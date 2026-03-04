<?php
/**
 * Dashboard Tab class.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Admin\Tabs;

defined( 'ABSPATH' ) || exit;

use ThachPN165\CFR2OffLoad\Admin\Widgets\StatsWidget;
use ThachPN165\CFR2OffLoad\Constants\TransientKeys;
use ThachPN165\CFR2OffLoad\Constants\CacheDuration;
use ThachPN165\CFR2OffLoad\Constants\MetaKeys;

/**
 * DashboardTab class - renders the dashboard tab content.
 */
class DashboardTab {

	/**
	 * Render the dashboard tab.
	 */
	public static function render(): void {
		?>
		<div class="cloudflare-r2-offload-cdn-tab-content active" id="tab-dashboard">
			<h2><?php esc_html_e( 'TP Media Offload & Edge CDN', 'tp-media-offload-edge-cdn' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Offload WordPress media to Cloudflare R2 storage and serve via CDN with automatic image optimization (WebP/AVIF, resize, quality).', 'tp-media-offload-edge-cdn' ); ?></p>

			<?php
			self::render_usage_statistics();
			self::render_quick_stats();
			self::render_usage_guides();
			?>
		</div>
		<?php
	}

	/**
	 * Render quick stats section.
	 */
	private static function render_quick_stats(): void {
		$stats = self::get_cached_stats();

		$total_count     = $stats['total'];
		$offloaded_count = $stats['offloaded'];
		$pending_count   = $stats['pending'];
		$local_count     = $stats['local'];
		?>
		<div class="settings-section cfr2-quick-stats">
			<h3><?php esc_html_e( 'Media Overview', 'tp-media-offload-edge-cdn' ); ?></h3>

			<div class="cfr2-stats-row">
				<div class="cfr2-stat">
					<span class="cfr2-stat-value"><?php echo esc_html( number_format_i18n( $total_count ) ); ?></span>
					<span class="cfr2-stat-label"><?php esc_html_e( 'Total Media', 'tp-media-offload-edge-cdn' ); ?></span>
				</div>
				<div class="cfr2-stat">
					<span class="cfr2-stat-value"><?php echo esc_html( number_format_i18n( $offloaded_count ) ); ?></span>
					<span class="cfr2-stat-label"><?php esc_html_e( 'Offloaded', 'tp-media-offload-edge-cdn' ); ?></span>
				</div>
				<div class="cfr2-stat">
					<span class="cfr2-stat-value"><?php echo esc_html( number_format_i18n( $pending_count ) ); ?></span>
					<span class="cfr2-stat-label"><?php esc_html_e( 'Pending', 'tp-media-offload-edge-cdn' ); ?></span>
				</div>
				<div class="cfr2-stat">
					<span class="cfr2-stat-value"><?php echo esc_html( number_format_i18n( $local_count ) ); ?></span>
					<span class="cfr2-stat-label"><?php esc_html_e( 'Local', 'tp-media-offload-edge-cdn' ); ?></span>
				</div>
			</div>

			<p style="margin-top: 16px; text-align: center;">
				<a href="#" class="button" data-tab="bulk-actions" id="goto-bulk-actions">
					<?php esc_html_e( 'Go to Bulk Actions', 'tp-media-offload-edge-cdn' ); ?> &rarr;
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render usage statistics section.
	 */
	private static function render_usage_statistics(): void {
		?>
		<div class="settings-section cfr2-stats-section">
			<h3><?php esc_html_e( 'Worker Statistics', 'tp-media-offload-edge-cdn' ); ?></h3>
			<?php StatsWidget::render(); ?>
		</div>
		<?php
	}

	/**
	 * Get cached dashboard stats with 5-minute transient caching.
	 *
	 * @return array Stats array with total, offloaded, pending, local counts.
	 */
	public static function get_cached_stats(): array {
		$cache_key = TransientKeys::DASHBOARD_STATS;
		$stats     = get_transient( $cache_key );

		if ( false !== $stats ) {
			return $stats;
		}

		// Compute fresh stats.
		$stats = self::compute_stats();

		// Cache for 5 minutes.
		set_transient( $cache_key, $stats, CacheDuration::STATS_CACHE );

		return $stats;
	}

	/**
	 * Compute dashboard stats from database.
	 *
	 * @return array Stats array.
	 */
	private static function compute_stats(): array {
		global $wpdb;

		$total_attachments = wp_count_posts( 'attachment' );
		$total_count       = $total_attachments->inherit ?? 0;

		// Count files on R2 (offloaded).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregating postmeta for stats.
		$offloaded_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id)
				 FROM {$wpdb->postmeta}
				 WHERE meta_key = %s AND meta_value = '1'",
				MetaKeys::OFFLOADED
			)
		);

		// Count pending in queue.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom queue table.
		$pending_count = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT attachment_id)
			 FROM {$wpdb->prefix}cfr2_offload_queue
			 WHERE status IN ('pending', 'processing')"
		);

		// Count offloaded files where local copy was deleted (R2 only).
		// Only count attachments that still exist and have local_exists = 0.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom status table.
		$r2_only_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}cfr2_offload_status os
			 INNER JOIN {$wpdb->posts} p ON os.attachment_id = p.ID
			 WHERE os.local_exists = 0 AND p.post_type = 'attachment'"
		);

		// Count offloaded files where local copy still exists (can be deleted to save disk).
		// Only count attachments that still exist and have local_exists = 1.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom status table.
		$disk_saveable_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}cfr2_offload_status os
			 INNER JOIN {$wpdb->posts} p ON os.attachment_id = p.ID
			 WHERE os.local_exists = 1 AND p.post_type = 'attachment'"
		);

		// Local files = Total - (R2 only files) - Pending.
		// This counts all files that exist locally (both offloaded with local copy AND not offloaded).
		$local_count = max( 0, $total_count - $r2_only_count - $pending_count );

		return array(
			'total'         => $total_count,
			'offloaded'     => $offloaded_count,
			'pending'       => $pending_count,
			'local'         => $local_count,
			'r2_only'       => $r2_only_count,
			'disk_saveable' => $disk_saveable_count,
		);
	}

	/**
	 * Render usage guides accordions.
	 */
	private static function render_usage_guides(): void {
		$guides = self::get_usage_guides();
		?>
		<div class="cloudflare-r2-offload-cdn-guides">
			<h3><?php esc_html_e( 'Setup Guides', 'tp-media-offload-edge-cdn' ); ?></h3>
			<div class="cloudflare-r2-offload-cdn-accordion">
				<?php foreach ( $guides as $guide ) : ?>
					<div class="cloudflare-r2-offload-cdn-accordion-item">
						<button type="button" class="cloudflare-r2-offload-cdn-accordion-header">
							<span class="dashicons dashicons-<?php echo esc_attr( $guide['icon'] ); ?>"></span>
							<span class="title"><?php echo esc_html( $guide['title'] ); ?></span>
							<span class="dashicons dashicons-arrow-down-alt2 toggle-icon"></span>
						</button>
						<div class="cloudflare-r2-offload-cdn-accordion-content">
							<?php echo wp_kses_post( $guide['content'] ); ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Get usage guides content.
	 *
	 * @return array Guides array.
	 */
	private static function get_usage_guides(): array {
		return array(
			self::get_r2_setup_guide(),
			self::get_api_token_guide(),
			self::get_cdn_setup_guide(),
			self::get_offload_guide(),
			self::get_optimization_guide(),
		);
	}

	/**
	 * Get R2 bucket setup guide.
	 *
	 * @return array Guide data.
	 */
	private static function get_r2_setup_guide(): array {
		return array(
			'icon'    => 'cloud-saved',
			'title'   => __( '1. Create R2 Bucket', 'tp-media-offload-edge-cdn' ),
			'content' => '
				<ol>
					<li>' . __( 'Log in to <a href="https://dash.cloudflare.com" target="_blank">Cloudflare Dashboard</a>', 'tp-media-offload-edge-cdn' ) . '</li>
					<li>' . __( 'Go to <strong>R2 Object Storage</strong> in the left sidebar', 'tp-media-offload-edge-cdn' ) . '</li>
					<li>' . __( 'Click <strong>Create bucket</strong>', 'tp-media-offload-edge-cdn' ) . '</li>
					<li>' . __( 'Enter bucket name (e.g., <code>my-wp-media</code>) and select location', 'tp-media-offload-edge-cdn' ) . '</li>
					<li>' . __( 'Click <strong>Create bucket</strong> to finish', 'tp-media-offload-edge-cdn' ) . '</li>
				</ol>
				<p><strong>' . __( 'Get R2 API Credentials:', 'tp-media-offload-edge-cdn' ) . '</strong></p>
				<ol>
					<li>' . __( 'In R2 page, click <strong>Manage R2 API Tokens</strong>', 'tp-media-offload-edge-cdn' ) . '</li>
					<li>' . __( 'Click <strong>Create API token</strong>', 'tp-media-offload-edge-cdn' ) . '</li>
					<li>' . __( 'Set permissions: <strong>Object Read & Write</strong>', 'tp-media-offload-edge-cdn' ) . '</li>
					<li>' . __( 'Specify bucket (or all buckets)', 'tp-media-offload-edge-cdn' ) . '</li>
					<li>' . __( 'Copy <strong>Access Key ID</strong> and <strong>Secret Access Key</strong>', 'tp-media-offload-edge-cdn' ) . '</li>
				</ol>
				<p class="description">' . __( 'Your Account ID is shown at the top right of the R2 page.', 'tp-media-offload-edge-cdn' ) . '</p>',
		);
	}

	/**
	 * Get API token creation guide.
	 *
	 * @return array Guide data.
	 */
	private static function get_api_token_guide(): array {
		return array(
			'icon'    => 'admin-network',
			'title'   => __( '2. Create Cloudflare API Token', 'tp-media-offload-edge-cdn' ),
			'content' => '
				<p>' . __( 'API Token is required for Worker deployment and cache purging.', 'tp-media-offload-edge-cdn' ) . '</p>
				<ol>
					<li>' . __( 'Go to <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank">API Tokens</a> page', 'tp-media-offload-edge-cdn' ) . '</li>
					<li>' . __( 'Click <strong>Create Token</strong>', 'tp-media-offload-edge-cdn' ) . '</li>
					<li>' . __( 'Select <strong>Create Custom Token</strong>', 'tp-media-offload-edge-cdn' ) . '</li>
					<li>' . __( 'Add the following permissions:', 'tp-media-offload-edge-cdn' ) . '</li>
				</ol>
				<table class="widefat" style="margin: 10px 0;">
					<thead>
						<tr>
							<th>' . __( 'Permission', 'tp-media-offload-edge-cdn' ) . '</th>
							<th>' . __( 'Access', 'tp-media-offload-edge-cdn' ) . '</th>
							<th>' . __( 'Purpose', 'tp-media-offload-edge-cdn' ) . '</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><code>Account > Workers Scripts</code></td>
							<td>Edit</td>
							<td>' . __( 'Deploy Worker', 'tp-media-offload-edge-cdn' ) . '</td>
						</tr>
						<tr>
							<td><code>Account > Workers R2 Storage</code></td>
							<td>Edit</td>
							<td>' . __( 'Bind R2 to Worker', 'tp-media-offload-edge-cdn' ) . '</td>
						</tr>
						<tr>
							<td><code>Zone > Zone</code></td>
							<td>Read</td>
							<td>' . __( 'List zones/domains', 'tp-media-offload-edge-cdn' ) . '</td>
						</tr>
						<tr>
							<td><code>Zone > DNS</code></td>
							<td>Edit</td>
							<td>' . __( 'Create/edit DNS records', 'tp-media-offload-edge-cdn' ) . '</td>
						</tr>
						<tr>
							<td><code>Zone > Workers Routes</code></td>
							<td>Edit</td>
							<td>' . __( 'Configure Worker route', 'tp-media-offload-edge-cdn' ) . '</td>
						</tr>
					</tbody>
				</table>
				<ol start="5">
					<li>' . __( 'Set <strong>Account Resources</strong>: Include your account', 'tp-media-offload-edge-cdn' ) . '</li>
					<li>' . __( 'Set <strong>Zone Resources</strong>: Include your domain', 'tp-media-offload-edge-cdn' ) . '</li>
					<li>' . __( 'Click <strong>Continue to summary</strong> > <strong>Create Token</strong>', 'tp-media-offload-edge-cdn' ) . '</li>
					<li>' . __( 'Copy the token (shown only once!)', 'tp-media-offload-edge-cdn' ) . '</li>
				</ol>',
		);
	}

	/**
	 * Get CDN setup guide.
	 *
	 * @return array Guide data.
	 */
	private static function get_cdn_setup_guide(): array {
		return array(
			'icon'    => 'performance',
			'title'   => __( '3. Configure CDN URL', 'tp-media-offload-edge-cdn' ),
			'content' => '
				<p>' . __( 'You need a custom domain to serve images via CDN with image transformations.', 'tp-media-offload-edge-cdn' ) . '</p>
				<p><strong>' . __( 'Option A: Use R2 Custom Domain (Recommended)', 'tp-media-offload-edge-cdn' ) . '</strong></p>
				<ol>
					<li>' . __( 'In R2 bucket settings, go to <strong>Settings</strong> tab', 'tp-media-offload-edge-cdn' ) . '</li>
					<li>' . __( 'Under <strong>Public access</strong>, click <strong>Connect Domain</strong>', 'tp-media-offload-edge-cdn' ) . '</li>
					<li>' . __( 'Enter subdomain (e.g., <code>cdn.yourdomain.com</code>)', 'tp-media-offload-edge-cdn' ) . '</li>
					<li>' . __( 'Cloudflare will automatically create DNS record', 'tp-media-offload-edge-cdn' ) . '</li>
				</ol>
				<p><strong>' . __( 'Option B: Use Worker Route', 'tp-media-offload-edge-cdn' ) . '</strong></p>
				<ol>
					<li>' . __( 'Deploy Worker first (see step 4)', 'tp-media-offload-edge-cdn' ) . '</li>
					<li>' . __( 'Create Worker route: <code>cdn.yourdomain.com/*</code>', 'tp-media-offload-edge-cdn' ) . '</li>
					<li>' . __( 'Add DNS record: CNAME <code>cdn</code> to your Worker', 'tp-media-offload-edge-cdn' ) . '</li>
				</ol>
				<p class="description">' . __( 'Enter the CDN URL in the CDN tab (e.g., https://cdn.yourdomain.com)', 'tp-media-offload-edge-cdn' ) . '</p>',
		);
	}

	/**
	 * Get offload guide.
	 *
	 * @return array Guide data.
	 */
	private static function get_offload_guide(): array {
		return array(
			'icon'    => 'upload',
			'title'   => __( '4. Offload Media to R2', 'tp-media-offload-edge-cdn' ),
			'content' => '
				<p><strong>' . __( 'Automatic Offload:', 'tp-media-offload-edge-cdn' ) . '</strong></p>
				<ul>
					<li>' . __( 'Enable <strong>Auto Offload</strong> in Offload tab', 'tp-media-offload-edge-cdn' ) . '</li>
					<li>' . __( 'New uploads will be automatically offloaded to R2', 'tp-media-offload-edge-cdn' ) . '</li>
				</ul>
				<p><strong>' . __( 'Bulk Offload Existing Media:', 'tp-media-offload-edge-cdn' ) . '</strong></p>
				<ol>
					<li>' . __( 'Go to <strong>Bulk Actions</strong> tab', 'tp-media-offload-edge-cdn' ) . '</li>
					<li>' . __( 'Set batch size (25-50 recommended)', 'tp-media-offload-edge-cdn' ) . '</li>
					<li>' . __( 'Click <strong>Start Bulk Offload</strong>', 'tp-media-offload-edge-cdn' ) . '</li>
					<li>' . __( 'Wait for completion (you can cancel anytime)', 'tp-media-offload-edge-cdn' ) . '</li>
				</ol>
				<p><strong>' . __( 'Manual Offload:', 'tp-media-offload-edge-cdn' ) . '</strong></p>
				<ul>
					<li>' . __( 'In Media Library, click <strong>Offload to R2</strong> for individual items', 'tp-media-offload-edge-cdn' ) . '</li>
					<li>' . __( 'Or use attachment edit page for single file offload', 'tp-media-offload-edge-cdn' ) . '</li>
				</ul>',
		);
	}

	/**
	 * Get optimization guide.
	 *
	 * @return array Guide data.
	 */
	private static function get_optimization_guide(): array {
		return array(
			'icon'    => 'images-alt2',
			'title'   => __( '5. Image Optimization Settings', 'tp-media-offload-edge-cdn' ),
			'content' => '
				<p>' . __( 'Configure image optimization in the <strong>CDN</strong> tab:', 'tp-media-offload-edge-cdn' ) . '</p>
				<table class="widefat" style="margin: 10px 0;">
					<tbody>
						<tr>
							<td><strong>' . __( 'Quality', 'tp-media-offload-edge-cdn' ) . '</strong></td>
							<td>' . __( '1-100 (default 85). Lower = smaller file, less quality.', 'tp-media-offload-edge-cdn' ) . '</td>
						</tr>
						<tr>
							<td><strong>' . __( 'Enable AVIF', 'tp-media-offload-edge-cdn' ) . '</strong></td>
							<td>' . __( 'Serve AVIF format for supported browsers. Better compression than WebP.', 'tp-media-offload-edge-cdn' ) . '</td>
						</tr>
						<tr>
							<td><strong>' . __( 'Smart Sizes', 'tp-media-offload-edge-cdn' ) . '</strong></td>
							<td>' . __( 'Auto-calculate responsive sizes. Increases Transformations usage.', 'tp-media-offload-edge-cdn' ) . '</td>
						</tr>
					</tbody>
				</table>
				<p><strong>' . __( 'Cost Information:', 'tp-media-offload-edge-cdn' ) . '</strong></p>
				<ul>
					<li>' . __( 'R2 Storage: $0.015/GB/month', 'tp-media-offload-edge-cdn' ) . '</li>
					<li>' . __( 'R2 Class A Operations (write): $4.50/million', 'tp-media-offload-edge-cdn' ) . '</li>
					<li>' . __( 'R2 Class B Operations (read): $0.36/million', 'tp-media-offload-edge-cdn' ) . '</li>
					<li>' . __( 'Image Transformations: First 5,000/month free, then $0.50/1,000', 'tp-media-offload-edge-cdn' ) . '</li>
				</ul>
				<p class="description">' . __( 'Monitor usage in Worker Statistics above and Cloudflare Dashboard.', 'tp-media-offload-edge-cdn' ) . '</p>',
		);
	}
}
