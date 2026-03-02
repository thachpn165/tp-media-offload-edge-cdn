<?php
/**
 * Media Upload Hooks class.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Hooks;

defined( 'ABSPATH' ) || exit;

use ThachPN165\CFR2OffLoad\Constants\MetaKeys;
use ThachPN165\CFR2OffLoad\Constants\TransientKeys;
use ThachPN165\CFR2OffLoad\Interfaces\HookableInterface;
use ThachPN165\CFR2OffLoad\Services\BulkOperationLogger;
use ThachPN165\CFR2OffLoad\Services\OffloadService;
use ThachPN165\CFR2OffLoad\Services\R2Client;
use ThachPN165\CFR2OffLoad\Services\QueueProcessor;
use ThachPN165\CFR2OffLoad\Traits\CredentialsHelperTrait;

/**
 * MediaUploadHooks class - handles media upload and deletion hooks.
 */
class MediaUploadHooks implements HookableInterface {

	use CredentialsHelperTrait;

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		// Only register auto-offload hook if enabled (avoid overhead when disabled).
		$settings = get_option( 'cloudflare_r2_offload_cdn_settings', array() );
		if ( ! empty( $settings['auto_offload'] ) ) {
			// Use wp_generate_attachment_metadata filter instead of add_attachment action
			// This ensures thumbnails are already generated before offloading.
			add_filter( 'wp_generate_attachment_metadata', array( $this, 'on_attachment_metadata_generated' ), 20, 2 );
		}

		// Use priority 5 to run before WordPress deletes metadata (default priority 10).
		add_action( 'delete_attachment', array( $this, 'on_attachment_deleted' ), 5 );
		add_action( 'cfr2_process_queue', array( QueueProcessor::class, 'process' ) );
	}

	/**
	 * Handle attachment metadata generated (after thumbnails created).
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array Unmodified metadata.
	 */
	public function on_attachment_metadata_generated( array $metadata, int $attachment_id ): array {
		$this->process_auto_offload( $attachment_id );
		return $metadata;
	}

	/**
	 * Process auto-offload for attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function process_auto_offload( int $attachment_id ): void {
		// Get settings (auto_offload already checked in register_hooks).
		$settings = get_option( 'cloudflare_r2_offload_cdn_settings', array() );

		// Validate R2 configured.
		if ( empty( $settings['r2_account_id'] ) || empty( $settings['r2_bucket'] ) ) {
			BulkOperationLogger::log( $attachment_id, 'error', 'R2 not configured' );
			return;
		}

		$credentials = self::get_r2_credentials( $settings );

		// Check if secret key is available.
		if ( empty( $credentials['secret_access_key'] ) ) {
			BulkOperationLogger::log( $attachment_id, 'error', 'R2 secret key not configured' );
			return;
		}

		$r2      = new R2Client( $credentials );
		$offload = new OffloadService( $r2 );

		// Offload immediately.
		$result = $offload->offload( $attachment_id );

		// Log the result.
		if ( $result['success'] ) {
			$thumb_info = '';
			if ( ! empty( $result['thumbnails']['total'] ) ) {
				$thumb_info = sprintf(
					' (+%d/%d thumbnails)',
					$result['thumbnails']['success'],
					$result['thumbnails']['total']
				);
			}
			BulkOperationLogger::log( $attachment_id, 'success', 'Offloaded to R2' . $thumb_info );
		} else {
			BulkOperationLogger::log( $attachment_id, 'error', $result['message'] ?? 'Unknown error' );
		}
	}

	/**
	 * Handle attachment deleted.
	 * If sync_delete is enabled, delete from R2. Otherwise, just clean up local DB.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function on_attachment_deleted( int $attachment_id ): void {
		$r2_key = get_post_meta( $attachment_id, MetaKeys::R2_KEY, true );
		if ( ! $r2_key ) {
			return; // Not offloaded, nothing to do.
		}

		$settings = get_option( 'cloudflare_r2_offload_cdn_settings', array() );

		// If sync_delete is enabled, delete from R2.
		if ( ! empty( $settings['sync_delete'] ) ) {
			$this->delete_from_r2( $attachment_id, $r2_key );
		}

		// Always clean up local database entries.
		$this->cleanup_attachment_data( $attachment_id );
	}

	/**
	 * Delete attachment files from R2 (main file + thumbnails).
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $r2_key        Main file R2 key.
	 */
	private function delete_from_r2( int $attachment_id, string $r2_key ): void {
		$credentials = self::get_r2_credentials();
		if ( empty( $credentials['secret_access_key'] ) ) {
			BulkOperationLogger::log( $attachment_id, 'error', 'R2 credentials not configured for sync delete' );
			return;
		}

		$r2 = new R2Client( $credentials );
		$deleted_count = 0;

		// Delete main file.
		$result = $r2->delete_file( $r2_key );
		if ( $result['success'] ) {
			++$deleted_count;
		}

		// Delete thumbnails from R2.
		$thumbnails = get_post_meta( $attachment_id, MetaKeys::THUMBNAILS, true );
		if ( ! empty( $thumbnails ) && is_array( $thumbnails ) ) {
			foreach ( $thumbnails as $size => $thumb_data ) {
				// Thumbnails are stored as array with 'r2_key' and 'url'.
				$thumb_key = is_array( $thumb_data ) ? ( $thumb_data['r2_key'] ?? '' ) : $thumb_data;
				if ( ! empty( $thumb_key ) ) {
					$thumb_result = $r2->delete_file( $thumb_key );
					if ( $thumb_result['success'] ) {
						++$deleted_count;
					}
				}
			}
		}

		BulkOperationLogger::log(
			$attachment_id,
			'success',
			sprintf(
				/* translators: %d: number of files deleted from R2 */
				__( 'Deleted %d file(s) from R2', 'cf-r2-offload-cdn' ),
				$deleted_count
			)
		);
	}

	/**
	 * Clean up attachment data from database.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function cleanup_attachment_data( int $attachment_id ): void {
		global $wpdb;

		// Delete from offload_status table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom status table cleanup.
		$wpdb->delete(
			$wpdb->prefix . 'cfr2_offload_status',
			array( 'attachment_id' => $attachment_id ),
			array( '%d' )
		);

		// Delete from queue table (any pending operations).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom queue table cleanup.
		$wpdb->delete(
			$wpdb->prefix . 'cfr2_offload_queue',
			array( 'attachment_id' => $attachment_id ),
			array( '%d' )
		);

		// Clear dashboard stats cache.
		delete_transient( TransientKeys::DASHBOARD_STATS );
	}
}
