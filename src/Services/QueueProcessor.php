<?php
/**
 * Queue Processor class.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Services;

defined( 'ABSPATH' ) || exit;

use ThachPN165\CFR2OffLoad\Traits\CredentialsHelperTrait;

/**
 * QueueProcessor class - processes background offload queue.
 */
class QueueProcessor {

	use CredentialsHelperTrait;

	/**
	 * Default batch size.
	 */
	private const BATCH_SIZE = 25;

	/**
	 * Maximum batch size.
	 */
	private const MAX_BATCH_SIZE = 50;

	/**
	 * Process queue items.
	 */
	public static function process(): void {
		global $wpdb;

		// Get batch size from settings.
		$settings   = get_option( 'cloudflare_r2_offload_cdn_settings', array() );
		$batch_size = min( (int) ( $settings['batch_size'] ?? self::BATCH_SIZE ), self::MAX_BATCH_SIZE );

		// Check if cancelled.
		if ( get_transient( 'cfr2_bulk_cancelled' ) ) {
			delete_transient( 'cfr2_bulk_cancelled' );
			return;
		}

		// Fetch pending items.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Queue processing requires fresh data.
		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cfr2_offload_queue
				 WHERE status = 'pending'
				 ORDER BY created_at ASC
				 LIMIT %d",
				$batch_size
			)
		);

		if ( empty( $items ) ) {
			return;
		}

		$credentials = self::get_r2_credentials();
		if ( empty( $credentials['account_id'] ) ) {
			return;
		}

		$r2      = new R2Client( $credentials );
		$offload = new OffloadService( $r2 );

		foreach ( $items as $item ) {
			// Mark as processing.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom queue table.
			$wpdb->update(
				$wpdb->prefix . 'cfr2_offload_queue',
				array( 'status' => 'processing' ),
				array( 'id' => $item->id ),
				array( '%s' ),
				array( '%d' )
			);

			// Process based on action.
			$result = match ( $item->action ) {
				'offload'      => $offload->offload( $item->attachment_id ),
				'restore'      => $offload->restore( $item->attachment_id ),
				'delete_local' => $offload->delete_local_files( $item->attachment_id ),
				default        => array(
					'success' => false,
					'message' => __( 'Unknown action', 'cf-r2-offload-cdn' ),
				),
			};

			// Update queue status.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom queue table.
			$wpdb->update(
				$wpdb->prefix . 'cfr2_offload_queue',
				array(
					'status'        => $result['success'] ? 'completed' : 'failed',
					'error_message' => $result['message'] ?? null,
					'processed_at'  => current_time( 'mysql' ),
				),
				array( 'id' => $item->id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);

			// Log operation.
			BulkOperationLogger::log(
				$item->attachment_id,
				$result['success'] ? 'success' : 'error',
				$result['message'] ?? ( $result['success'] ? __( 'Offloaded successfully', 'cf-r2-offload-cdn' ) : __( 'Operation failed', 'cf-r2-offload-cdn' ) )
			);

			// Check for cancellation between items.
			if ( get_transient( 'cfr2_bulk_cancelled' ) ) {
				break;
			}
		}

		// Schedule next batch if more pending.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Queue count for scheduling.
		$remaining = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}cfr2_offload_queue WHERE status = 'pending'"
		);

		if ( $remaining > 0 && ! get_transient( 'cfr2_bulk_cancelled' ) ) {
			\as_schedule_single_action( time() + 5, 'cfr2_process_queue' );
		}
	}

	/**
	 * Delete file from R2.
	 *
	 * @param R2Client $r2            R2Client instance.
	 * @param int      $attachment_id Attachment ID.
	 * @return array Result array with success/message.
	 */
	private static function delete_from_r2( R2Client $r2, int $attachment_id ): array {
		$r2_key = get_post_meta( $attachment_id, '_cfr2_r2_key', true );
		if ( ! $r2_key ) {
			return array(
				'success' => false,
				'message' => __( 'No R2 key found', 'cf-r2-offload-cdn' ),
			);
		}
		return $r2->delete_file( $r2_key );
	}
}
