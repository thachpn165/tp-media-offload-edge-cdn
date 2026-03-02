<?php
/**
 * Media Library Extension class.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Admin;

defined( 'ABSPATH' ) || exit;

use ThachPN165\CFR2OffLoad\Constants\TransientKeys;
use ThachPN165\CFR2OffLoad\Constants\CacheDuration;
use ThachPN165\CFR2OffLoad\Interfaces\HookableInterface;
use ThachPN165\CFR2OffLoad\Services\OffloadService;
use ThachPN165\CFR2OffLoad\Services\R2Client;
use ThachPN165\CFR2OffLoad\Traits\CredentialsHelperTrait;

/**
 * MediaLibraryExtension class - extends Media Library with R2 functionality.
 */
class MediaLibraryExtension implements HookableInterface {

	use CredentialsHelperTrait;

	/**
	 * Static cache for pending status checks (batch prefetched).
	 *
	 * @var array<int, bool>
	 */
	private static array $pending_cache = array();

	/**
	 * Flag to track if pending status has been prefetched.
	 *
	 * @var bool
	 */
	private static bool $pending_prefetched = false;

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		// Prefetch pending status for batch performance.
		add_action( 'pre_get_posts', array( $this, 'prefetch_pending_on_media_query' ) );

		// Add column.
		add_filter( 'manage_media_columns', array( $this, 'add_status_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_status_column' ), 10, 2 );

		// Add row actions.
		add_filter( 'media_row_actions', array( $this, 'add_row_actions' ), 10, 2 );

		// Add bulk actions.
		add_filter( 'bulk_actions-upload', array( $this, 'add_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_actions' ), 10, 3 );

		// Admin notices.
		add_action( 'admin_notices', array( $this, 'show_bulk_action_notices' ) );

		// AJAX handlers for row actions.
		add_action( 'wp_ajax_cfr2_offload_single', array( $this, 'ajax_offload_single' ) );
		add_action( 'wp_ajax_cfr2_restore_single', array( $this, 'ajax_restore_single' ) );
		add_action( 'wp_ajax_cfr2_delete_local_single', array( $this, 'ajax_delete_local_single' ) );

		// Attachment details page.
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_attachment_fields' ), 10, 2 );
		add_action( 'wp_ajax_cfr2_offload_attachment', array( $this, 'ajax_offload_attachment' ) );
	}

	/**
	 * Prefetch pending status for media library query to optimize batch queries.
	 *
	 * @param \WP_Query $query The WP_Query instance.
	 */
	public function prefetch_pending_on_media_query( \WP_Query $query ): void {
		if ( ! is_admin() || self::$pending_prefetched ) {
			return;
		}

		// Only run on media library list screens.
		if ( 'attachment' !== $query->get( 'post_type' ) ) {
			return;
		}

		// Will be populated after query runs - hook into the_posts.
		add_filter( 'the_posts', array( $this, 'prefetch_pending_status_batch' ), 10, 2 );
	}

	/**
	 * Batch prefetch pending status for all attachments in the query.
	 *
	 * @param array     $posts Array of post objects.
	 * @param \WP_Query $query The WP_Query instance.
	 * @return array Unmodified posts array.
	 */
	public function prefetch_pending_status_batch( array $posts, \WP_Query $query ): array {
		if ( self::$pending_prefetched || empty( $posts ) ) {
			return $posts;
		}

		// Only process attachment queries.
		if ( 'attachment' !== $query->get( 'post_type' ) ) {
			return $posts;
		}

		$attachment_ids = wp_list_pluck( $posts, 'ID' );
		self::prefetch_pending_status( $attachment_ids );
		self::$pending_prefetched = true;

		return $posts;
	}

	/**
	 * Prefetch pending status for multiple attachment IDs in a single query.
	 *
	 * @param array $attachment_ids Array of attachment IDs.
	 */
	public static function prefetch_pending_status( array $attachment_ids ): void {
		if ( empty( $attachment_ids ) ) {
			return;
		}

		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $attachment_ids ) ) );
		if ( empty( $ids ) ) {
			return;
		}

		$ids_sql = implode( ',', $ids );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are sanitized with absint.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pending_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT attachment_id FROM {$wpdb->prefix}cfr2_offload_queue
				 WHERE attachment_id IN ({$ids_sql})
				 AND status IN (%s, %s)",
				'pending',
				'processing'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Initialize all as not pending.
		foreach ( $attachment_ids as $id ) {
			self::$pending_cache[ (int) $id ] = false;
		}

		// Mark pending ones.
		foreach ( $pending_ids as $id ) {
			self::$pending_cache[ (int) $id ] = true;
		}
	}

	/**
	 * Get safe redirect URL - validates referer is within admin.
	 *
	 * @return string Safe redirect URL.
	 */
	private function get_safe_redirect_url(): string {
		$redirect_url = wp_get_referer();

		// Validate redirect URL is within admin area.
		if ( ! $redirect_url || ! str_starts_with( $redirect_url, admin_url() ) ) {
			$redirect_url = admin_url( 'upload.php' );
		}

		return $redirect_url;
	}

	/**
	 * Add R2 Status column to Media Library.
	 *
	 * @param array $columns Columns array.
	 * @return array Modified columns array.
	 */
	public function add_status_column( array $columns ): array {
		$columns['cfr2_status'] = __( 'R2 Status', 'thachpham-offload-cdn-cloudflare-r2' );
		return $columns;
	}

	/**
	 * Render R2 Status column content.
	 *
	 * @param string $column_name Column name.
	 * @param int    $post_id     Post ID.
	 */
	public function render_status_column( string $column_name, int $post_id ): void {
		if ( 'cfr2_status' !== $column_name ) {
			return;
		}

		$is_offloaded  = get_post_meta( $post_id, '_cfr2_offloaded', true );
		$is_pending    = $this->is_pending( $post_id );
		$file_path     = get_attached_file( $post_id );
		$local_exists  = $file_path && file_exists( $file_path );

		if ( $is_offloaded && $local_exists ) {
			echo '<span class="cfr2-status cfr2-both">' . esc_html__( 'Local / R2', 'thachpham-offload-cdn-cloudflare-r2' ) . '</span>';
		} elseif ( $is_offloaded ) {
			echo '<span class="cfr2-status cfr2-offloaded">' . esc_html__( 'R2', 'thachpham-offload-cdn-cloudflare-r2' ) . '</span>';
		} elseif ( $is_pending ) {
			echo '<span class="cfr2-status cfr2-pending">' . esc_html__( 'Pending', 'thachpham-offload-cdn-cloudflare-r2' ) . '</span>';
		} else {
			echo '<span class="cfr2-status cfr2-local">' . esc_html__( 'Local', 'thachpham-offload-cdn-cloudflare-r2' ) . '</span>';
		}
	}

	/**
	 * Add row actions to Media Library.
	 *
	 * @param array    $actions Row actions array.
	 * @param \WP_Post $post    Post object.
	 * @return array Modified row actions array.
	 */
	public function add_row_actions( array $actions, \WP_Post $post ): array {
		if ( 'attachment' !== $post->post_type ) {
			return $actions;
		}

		$is_offloaded = get_post_meta( $post->ID, '_cfr2_offloaded', true );
		$nonce        = wp_create_nonce( 'cfr2_media_action_' . $post->ID );
		$file_path    = get_attached_file( $post->ID );
		$local_exists = $file_path && file_exists( $file_path );

		if ( $is_offloaded ) {
			// Offloaded to R2.
			if ( $local_exists ) {
				// Local + R2: Show Delete Local to free space.
				$actions['cfr2_delete_local'] = sprintf(
					'<a href="%s" class="cfr2-delete-local" style="color: #d63638;" onclick="return confirm(\'%s\');">%s</a>',
					esc_url( admin_url( "admin-ajax.php?action=cfr2_delete_local_single&id={$post->ID}&nonce={$nonce}" ) ),
					esc_js( __( 'Delete local files? This cannot be undone. Files will remain on R2.', 'thachpham-offload-cdn-cloudflare-r2' ) ),
					esc_html__( 'Delete Local', 'thachpham-offload-cdn-cloudflare-r2' )
				);
			} else {
				// R2 only: Show Restore to download from R2.
				$actions['cfr2_restore'] = sprintf(
					'<a href="%s" class="cfr2-restore">%s</a>',
					esc_url( admin_url( "admin-ajax.php?action=cfr2_restore_single&id={$post->ID}&nonce={$nonce}" ) ),
					esc_html__( 'Download to Local', 'thachpham-offload-cdn-cloudflare-r2' )
				);
			}

			// Always show Re-offload option for offloaded files.
			$actions['cfr2_reoffload'] = sprintf(
				'<a href="%s" class="cfr2-reoffload">%s</a>',
				esc_url( admin_url( "admin-ajax.php?action=cfr2_offload_single&id={$post->ID}&nonce={$nonce}&force=1" ) ),
				esc_html__( 'Re-offload', 'thachpham-offload-cdn-cloudflare-r2' )
			);
		} else {
			// Not offloaded: Show Offload action.
			$actions['cfr2_offload'] = sprintf(
				'<a href="%s" class="cfr2-offload">%s</a>',
				esc_url( admin_url( "admin-ajax.php?action=cfr2_offload_single&id={$post->ID}&nonce={$nonce}" ) ),
				esc_html__( 'Offload to R2', 'thachpham-offload-cdn-cloudflare-r2' )
			);
		}

		return $actions;
	}

	/**
	 * Add bulk actions to Media Library.
	 *
	 * @param array $actions Bulk actions array.
	 * @return array Modified bulk actions array.
	 */
	public function add_bulk_actions( array $actions ): array {
		$actions['cfr2_bulk_offload'] = __( 'Offload to R2', 'thachpham-offload-cdn-cloudflare-r2' );
		$actions['cfr2_bulk_restore'] = __( 'Restore to Local', 'thachpham-offload-cdn-cloudflare-r2' );
		return $actions;
	}

	/**
	 * Handle bulk actions.
	 *
	 * @param string $redirect_url Redirect URL.
	 * @param string $action       Action name.
	 * @param array  $post_ids     Post IDs array.
	 * @return string Modified redirect URL.
	 */
	public function handle_bulk_actions( string $redirect_url, string $action, array $post_ids ): string {
		if ( ! in_array( $action, array( 'cfr2_bulk_offload', 'cfr2_bulk_restore' ), true ) ) {
			return $redirect_url;
		}

		$count = 0;
		foreach ( $post_ids as $attachment_id ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table for queue.
			$wpdb->insert(
				$wpdb->prefix . 'cfr2_offload_queue',
				array(
					'attachment_id' => $attachment_id,
					'action'        => 'cfr2_bulk_offload' === $action ? 'offload' : 'restore',
					'status'        => 'pending',
					'created_at'    => current_time( 'mysql' ),
				),
				array( '%d', '%s', '%s', '%s' )
			);
			++$count;
		}

		// Schedule queue processing.
		if ( ! \as_next_scheduled_action( 'cfr2_process_queue' ) ) {
			\as_schedule_single_action( time(), 'cfr2_process_queue' );
		}

		return add_query_arg( 'cfr2_queued', $count, $redirect_url );
	}

	/**
	 * Show bulk action notices.
	 */
	public function show_bulk_action_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended

		// Show error from transient (secure - no URL param exposure).
		if ( isset( $_GET['cfr2_error'] ) ) {
			$user_id       = get_current_user_id();
			$transient_key = TransientKeys::ERROR_PREFIX . $user_id;
			$error_message = get_transient( $transient_key );

			if ( $error_message ) {
				delete_transient( $transient_key );
				printf(
					'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
					esc_html( $error_message )
				);
			} else {
				// Fallback generic message if transient expired.
				printf(
					'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
					esc_html__( 'An error occurred during the operation.', 'thachpham-offload-cdn-cloudflare-r2' )
				);
			}
		}

		// Show success notices.
		if ( isset( $_GET['cfr2_offloaded'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'File offloaded to R2 successfully.', 'thachpham-offload-cdn-cloudflare-r2' )
			);
		}

		if ( isset( $_GET['cfr2_restored'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'File downloaded to local storage. Website continues serving from R2.', 'thachpham-offload-cdn-cloudflare-r2' )
			);
		}

		if ( isset( $_GET['cfr2_local_deleted'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Local files deleted successfully. Files remain on R2.', 'thachpham-offload-cdn-cloudflare-r2' )
			);
		}

		if ( ! isset( $_GET['cfr2_queued'] ) ) {
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			return;
		}

		$count = absint( $_GET['cfr2_queued'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			sprintf(
				/* translators: %d: number of files */
				esc_html( _n( '%d file queued for processing.', '%d files queued for processing.', $count, 'thachpham-offload-cdn-cloudflare-r2' ) ),
				(int) $count
			)
		);
	}

	/**
	 * AJAX handler for single offload.
	 */
	public function ajax_offload_single(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$id    = absint( $_GET['id'] ?? 0 );
		$nonce = sanitize_text_field( wp_unslash( $_GET['nonce'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! wp_verify_nonce( $nonce, 'cfr2_media_action_' . $id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'thachpham-offload-cdn-cloudflare-r2' ) );
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'thachpham-offload-cdn-cloudflare-r2' ) );
		}

		$credentials = self::get_r2_credentials();
		$r2          = new R2Client( $credentials );
		$offload     = new OffloadService( $r2 );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['force'] ) ) {
			delete_post_meta( $id, '_cfr2_offloaded' );
		}

		$result       = $offload->offload( $id );
		$redirect_url = $this->get_safe_redirect_url();

		if ( $result['success'] ) {
			wp_safe_redirect( add_query_arg( 'cfr2_offloaded', 1, $redirect_url ) );
		} else {
			// Store error in transient instead of exposing in URL.
			set_transient( TransientKeys::ERROR_PREFIX . get_current_user_id(), $result['message'], CacheDuration::ERROR_TTL );
			wp_safe_redirect( add_query_arg( 'cfr2_error', 1, $redirect_url ) );
		}
		exit;
	}

	/**
	 * AJAX handler for single restore.
	 */
	public function ajax_restore_single(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$id    = absint( $_GET['id'] ?? 0 );
		$nonce = sanitize_text_field( wp_unslash( $_GET['nonce'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! wp_verify_nonce( $nonce, 'cfr2_media_action_' . $id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'thachpham-offload-cdn-cloudflare-r2' ) );
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'thachpham-offload-cdn-cloudflare-r2' ) );
		}

		$credentials  = self::get_r2_credentials();
		$r2           = new R2Client( $credentials );
		$offload      = new OffloadService( $r2 );
		$redirect_url = $this->get_safe_redirect_url();

		$offload->restore( $id );

		wp_safe_redirect( add_query_arg( 'cfr2_restored', 1, $redirect_url ) );
		exit;
	}

	/**
	 * AJAX handler for single delete local files (disk saving).
	 */
	public function ajax_delete_local_single(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$id    = absint( $_GET['id'] ?? 0 );
		$nonce = sanitize_text_field( wp_unslash( $_GET['nonce'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! wp_verify_nonce( $nonce, 'cfr2_media_action_' . $id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'thachpham-offload-cdn-cloudflare-r2' ) );
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'thachpham-offload-cdn-cloudflare-r2' ) );
		}

		$credentials  = self::get_r2_credentials();
		$r2           = new R2Client( $credentials );
		$offload      = new OffloadService( $r2 );
		$redirect_url = $this->get_safe_redirect_url();

		$result = $offload->delete_local_files( $id );

		if ( $result['success'] ) {
			wp_safe_redirect( add_query_arg( 'cfr2_local_deleted', 1, $redirect_url ) );
		} else {
			set_transient( TransientKeys::ERROR_PREFIX . get_current_user_id(), $result['message'], CacheDuration::ERROR_TTL );
			wp_safe_redirect( add_query_arg( 'cfr2_error', 1, $redirect_url ) );
		}
		exit;
	}

	/**
	 * Add R2 status field to attachment details page.
	 *
	 * @param array    $form_fields Form fields array.
	 * @param \WP_Post $post        Attachment post object.
	 * @return array Modified form fields array.
	 */
	public function add_attachment_fields( array $form_fields, \WP_Post $post ): array {
		$is_offloaded = get_post_meta( $post->ID, '_cfr2_offloaded', true );
		$r2_url       = get_post_meta( $post->ID, '_cfr2_r2_url', true );
		$thumbnails   = get_post_meta( $post->ID, '_cfr2_thumbnails', true );
		$is_pending   = $this->is_pending( $post->ID );
		$file_path    = get_attached_file( $post->ID );
		$local_exists = $file_path && file_exists( $file_path );

		// Build status HTML.
		if ( $is_offloaded && $local_exists ) {
			// Both local and R2.
			$thumb_count = is_array( $thumbnails ) ? count( $thumbnails ) : 0;

			$status_html = '<span style="color: #2271b1; font-weight: bold;">';
			$status_html .= '<span class="dashicons dashicons-admin-site" style="vertical-align: middle;"></span>';
			$status_html .= '<span class="dashicons dashicons-cloud" style="vertical-align: middle;"></span> ';
			$status_html .= esc_html__( 'Local / R2', 'thachpham-offload-cdn-cloudflare-r2' );
			if ( $thumb_count > 0 ) {
				$status_html .= sprintf( ' (+%d %s)', $thumb_count, _n( 'thumbnail', 'thumbnails', $thumb_count, 'thachpham-offload-cdn-cloudflare-r2' ) );
			}
			$status_html .= '</span>';
			if ( $r2_url ) {
				$status_html .= '<br><small style="color: #666;">' . esc_html( $r2_url ) . '</small>';
			}
		} elseif ( $is_offloaded ) {
			// R2 only (no local file).
			$thumb_count = is_array( $thumbnails ) ? count( $thumbnails ) : 0;

			$status_html = '<span style="color: #46b450; font-weight: bold;">';
			$status_html .= '<span class="dashicons dashicons-cloud" style="vertical-align: middle;"></span> ';
			$status_html .= esc_html__( 'R2', 'thachpham-offload-cdn-cloudflare-r2' );
			if ( $thumb_count > 0 ) {
				$status_html .= sprintf( ' (+%d %s)', $thumb_count, _n( 'thumbnail', 'thumbnails', $thumb_count, 'thachpham-offload-cdn-cloudflare-r2' ) );
			}
			$status_html .= '</span>';
			$status_html .= '<br><small style="color: #999;">' . esc_html__( 'No local file', 'thachpham-offload-cdn-cloudflare-r2' ) . '</small>';
			if ( $r2_url ) {
				$status_html .= '<br><small style="color: #666;">' . esc_html( $r2_url ) . '</small>';
			}
		} elseif ( $is_pending ) {
			$status_html = '<span style="color: #f0ad4e; font-weight: bold;">';
			$status_html .= '<span class="dashicons dashicons-clock" style="vertical-align: middle;"></span> ';
			$status_html .= esc_html__( 'Queued for offload', 'thachpham-offload-cdn-cloudflare-r2' );
			$status_html .= '</span>';
		} else {
			// Local only.
			$status_html = '<span style="color: #999;">';
			$status_html .= '<span class="dashicons dashicons-admin-site" style="vertical-align: middle;"></span> ';
			$status_html .= esc_html__( 'Local', 'thachpham-offload-cdn-cloudflare-r2' );
			$status_html .= '</span>';

			// Add offload button.
			$nonce        = wp_create_nonce( 'cfr2_offload_attachment_' . $post->ID );
			$status_html .= '<br><br>';
			$status_html .= sprintf(
				'<button type="button" class="button cfr2-offload-btn" data-id="%d" data-nonce="%s">%s</button>',
				$post->ID,
				$nonce,
				esc_html__( 'Offload to R2', 'thachpham-offload-cdn-cloudflare-r2' )
			);
			$status_html .= '<span class="cfr2-offload-status" style="margin-left: 10px;"></span>';
		}

		$form_fields['cfr2_status'] = array(
			'label' => __( 'R2 Status', 'thachpham-offload-cdn-cloudflare-r2' ),
			'input' => 'html',
			'html'  => $status_html,
		);

		return $form_fields;
	}

	/**
	 * AJAX handler for offloading from attachment details.
	 */
	public function ajax_offload_attachment(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$id    = absint( $_POST['attachment_id'] ?? 0 );
		$nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! wp_verify_nonce( $nonce, 'cfr2_offload_attachment_' . $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'thachpham-offload-cdn-cloudflare-r2' ) ) );
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'thachpham-offload-cdn-cloudflare-r2' ) ) );
		}

		$credentials = self::get_r2_credentials();
		$r2          = new R2Client( $credentials );
		$offload     = new OffloadService( $r2 );
		$result      = $offload->offload( $id );

		if ( $result['success'] ) {
			wp_send_json_success(
				array(
					'message' => __( 'Offloaded successfully!', 'thachpham-offload-cdn-cloudflare-r2' ),
					'url'     => $result['url'] ?? '',
				)
			);
		} else {
			wp_send_json_error( array( 'message' => $result['message'] ?? __( 'Offload failed.', 'thachpham-offload-cdn-cloudflare-r2' ) ) );
		}
	}

	/**
	 * Check if attachment is pending in queue.
	 * Uses batch prefetched cache when available for performance.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True if pending, false otherwise.
	 */
	private function is_pending( int $attachment_id ): bool {
		// Check static cache first (populated by batch prefetch).
		if ( isset( self::$pending_cache[ $attachment_id ] ) ) {
			return self::$pending_cache[ $attachment_id ];
		}

		// Fallback to single query if not prefetched.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Queue status should not be cached.
		$is_pending = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}cfr2_offload_queue
				 WHERE attachment_id = %d AND status IN ('pending', 'processing')",
				$attachment_id
			)
		);

		// Cache the result.
		self::$pending_cache[ $attachment_id ] = $is_pending;

		return $is_pending;
	}
}
