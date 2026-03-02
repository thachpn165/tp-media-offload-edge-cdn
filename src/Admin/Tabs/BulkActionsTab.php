<?php
/**
 * Bulk Actions Tab class.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Admin\Tabs;

defined( 'ABSPATH' ) || exit;

use ThachPN165\CFR2OffLoad\Constants\QueueStatus;

/**
 * BulkActionsTab class - renders the bulk actions tab content.
 */
class BulkActionsTab {

	/**
	 * Render the bulk actions tab.
	 */
	public static function render(): void {
		global $wpdb;

		// Get stats from cached source.
		$stats = DashboardTab::get_cached_stats();

		$total_count         = $stats['total'];
		$offloaded_count     = $stats['offloaded'];
		$pending_count       = $stats['pending'];
		$local_count         = $stats['local'];
		$disk_saveable_count = $stats['disk_saveable'] ?? 0;

		// Failed count needs fresh query (not cached to show accurate retry count).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom queue table.
		$failed_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT attachment_id)
				 FROM {$wpdb->prefix}cfr2_offload_queue
				 WHERE status = %s
				 AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
				QueueStatus::FAILED
			)
		);
		?>
		<div class="cloudflare-r2-offload-cdn-tab-content" id="tab-bulk-actions">
			<h2><?php esc_html_e( 'Bulk Actions', 'cf-r2-offload-cdn' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Manage bulk offload operations and monitor progress.', 'cf-r2-offload-cdn' ); ?></p>

			<?php self::render_quick_stats( $total_count, $offloaded_count, $pending_count, $local_count ); ?>
			<?php self::render_bulk_actions( $failed_count, $offloaded_count, $local_count, $disk_saveable_count ); ?>
			<?php self::render_progress_section(); ?>
			<?php self::render_activity_log(); ?>
			<?php self::render_error_summary(); ?>
		</div>
		<?php
	}

	/**
	 * Render quick stats section.
	 *
	 * @param int $total_count     Total media count.
	 * @param int $offloaded_count Offloaded count.
	 * @param int $pending_count   Pending count.
	 * @param int $local_count     Local count.
	 */
	private static function render_quick_stats( int $total_count, int $offloaded_count, int $pending_count, int $local_count ): void {
		?>
		<div class="settings-section cfr2-quick-stats">
			<h3><?php esc_html_e( 'Quick Stats', 'cf-r2-offload-cdn' ); ?></h3>

			<div class="cfr2-stats-row">
				<div class="cfr2-stat">
					<span class="cfr2-stat-value"><?php echo esc_html( number_format_i18n( $total_count ) ); ?></span>
					<span class="cfr2-stat-label"><?php esc_html_e( 'Total Media', 'cf-r2-offload-cdn' ); ?></span>
				</div>
				<div class="cfr2-stat">
					<span class="cfr2-stat-value"><?php echo esc_html( number_format_i18n( $offloaded_count ) ); ?></span>
					<span class="cfr2-stat-label"><?php esc_html_e( 'Offloaded', 'cf-r2-offload-cdn' ); ?></span>
				</div>
				<div class="cfr2-stat <?php echo $pending_count > 0 ? 'cfr2-stat-clickable' : ''; ?>" <?php echo $pending_count > 0 ? 'id="cfr2-pending-stat"' : ''; ?>>
					<span class="cfr2-stat-value"><?php echo esc_html( number_format_i18n( $pending_count ) ); ?></span>
					<span class="cfr2-stat-label">
						<?php esc_html_e( 'Pending', 'cf-r2-offload-cdn' ); ?>
						<?php if ( $pending_count > 0 ) : ?>
							<span class="dashicons dashicons-visibility" style="font-size: 14px; vertical-align: middle;"></span>
						<?php endif; ?>
					</span>
				</div>
				<div class="cfr2-stat">
					<span class="cfr2-stat-value"><?php echo esc_html( number_format_i18n( $local_count ) ); ?></span>
					<span class="cfr2-stat-label"><?php esc_html_e( 'Local', 'cf-r2-offload-cdn' ); ?></span>
				</div>
			</div>
		</div>
		<?php
		self::render_pending_items_section();
	}

	/**
	 * Render pending items section (hidden by default).
	 */
	private static function render_pending_items_section(): void {
		?>
		<div id="cfr2-pending-section" class="settings-section" style="display: none;">
			<div class="cfr2-section-header">
				<h3><?php esc_html_e( 'Pending Queue', 'cf-r2-offload-cdn' ); ?></h3>
				<div>
					<button type="button" id="cfr2-clear-pending" class="button button-secondary button-small" style="color: #d63638;">
						<?php esc_html_e( 'Clear All', 'cf-r2-offload-cdn' ); ?>
					</button>
					<button type="button" id="cfr2-close-pending" class="button button-small">
						<?php esc_html_e( 'Close', 'cf-r2-offload-cdn' ); ?>
					</button>
				</div>
			</div>
			<div id="cfr2-pending-list" class="cfr2-pending-list">
				<p class="cfr2-loading"><?php esc_html_e( 'Loading...', 'cf-r2-offload-cdn' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render bulk actions section.
	 *
	 * @param int $failed_count       Failed items count.
	 * @param int $offloaded_count    Offloaded items count.
	 * @param int $local_count        Local items count.
	 * @param int $disk_saveable_count Offloaded files with local copies that can be deleted.
	 */
	private static function render_bulk_actions( int $failed_count, int $offloaded_count, int $local_count, int $disk_saveable_count = 0 ): void {
		?>
		<div class="settings-section cfr2-bulk-actions-section">
			<h3><?php esc_html_e( 'Bulk Actions', 'cf-r2-offload-cdn' ); ?></h3>

			<div class="cfr2-bulk-actions">
				<?php if ( $local_count > 0 ) : ?>
					<button type="button" id="cfr2-bulk-offload-all" class="button button-primary">
						<?php
						/* translators: %d: number of local items */
						echo esc_html( sprintf( __( 'Offload All (%d)', 'cf-r2-offload-cdn' ), $local_count ) );
						?>
					</button>
				<?php endif; ?>
				<?php if ( $offloaded_count > 0 ) : ?>
					<button type="button" id="cfr2-bulk-restore-all" class="button">
						<?php
						/* translators: %d: number of offloaded items */
						echo esc_html( sprintf( __( 'Restore All (%d)', 'cf-r2-offload-cdn' ), $offloaded_count ) );
						?>
					</button>
				<?php endif; ?>
				<?php if ( $failed_count > 0 ) : ?>
					<button type="button" id="cfr2-retry-all-failed" class="button">
						<?php
						/* translators: %d: number of failed items */
						echo esc_html( sprintf( __( 'Retry Failed (%d)', 'cf-r2-offload-cdn' ), $failed_count ) );
						?>
					</button>
				<?php endif; ?>
				<?php if ( $disk_saveable_count > 0 ) : ?>
					<button type="button" id="cfr2-bulk-delete-local" class="button button-secondary" style="color: #d63638;">
						<?php
						/* translators: %d: number of items with local copies */
						echo esc_html( sprintf( __( 'Free Disk Space (%d)', 'cf-r2-offload-cdn' ), $disk_saveable_count ) );
						?>
					</button>
				<?php endif; ?>
				<button type="button" id="cfr2-cancel-bulk" class="button button-secondary" style="display:none;">
					<?php esc_html_e( 'Cancel', 'cf-r2-offload-cdn' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Render progress section.
	 */
	private static function render_progress_section(): void {
		?>
		<div id="cfr2-bulk-progress-section" style="display:none;">
			<div class="settings-section cfr2-progress-section">
				<h3><?php esc_html_e( 'Progress', 'cf-r2-offload-cdn' ); ?></h3>

				<div class="cfr2-progress-bar-container">
					<div class="cfr2-progress-bar">
						<div class="cfr2-progress-fill" style="width: 0%;"></div>
					</div>
					<span class="cfr2-progress-percentage">0%</span>
				</div>

				<div class="cfr2-progress-details">
					<p class="cfr2-current-item">
						<strong><?php esc_html_e( 'Current:', 'cf-r2-offload-cdn' ); ?></strong>
						<span id="cfr2-current-file"></span>
					</p>
					<p class="cfr2-progress-text"></p>
					<p class="cfr2-elapsed-time">
						<strong><?php esc_html_e( 'Elapsed Time:', 'cf-r2-offload-cdn' ); ?></strong>
						<span id="cfr2-elapsed"></span>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render activity log section (terminal style).
	 */
	private static function render_activity_log(): void {
		?>
		<div class="settings-section cfr2-terminal-section">
			<div class="cfr2-section-header">
				<h3><?php esc_html_e( 'Process Log', 'cf-r2-offload-cdn' ); ?></h3>
				<button type="button" id="cfr2-clear-log" class="button button-small">
					<?php esc_html_e( 'Clear', 'cf-r2-offload-cdn' ); ?>
				</button>
			</div>

			<div class="cfr2-terminal" id="cfr2-terminal">
				<div class="cfr2-terminal-header">
					<span class="cfr2-terminal-dot red"></span>
					<span class="cfr2-terminal-dot yellow"></span>
					<span class="cfr2-terminal-dot green"></span>
					<span class="cfr2-terminal-title"><?php esc_html_e( 'R2 Offload Terminal', 'cf-r2-offload-cdn' ); ?></span>
				</div>
				<div class="cfr2-terminal-body" id="cfr2-terminal-output">
					<div class="cfr2-terminal-line cfr2-terminal-info">
						<span class="cfr2-terminal-prompt">$</span>
						<span><?php esc_html_e( 'Ready. Click "Offload All Media" to start.', 'cf-r2-offload-cdn' ); ?></span>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render error summary section.
	 */
	private static function render_error_summary(): void {
		?>
		<div id="cfr2-error-summary-section" style="display:none;">
			<div class="settings-section cfr2-error-summary-section">
				<div class="cfr2-section-header">
					<h3><?php esc_html_e( 'Failed Items', 'cf-r2-offload-cdn' ); ?></h3>
					<button type="button" id="cfr2-retry-all" class="button button-primary button-small">
						<?php esc_html_e( 'Retry All', 'cf-r2-offload-cdn' ); ?>
					</button>
				</div>

				<div class="cfr2-error-summary" id="cfr2-error-list"></div>
			</div>
		</div>
		<?php
	}
}
