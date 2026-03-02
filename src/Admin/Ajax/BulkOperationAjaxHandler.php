<?php
/**
 * Bulk Operation AJAX Handler class.
 *
 * Handles AJAX requests for bulk offload/restore operations.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

use ThachPN165\CFR2OffLoad\Constants\MetaKeys;
use ThachPN165\CFR2OffLoad\Constants\TransientKeys;
use ThachPN165\CFR2OffLoad\Constants\CacheDuration;
use ThachPN165\CFR2OffLoad\Constants\QueueStatus;
use ThachPN165\CFR2OffLoad\Constants\QueueAction;
use ThachPN165\CFR2OffLoad\Services\QueueManager;
use ThachPN165\CFR2OffLoad\Services\BulkItemProcessor;
use ThachPN165\CFR2OffLoad\Services\BulkProgressService;
use ThachPN165\CFR2OffLoad\Traits\AjaxSecurityTrait;

/**
 * BulkOperationAjaxHandler class - handles bulk operation AJAX requests.
 */
class BulkOperationAjaxHandler {

	use AjaxSecurityTrait;

	/**
	 * Batch size for querying non-offloaded attachments.
	 */
	private const OFFLOAD_QUERY_BATCH_SIZE = 500;

	/**
	 * QueueManager instance.
	 *
	 * @var QueueManager
	 */
	private QueueManager $queue_manager;

	/**
	 * BulkItemProcessor instance.
	 *
	 * @var BulkItemProcessor
	 */
	private BulkItemProcessor $processor;

	/**
	 * BulkProgressService instance.
	 *
	 * @var BulkProgressService
	 */
	private BulkProgressService $progress_service;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->queue_manager    = new QueueManager();
		$this->processor        = new BulkItemProcessor();
		$this->progress_service = new BulkProgressService();
	}

	/**
	 * Register AJAX hooks.
	 */
	public function register_hooks(): void {
		add_action( 'wp_ajax_cfr2_bulk_offload_all', array( $this, 'ajax_bulk_offload_all' ) );
		add_action( 'wp_ajax_cfr2_bulk_restore_all', array( $this, 'ajax_bulk_restore_all' ) );
		add_action( 'wp_ajax_cfr2_bulk_delete_local', array( $this, 'ajax_bulk_delete_local' ) );
		add_action( 'wp_ajax_cfr2_process_bulk_item', array( $this, 'ajax_process_bulk_item' ) );
		add_action( 'wp_ajax_cfr2_process_restore_item', array( $this, 'ajax_process_restore_item' ) );
		add_action( 'wp_ajax_cfr2_process_delete_local_item', array( $this, 'ajax_process_delete_local_item' ) );
		add_action( 'wp_ajax_cfr2_cancel_bulk', array( $this, 'ajax_cancel_bulk' ) );
		add_action( 'wp_ajax_cfr2_get_bulk_progress', array( $this, 'ajax_get_bulk_progress' ) );
		add_action( 'wp_ajax_cfr2_get_bulk_counts', array( $this, 'ajax_get_bulk_counts' ) );
		add_action( 'wp_ajax_cfr2_get_pending_items', array( $this, 'ajax_get_pending_items' ) );
		add_action( 'wp_ajax_cfr2_cancel_pending_item', array( $this, 'ajax_cancel_pending_item' ) );
		add_action( 'wp_ajax_cfr2_clear_pending', array( $this, 'ajax_clear_pending' ) );
	}

	/**
	 * AJAX handler for bulk offload all.
	 */
	public function ajax_bulk_offload_all(): void {
		$this->verify_ajax_nonce();
		$this->verify_manage_options();
		$this->queue_manager->clear_old_items();

		$queued = $this->enqueue_non_offloaded_attachments();
		$this->send_queue_success( $queued, 'offload' );
	}

	/**
	 * AJAX handler for bulk restore all.
	 */
	public function ajax_bulk_restore_all(): void {
		$this->verify_ajax_nonce();
		$this->verify_manage_options();
		$this->queue_manager->clear_old_items();

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$attachments = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = %s AND meta_value = '1'",
				MetaKeys::OFFLOADED
			)
		);

		$queued = $this->enqueue_items( $attachments, QueueAction::RESTORE );
		$this->send_queue_success( $queued, 'restore' );
	}

	/**
	 * AJAX handler for bulk delete local files (disk saving).
	 */
	public function ajax_bulk_delete_local(): void {
		$this->verify_ajax_nonce();
		$this->verify_manage_options();
		$this->queue_manager->clear_old_items();

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$attachments = $wpdb->get_col(
			"SELECT attachment_id FROM {$wpdb->prefix}cfr2_offload_status
			 WHERE local_exists = 1"
		);

		$queued = $this->enqueue_items( $attachments, QueueAction::DELETE_LOCAL );
		$this->send_queue_success( $queued, 'local deletion' );
	}

	/**
	 * AJAX handler for process single bulk item.
	 */
	public function ajax_process_bulk_item(): void {
		$this->process_action_item( QueueAction::OFFLOAD );
	}

	/**
	 * AJAX handler for process single restore item.
	 */
	public function ajax_process_restore_item(): void {
		$this->process_action_item( QueueAction::RESTORE );
	}

	/**
	 * AJAX handler for process single delete local item.
	 */
	public function ajax_process_delete_local_item(): void {
		$this->process_action_item( QueueAction::DELETE_LOCAL );
	}

	/**
	 * Generic action item processor.
	 *
	 * @param string $action Action type.
	 */
	private function process_action_item( string $action ): void {
		$this->verify_ajax_nonce();
		$this->verify_manage_options();

		// Check if cancelled.
		if ( get_transient( TransientKeys::BULK_CANCELLED ) ) {
			wp_send_json_success(
				array(
					'done'    => true,
					'message' => $this->get_cancel_message( $action ),
				)
			);
		}

		// Get next item.
		$item = $this->queue_manager->get_next_pending( $action );
		if ( ! $item ) {
			wp_send_json_success(
				array(
					'done'    => true,
					'message' => $this->get_done_message( $action ),
				)
			);
		}

		// Process item.
		$result = $this->processor->process_item( $item, $action );
		wp_send_json_success(
			array(
				'done'     => false,
				'status'   => $result['status'],
				'filename' => $result['filename'],
				'message'  => $result['message'],
			)
		);
	}

	/**
	 * Get cancellation message for action.
	 *
	 * @param string $action Action type.
	 * @return string Translated message.
	 */
	private function get_cancel_message( string $action ): string {
		switch ( $action ) {
			case QueueAction::OFFLOAD:
				return __( 'Bulk offload cancelled.', 'cf-r2-offload-cdn' );
			case QueueAction::RESTORE:
				return __( 'Bulk restore cancelled.', 'cf-r2-offload-cdn' );
			case QueueAction::DELETE_LOCAL:
				return __( 'Bulk delete cancelled.', 'cf-r2-offload-cdn' );
			default:
				return __( 'Operation cancelled.', 'cf-r2-offload-cdn' );
		}
	}

	/**
	 * Get completion message for action.
	 *
	 * @param string $action Action type.
	 * @return string Translated message.
	 */
	private function get_done_message( string $action ): string {
		switch ( $action ) {
			case QueueAction::OFFLOAD:
				return __( 'All items processed.', 'cf-r2-offload-cdn' );
			case QueueAction::RESTORE:
				return __( 'All items restored.', 'cf-r2-offload-cdn' );
			case QueueAction::DELETE_LOCAL:
				return __( 'All local files deleted.', 'cf-r2-offload-cdn' );
			default:
				return __( 'Operation completed.', 'cf-r2-offload-cdn' );
		}
	}

	/**
	 * AJAX handler for cancel bulk.
	 */
	public function ajax_cancel_bulk(): void {
		$this->verify_ajax_nonce();
		$this->verify_manage_options();

		set_transient( TransientKeys::BULK_CANCELLED, true, CacheDuration::CANCEL_TTL );

		// Clear pending queue items.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'cfr2_offload_queue',
			array( 'status' => QueueStatus::CANCELLED ),
			array( 'status' => QueueStatus::PENDING ),
			array( '%s' ),
			array( '%s' )
		);

		wp_send_json_success( array( 'message' => __( 'Bulk offload cancelled.', 'cf-r2-offload-cdn' ) ) );
	}

	/**
	 * AJAX handler for get bulk progress.
	 */
	public function ajax_get_bulk_progress(): void {
		$this->verify_ajax_nonce();
		$this->verify_manage_options();

		$progress = $this->progress_service->get_progress();
		wp_send_json_success( $progress );
	}

	/**
	 * AJAX handler for getting bulk button counts.
	 */
	public function ajax_get_bulk_counts(): void {
		$this->verify_ajax_nonce();
		$this->verify_manage_options();

		// Clear dashboard stats cache first to ensure fresh data.
		delete_transient( TransientKeys::DASHBOARD_STATS );

		$counts = $this->progress_service->get_counts();
		wp_send_json_success( $counts );
	}

	/**
	 * AJAX handler for getting pending items list.
	 */
	public function ajax_get_pending_items(): void {
		$this->verify_ajax_nonce();
		$this->verify_manage_options();

		$items = $this->progress_service->get_pending_items();
		wp_send_json_success( array( 'items' => $items ) );
	}

	/**
	 * AJAX handler for canceling a single pending item.
	 */
	public function ajax_cancel_pending_item(): void {
		$this->verify_ajax_nonce();
		$this->verify_manage_options();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$item_id = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;

		if ( ! $item_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid item ID.', 'cf-r2-offload-cdn' ) ) );
		}

		global $wpdb;

		// Only cancel if pending (not processing).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$wpdb->prefix . 'cfr2_offload_queue',
			array( 'status' => QueueStatus::CANCELLED ),
			array(
				'id'     => $item_id,
				'status' => QueueStatus::PENDING,
			),
			array( '%s' ),
			array( '%d', '%s' )
		);

		if ( $updated ) {
			// Clear stats cache.
			delete_transient( TransientKeys::DASHBOARD_STATS );

			wp_send_json_success( array( 'message' => __( 'Item cancelled.', 'cf-r2-offload-cdn' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Could not cancel item. It may already be processing.', 'cf-r2-offload-cdn' ) ) );
		}
	}

	/**
	 * AJAX handler for clearing all pending items.
	 */
	public function ajax_clear_pending(): void {
		$this->verify_ajax_nonce();
		$this->verify_manage_options();

		global $wpdb;

		// Cancel all pending items (not processing).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$wpdb->prefix . 'cfr2_offload_queue',
			array( 'status' => QueueStatus::CANCELLED ),
			array( 'status' => QueueStatus::PENDING ),
			array( '%s' ),
			array( '%s' )
		);

		// Set cancellation flag to stop any running process.
		set_transient( TransientKeys::BULK_CANCELLED, true, CacheDuration::CANCEL_TTL );

		// Clear stats cache.
		delete_transient( TransientKeys::DASHBOARD_STATS );

		wp_send_json_success(
			array(
				'message'   => sprintf(
					/* translators: %d: number of items cancelled */
					__( '%d pending items cancelled.', 'cf-r2-offload-cdn' ),
					$updated ? $updated : 0
				),
				'cancelled' => $updated ? $updated : 0,
			)
		);
	}

	/**
	 * Enqueue all non-offloaded attachments in batches to avoid loading all IDs into memory.
	 *
	 * @return int Number of queued items.
	 */
	private function enqueue_non_offloaded_attachments(): int {
		global $wpdb;

		$queued  = 0;
		$last_id = 0;

		while ( true ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$attachments = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID
					 FROM {$wpdb->posts} p
					 LEFT JOIN {$wpdb->postmeta} pm
						ON p.ID = pm.post_id
						AND pm.meta_key = %s
						AND pm.meta_value = '1'
					 WHERE p.post_type = 'attachment'
						AND p.post_status = 'inherit'
						AND p.ID > %d
						AND pm.post_id IS NULL
					 ORDER BY p.ID ASC
					 LIMIT %d",
					MetaKeys::OFFLOADED,
					$last_id,
					self::OFFLOAD_QUERY_BATCH_SIZE
				)
			);

			if ( empty( $attachments ) ) {
				break;
			}

			$queued  += $this->enqueue_items( $attachments, QueueAction::OFFLOAD, true );
			$last_id  = (int) end( $attachments );

			if ( count( $attachments ) < self::OFFLOAD_QUERY_BATCH_SIZE ) {
				break;
			}
		}

		return $queued;
	}

	/**
	 * Enqueue items for processing.
	 *
	 * @param array  $attachments   Attachment IDs.
	 * @param string $action        Queue action.
	 * @param bool   $check_exists  Check if item already exists.
	 * @return int Number of items queued.
	 */
	private function enqueue_items( array $attachments, string $action, bool $check_exists = false ): int {
		$queued        = 0;
		$existing_ids  = $check_exists ? $this->get_pending_attachment_lookup( $attachments ) : array();

		foreach ( $attachments as $attachment_id ) {
			$attachment_id = (int) $attachment_id;

			if ( isset( $existing_ids[ $attachment_id ] ) ) {
				continue;
			}

			$this->queue_manager->enqueue( $attachment_id, $action );
			++$queued;
		}
		delete_transient( TransientKeys::BULK_CANCELLED );

		return $queued;
	}

	/**
	 * Build lookup map of attachment IDs already pending in queue.
	 *
	 * @param array $attachments Attachment IDs.
	 * @return array<int, bool> Lookup array where key is attachment ID.
	 */
	private function get_pending_attachment_lookup( array $attachments ): array {
		global $wpdb;

		$attachment_ids = array_values( array_filter( array_map( 'absint', $attachments ) ) );
		if ( empty( $attachment_ids ) ) {
			return array();
		}

		$ids_sql = implode( ',', $attachment_ids );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are sanitized with absint.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT attachment_id FROM {$wpdb->prefix}cfr2_offload_queue
				 WHERE status = %s AND attachment_id IN ({$ids_sql})",
				QueueStatus::PENDING
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$lookup = array();
		foreach ( $existing_ids as $attachment_id ) {
			$lookup[ (int) $attachment_id ] = true;
		}

		return $lookup;
	}

	/**
	 * Send queue success response.
	 *
	 * @param int    $queued Number of items queued.
	 * @param string $action Action description.
	 */
	private function send_queue_success( int $queued, string $action ): void {
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %1$d: number of files, %2$s: action */
					__( '%1$d files queued for %2$s.', 'cf-r2-offload-cdn' ),
					$queued,
					$action
				),
				'total'   => $queued,
			)
		);
	}
}
