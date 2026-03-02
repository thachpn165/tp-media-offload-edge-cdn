<?php
/**
 * Offload Service class.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Services;

defined( 'ABSPATH' ) || exit;

use ThachPN165\CFR2OffLoad\Constants\MetaKeys;
use ThachPN165\CFR2OffLoad\Constants\Settings;
use ThachPN165\CFR2OffLoad\Constants\TransientKeys;
use ThachPN165\CFR2OffLoad\Hooks\ExtensibilityHooks;

/**
 * OffloadService class - coordinates offload operations.
 */
class OffloadService {

	/**
	 * R2Client instance.
	 *
	 * @var R2Client
	 */
	private R2Client $r2;

	/**
	 * Constructor.
	 *
	 * @param R2Client $r2 R2Client instance.
	 */
	public function __construct( R2Client $r2 ) {
		$this->r2 = $r2;
	}

	/**
	 * Queue attachment for offload.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True if queued, false otherwise.
	 */
	public function queue_offload( int $attachment_id ): bool {
		if ( $this->is_offloaded( $attachment_id ) ) {
			return false;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom queue table.
		$wpdb->insert(
			$wpdb->prefix . 'cfr2_offload_queue',
			array(
				'attachment_id' => $attachment_id,
				'action'        => 'offload',
				'status'        => 'pending',
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);

		// Schedule queue processor if not already scheduled.
		if ( ! wp_next_scheduled( 'cfr2_process_queue' ) ) {
			wp_schedule_single_event( time(), 'cfr2_process_queue' );
		}

		return true;
	}

	/**
	 * Perform actual offload.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array Result array with success/message.
	 */
	public function offload( int $attachment_id ): array {
		// Check MIME type first for better error message.
		$mime          = get_post_mime_type( $attachment_id );
		$allowed_types = ExtensibilityHooks::get_allowed_mime_types();

		if ( ! in_array( $mime, $allowed_types, true ) ) {
			return array(
				'success' => false,
				/* translators: %s: MIME type */
				'message' => sprintf( __( 'MIME type "%s" is not allowed for offload', 'cf-r2-offload-cdn' ), $mime ?: 'unknown' ),
			);
		}

		// Check if should offload (with filter for third-party).
		if ( ! ExtensibilityHooks::should_offload( $attachment_id ) ) {
			return array(
				'success' => false,
				'message' => __( 'Offload blocked by filter (cfr2_should_offload)', 'cf-r2-offload-cdn' ),
			);
		}

		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return array(
				'success' => false,
				'message' => __( 'File not found', 'cf-r2-offload-cdn' ),
			);
		}

		// Fire before offload hook.
		ExtensibilityHooks::before_offload( $attachment_id );

		// Generate R2 key (with filter).
		$r2_key = ExtensibilityHooks::get_r2_key( $attachment_id, $file_path );

		// Store local URL before offload.
		$local_url = wp_get_attachment_url( $attachment_id );
		update_post_meta( $attachment_id, MetaKeys::LOCAL_URL, $local_url );

		// Upload to R2.
		$result = $this->r2->upload_file( $file_path, $r2_key );

		if ( $result['success'] ) {
			// Update meta.
			update_post_meta( $attachment_id, MetaKeys::OFFLOADED, true );
			update_post_meta( $attachment_id, MetaKeys::R2_URL, $result['url'] );
			update_post_meta( $attachment_id, MetaKeys::R2_KEY, $r2_key );

			// Update database table.
			$this->update_offload_status( $attachment_id, $r2_key, $result['url'], $file_path );

			// Also offload thumbnail sizes.
			$thumb_results = $this->offload_thumbnails( $attachment_id );

			// Build success message with thumbnail info.
			$thumb_info = '';
			if ( $thumb_results['total'] > 0 ) {
				$thumb_info = sprintf(
					' (+%d/%d thumbnails)',
					$thumb_results['success'],
					$thumb_results['total']
				);
			}

			// Delete local files if keep_local_files is disabled.
			$deleted_local = $this->maybe_delete_local_files( $attachment_id, $file_path, $thumb_results );

			$success_result = array(
				'success'       => true,
				'url'           => $result['url'],
				'thumbnails'    => $thumb_results,
				'local_deleted' => $deleted_local,
				'message'       => __( 'Offloaded successfully', 'cf-r2-offload-cdn' ) . $thumb_info,
			);

			// Clear dashboard stats cache so stats update immediately.
			delete_transient( TransientKeys::DASHBOARD_STATS );

			// Fire after offload hook.
			ExtensibilityHooks::after_offload( $attachment_id, $success_result );

			return $success_result;
		}

		// Fire after offload hook even on failure.
		ExtensibilityHooks::after_offload( $attachment_id, $result );

		return $result;
	}

	/**
	 * Offload all thumbnail sizes.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param array|null $metadata      Optional pre-fetched metadata.
	 * @return array Results with success count, failed sizes, and metadata.
	 */
	private function offload_thumbnails( int $attachment_id, ?array $metadata = null ): array {
		if ( null === $metadata ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
		}

		$results = array(
			'total'    => 0,
			'success'  => 0,
			'failed'   => array(),
			'uploaded' => array(),
			'metadata' => $metadata, // Pass metadata for reuse.
		);

		if ( empty( $metadata['sizes'] ) ) {
			return $results;
		}

		$file_path  = get_attached_file( $attachment_id );
		$base_dir   = dirname( $file_path );
		$upload_dir = wp_upload_dir();

		$results['total'] = count( $metadata['sizes'] );

		foreach ( $metadata['sizes'] as $size => $data ) {
			$thumb_path = $base_dir . '/' . $data['file'];

			if ( ! file_exists( $thumb_path ) ) {
				$results['failed'][] = array(
					'size'    => $size,
					'message' => 'File not found: ' . $data['file'],
				);
				continue;
			}

			$r2_key = str_replace( $upload_dir['basedir'] . '/', '', $thumb_path );
			$result = $this->r2->upload_file( $thumb_path, $r2_key );

			if ( $result['success'] ) {
				++$results['success'];
				$results['uploaded'][ $size ] = array(
					'r2_key' => $r2_key,
					'url'    => $result['url'] ?? '',
				);
			} else {
				$results['failed'][] = array(
					'size'    => $size,
					'message' => $result['message'] ?? 'Unknown error',
				);
			}
		}

		// Store thumbnail R2 keys in meta for later reference.
		if ( ! empty( $results['uploaded'] ) ) {
			update_post_meta( $attachment_id, MetaKeys::THUMBNAILS, $results['uploaded'] );
		}

		return $results;
	}

	/**
	 * Delete local files if keep_local_files setting is disabled.
	 * Uses metadata from thumb_results to avoid re-fetching.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $file_path     Main file path.
	 * @param array  $thumb_results Thumbnail upload results (includes metadata).
	 * @return bool True if files were deleted, false otherwise.
	 */
	private function maybe_delete_local_files( int $attachment_id, string $file_path, array $thumb_results ): bool {
		$settings = get_option( Settings::OPTION_KEY, array() );

		// Default to keeping local files if setting not set.
		if ( ! empty( $settings['keep_local_files'] ) ) {
			return false;
		}

		$deleted = false;

		// Delete main file.
		if ( file_exists( $file_path ) ) {
			wp_delete_file( $file_path );
			$deleted = true;
		}

		// Delete thumbnails using metadata from thumb_results (avoids re-fetch).
		if ( ! empty( $thumb_results['uploaded'] ) ) {
			$metadata = $thumb_results['metadata'] ?? null;
			$base_dir = dirname( $file_path );

			if ( ! empty( $metadata['sizes'] ) ) {
				foreach ( $metadata['sizes'] as $size => $data ) {
					$thumb_path = $base_dir . '/' . $data['file'];
					if ( file_exists( $thumb_path ) ) {
						wp_delete_file( $thumb_path );
					}
				}
			}
		}

		// Update offload_status table to reflect local_exists = 0.
		if ( $deleted ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom status table.
			$wpdb->update(
				$wpdb->prefix . 'cfr2_offload_status',
				array( 'local_exists' => 0 ),
				array( 'attachment_id' => $attachment_id ),
				array( '%d' ),
				array( '%d' )
			);
		}

		return $deleted;
	}

	/**
	 * Queue attachment for restore.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True if queued, false otherwise.
	 */
	public function queue_restore( int $attachment_id ): bool {
		if ( ! $this->is_offloaded( $attachment_id ) ) {
			return false;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom queue table.
		$wpdb->insert(
			$wpdb->prefix . 'cfr2_offload_queue',
			array(
				'attachment_id' => $attachment_id,
				'action'        => 'restore',
				'status'        => 'pending',
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);

		if ( function_exists( 'as_next_scheduled_action' ) ) {
			if ( ! \as_next_scheduled_action( 'cfr2_process_queue' ) ) {
				\as_schedule_single_action( time(), 'cfr2_process_queue' );
			}
		} elseif ( ! wp_next_scheduled( 'cfr2_process_queue' ) ) {
				wp_schedule_single_event( time(), 'cfr2_process_queue' );
		}

		return true;
	}

	/**
	 * Restore from R2 to local (download files, keep R2 metadata).
	 * Files are downloaded to local storage but website continues serving from R2.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array Result array with success/message.
	 */
	public function restore( int $attachment_id ): array {
		// Verify attachment is offloaded.
		if ( ! $this->is_offloaded( $attachment_id ) ) {
			return array(
				'success' => false,
				'message' => __( 'Attachment is not offloaded to R2', 'cf-r2-offload-cdn' ),
			);
		}

		// Fire before restore hook.
		ExtensibilityHooks::before_restore( $attachment_id );

		$file_path = get_attached_file( $attachment_id );
		$r2_key    = get_post_meta( $attachment_id, MetaKeys::R2_KEY, true );

		if ( ! $file_path || ! $r2_key ) {
			return array(
				'success' => false,
				'message' => __( 'Missing file path or R2 key', 'cf-r2-offload-cdn' ),
			);
		}

		$downloaded_main   = false;
		$downloaded_thumbs = 0;

		// Download main file if not exists locally.
		if ( ! file_exists( $file_path ) ) {
			$result = $this->r2->download_file( $r2_key, $file_path );
			if ( ! $result['success'] ) {
				return array(
					'success' => false,
					'message' => sprintf(
						/* translators: %s: error message */
						__( 'Failed to download main file: %s', 'cf-r2-offload-cdn' ),
						$result['message'] ?? 'Unknown error'
					),
				);
			}
			$downloaded_main = true;
		}

		// Download thumbnails.
		$thumbnails = get_post_meta( $attachment_id, MetaKeys::THUMBNAILS, true );
		if ( ! empty( $thumbnails ) && is_array( $thumbnails ) ) {
			$base_dir   = dirname( $file_path );
			$upload_dir = wp_upload_dir();

			foreach ( $thumbnails as $size => $thumb_data ) {
				if ( empty( $thumb_data['r2_key'] ) ) {
					continue;
				}

				$thumb_path = $base_dir . '/' . basename( $thumb_data['r2_key'] );

				// Skip if already exists locally.
				if ( file_exists( $thumb_path ) ) {
					continue;
				}

				$result = $this->r2->download_file( $thumb_data['r2_key'], $thumb_path );
				if ( $result['success'] ) {
					++$downloaded_thumbs;
				}
			}
		}

		// Update local_exists flag in database.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom status table.
		$wpdb->update(
			$wpdb->prefix . 'cfr2_offload_status',
			array( 'local_exists' => 1 ),
			array( 'attachment_id' => $attachment_id ),
			array( '%d' ),
			array( '%d' )
		);

		// Clear dashboard stats cache so stats update immediately.
		delete_transient( TransientKeys::DASHBOARD_STATS );

		$message = $downloaded_main
			? sprintf(
				/* translators: %d: number of thumbnails downloaded */
				__( 'Files restored to local (+%d thumbnails)', 'cf-r2-offload-cdn' ),
				$downloaded_thumbs
			)
			: __( 'Files already exist locally', 'cf-r2-offload-cdn' );

		$result = array(
			'success'           => true,
			'downloaded_main'   => $downloaded_main,
			'downloaded_thumbs' => $downloaded_thumbs,
			'message'           => $message,
		);

		// Fire after restore hook.
		ExtensibilityHooks::after_restore( $attachment_id, $result );

		return $result;
	}

	/**
	 * Check if attachment is offloaded.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True if offloaded, false otherwise.
	 */
	public function is_offloaded( int $attachment_id ): bool {
		return (bool) get_post_meta( $attachment_id, MetaKeys::OFFLOADED, true );
	}

	/**
	 * Get R2 URL for attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|null R2 URL or null if not offloaded.
	 */
	public function get_r2_url( int $attachment_id ): ?string {
		$url = get_post_meta( $attachment_id, MetaKeys::R2_URL, true );
		return $url ? $url : null;
	}

	/**
	 * Get local URL for attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|null Local URL or null if not stored.
	 */
	public function get_local_url( int $attachment_id ): ?string {
		$url = get_post_meta( $attachment_id, MetaKeys::LOCAL_URL, true );
		return $url ? $url : null;
	}

	/**
	 * Delete local files for an offloaded attachment (disk saving).
	 * Only works for attachments that are already offloaded to R2.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array Result array with success/message.
	 */
	public function delete_local_files( int $attachment_id ): array {
		// Verify attachment is offloaded.
		if ( ! $this->is_offloaded( $attachment_id ) ) {
			return array(
				'success' => false,
				'message' => __( 'Attachment is not offloaded to R2', 'cf-r2-offload-cdn' ),
			);
		}

		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path ) {
			return array(
				'success' => false,
				'message' => __( 'Could not determine file path', 'cf-r2-offload-cdn' ),
			);
		}

		$deleted_main   = false;
		$deleted_thumbs = 0;

		// Delete main file.
		if ( file_exists( $file_path ) ) {
			wp_delete_file( $file_path );
			$deleted_main = true;
		}

		// Delete thumbnails.
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $metadata['sizes'] ) ) {
			$base_dir = dirname( $file_path );
			foreach ( $metadata['sizes'] as $size => $data ) {
				$thumb_path = $base_dir . '/' . $data['file'];
				if ( file_exists( $thumb_path ) ) {
					wp_delete_file( $thumb_path );
					++$deleted_thumbs;
				}
			}
		}

		// Update local_exists flag in database.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom status table.
		$wpdb->update(
			$wpdb->prefix . 'cfr2_offload_status',
			array( 'local_exists' => 0 ),
			array( 'attachment_id' => $attachment_id ),
			array( '%d' ),
			array( '%d' )
		);

		// Clear dashboard stats cache.
		delete_transient( TransientKeys::DASHBOARD_STATS );

		$message = $deleted_main
			? sprintf(
				/* translators: %d: number of thumbnails deleted */
				__( 'Local files deleted (+%d thumbnails)', 'cf-r2-offload-cdn' ),
				$deleted_thumbs
			)
			: __( 'No local files found to delete', 'cf-r2-offload-cdn' );

		return array(
			'success'        => true,
			'deleted_main'   => $deleted_main,
			'deleted_thumbs' => $deleted_thumbs,
			'message'        => $message,
		);
	}

	/**
	 * Update offload status in database.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $r2_key        R2 object key.
	 * @param string $r2_url        R2 URL.
	 * @param string $local_path    Local file path.
	 */
	private function update_offload_status( int $attachment_id, string $r2_key, string $r2_url, string $local_path ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom status table.
		$wpdb->replace(
			$wpdb->prefix . 'cfr2_offload_status',
			array(
				'attachment_id' => $attachment_id,
				'r2_key'        => $r2_key,
				'r2_url'        => $r2_url,
				'local_path'    => $local_path,
				'local_exists'  => file_exists( $local_path ) ? 1 : 0,
				'file_size'     => filesize( $local_path ),
				'offloaded_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%s' )
		);
	}
}
