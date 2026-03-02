<?php
/**
 * Activity AJAX Handler class.
 *
 * Handles AJAX requests for activity logs, stats, and retry operations.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

use ThachPN165\CFR2OffLoad\Constants\NonceActions;
use ThachPN165\CFR2OffLoad\Constants\TransientKeys;
use ThachPN165\CFR2OffLoad\Constants\QueueStatus;
use ThachPN165\CFR2OffLoad\Services\StatsTracker;
use ThachPN165\CFR2OffLoad\Services\BulkOperationLogger;
use ThachPN165\CFR2OffLoad\Admin\Widgets\StatsWidget;

/**
 * ActivityAjaxHandler class - handles activity-related AJAX requests.
 */
class ActivityAjaxHandler {

	/**
	 * Register AJAX hooks.
	 */
	public function register_hooks(): void {
		add_action( 'wp_ajax_cfr2_get_stats', array( $this, 'ajax_get_stats' ) );
		add_action( 'wp_ajax_cfr2_get_activity_log', array( $this, 'ajax_get_activity_log' ) );
		add_action( 'wp_ajax_cfr2_retry_failed', array( $this, 'ajax_retry_failed' ) );
		add_action( 'wp_ajax_cfr2_retry_single', array( $this, 'ajax_retry_single' ) );
		add_action( 'wp_ajax_cfr2_clear_log', array( $this, 'ajax_clear_log' ) );
	}

	/**
	 * Verify nonce for activity operations.
	 *
	 * @return bool True if valid, sends error response otherwise.
	 */
	private function verify_activity_nonce(): bool {
		// Support both legacy and new nonces during transition.
		$nonce_valid = check_ajax_referer( NonceActions::LEGACY, 'nonce', false );
		if ( false === $nonce_valid ) {
			$nonce_valid = check_ajax_referer( NonceActions::ACTIVITY, 'cfr2_nonce', false );
		}

		if ( false === $nonce_valid ) {
			wp_send_json_error(
				array( 'message' => __( 'Security check failed.', 'cf-r2-offload-cdn' ) ),
				403
			);
			return false;
		}

		return true;
	}

	/**
	 * Check user permissions.
	 *
	 * @return bool True if authorized, sends error response otherwise.
	 */
	private function check_permissions(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cf-r2-offload-cdn' ) ), 403 );
			return false;
		}
		return true;
	}

	/**
	 * AJAX handler for get stats.
	 */
	public function ajax_get_stats(): void {
		$this->verify_activity_nonce();
		$this->check_permissions();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$period = sanitize_text_field( wp_unslash( $_GET['period'] ?? 'month' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		switch ( $period ) {
			case 'week':
				$days = 7;
				break;
			case 'month':
			default:
				$days = 30;
				break;
		}

		$daily_stats   = StatsTracker::get_daily_stats( $days );
		$current_month = StatsTracker::get_current_month_transformations();
		$chart_data    = StatsWidget::get_chart_data();

		wp_send_json_success(
			array(
				'daily'         => $daily_stats,
				'current_month' => $current_month,
				'chart_data'    => $chart_data,
			)
		);
	}

	/**
	 * AJAX handler for get activity log.
	 */
	public function ajax_get_activity_log(): void {
		$this->verify_activity_nonce();
		$this->check_permissions();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$limit = isset( $_GET['limit'] ) ? absint( $_GET['limit'] ) : 20;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$logs = BulkOperationLogger::get_logs( $limit );

		wp_send_json_success( array( 'logs' => $logs ) );
	}

	/**
	 * AJAX handler for retry all failed.
	 */
	public function ajax_retry_failed(): void {
		$this->verify_activity_nonce();
		$this->check_permissions();

		global $wpdb;

		// Clear cancellation flag.
		delete_transient( TransientKeys::BULK_CANCELLED );

		// Get all failed items from last 24 hours and reset to pending.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom queue table.
		$queued = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}cfr2_offload_queue
				 SET status = %s, error_message = NULL, processed_at = NULL
				 WHERE status = %s
				 AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
				QueueStatus::PENDING,
				QueueStatus::FAILED
			)
		);

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of items */
					__( '%d items queued for retry.', 'cf-r2-offload-cdn' ),
					$queued
				),
				'queued'  => (int) $queued,
			)
		);
	}

	/**
	 * AJAX handler for retry single.
	 */
	public function ajax_retry_single(): void {
		$this->verify_activity_nonce();
		$this->check_permissions();

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $attachment_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid attachment ID.', 'cf-r2-offload-cdn' ) ) );
		}

		global $wpdb;

		// Reset status to pending.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom queue table.
		$updated = $wpdb->update(
			$wpdb->prefix . 'cfr2_offload_queue',
			array(
				'status'        => QueueStatus::PENDING,
				'error_message' => null,
				'processed_at'  => null,
			),
			array( 'attachment_id' => $attachment_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( $updated ) {
			wp_send_json_success( array( 'message' => __( 'Item queued for retry.', 'cf-r2-offload-cdn' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to queue item.', 'cf-r2-offload-cdn' ) ) );
		}
	}

	/**
	 * AJAX handler for clear log.
	 */
	public function ajax_clear_log(): void {
		$this->verify_activity_nonce();
		$this->check_permissions();

		BulkOperationLogger::clear();

		wp_send_json_success( array( 'message' => __( 'Activity log cleared.', 'cf-r2-offload-cdn' ) ) );
	}
}
