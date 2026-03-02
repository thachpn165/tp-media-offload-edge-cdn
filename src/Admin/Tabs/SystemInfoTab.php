<?php
/**
 * System Info Tab class.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Admin\Tabs;

defined( 'ABSPATH' ) || exit;

use ThachPN165\CFR2OffLoad\Services\EncryptionService;
use ThachPN165\CFR2OffLoad\Services\R2Client;

/**
 * SystemInfoTab class - renders system information and debug data.
 */
class SystemInfoTab {

	/**
	 * Render the system info tab.
	 */
	public static function render(): void {
		$settings = get_option( 'cloudflare_r2_offload_cdn_settings', array() );
		?>
		<div class="cloudflare-r2-offload-cdn-tab-content" id="tab-system-info">
			<h2><?php esc_html_e( 'System Information', 'cf-r2-offload-cdn' ); ?></h2>
			<p class="description"><?php esc_html_e( 'System status and debug information for troubleshooting.', 'cf-r2-offload-cdn' ); ?></p>

			<?php self::render_system_status( $settings ); ?>
			<?php self::render_debug_info( $settings ); ?>
		</div>
		<?php
	}

	/**
	 * Render system status section.
	 *
	 * @param array $settings Plugin settings.
	 */
	private static function render_system_status( array $settings ): void {
		$statuses = self::get_system_statuses( $settings );
		?>
		<div class="settings-section cfr2-system-status">
			<h3><?php esc_html_e( 'System Status', 'cf-r2-offload-cdn' ); ?></h3>
			<table class="widefat cfr2-status-table">
				<tbody>
					<?php foreach ( $statuses as $status ) : ?>
						<tr>
							<td class="cfr2-status-label"><?php echo esc_html( $status['label'] ); ?></td>
							<td class="cfr2-status-value">
								<?php if ( 'ok' === $status['status'] ) : ?>
									<span class="cfr2-status-ok"><span class="dashicons dashicons-yes-alt"></span> <?php echo esc_html( $status['value'] ); ?></span>
								<?php elseif ( 'warning' === $status['status'] ) : ?>
									<span class="cfr2-status-warning"><span class="dashicons dashicons-warning"></span> <?php echo esc_html( $status['value'] ); ?></span>
								<?php elseif ( 'error' === $status['status'] ) : ?>
									<span class="cfr2-status-error"><span class="dashicons dashicons-dismiss"></span> <?php echo esc_html( $status['value'] ); ?></span>
								<?php else : ?>
									<span class="cfr2-status-info"><?php echo esc_html( $status['value'] ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Get system statuses.
	 *
	 * @param array $settings Plugin settings.
	 * @return array Status items.
	 */
	private static function get_system_statuses( array $settings ): array {
		$statuses = array();

		// Plugin version.
		$statuses[] = array(
			'label'  => __( 'Plugin Version', 'cf-r2-offload-cdn' ),
			'value'  => \CFR2_VERSION,
			'status' => 'info',
		);

		// PHP version.
		$php_version = PHP_VERSION;
		$php_min     = '8.0';
		$php_status  = version_compare( $php_version, $php_min, '>=' ) ? 'ok' : 'error';
		$statuses[]  = array(
			'label'  => __( 'PHP Version', 'cf-r2-offload-cdn' ),
			'value'  => $php_version . ( 'error' === $php_status ? " (min: {$php_min})" : '' ),
			'status' => $php_status,
		);

		// WordPress version.
		$wp_version = get_bloginfo( 'version' );
		$wp_min     = '6.0';
		$wp_status  = version_compare( $wp_version, $wp_min, '>=' ) ? 'ok' : 'warning';
		$statuses[] = array(
			'label'  => __( 'WordPress Version', 'cf-r2-offload-cdn' ),
			'value'  => $wp_version,
			'status' => $wp_status,
		);

		// R2 Connection.
		$r2_configured = ! empty( $settings['r2_account_id'] ) && ! empty( $settings['r2_bucket'] ) && ! empty( $settings['r2_secret_access_key'] );
		$statuses[]    = array(
			'label'  => __( 'R2 Credentials', 'cf-r2-offload-cdn' ),
			'value'  => $r2_configured ? __( 'Configured', 'cf-r2-offload-cdn' ) : __( 'Not configured', 'cf-r2-offload-cdn' ),
			'status' => $r2_configured ? 'ok' : 'warning',
		);

		// Cloudflare API Token.
		$cf_configured = ! empty( $settings['cf_api_token'] );
		$statuses[]    = array(
			'label'  => __( 'Cloudflare API Token', 'cf-r2-offload-cdn' ),
			'value'  => $cf_configured ? __( 'Configured', 'cf-r2-offload-cdn' ) : __( 'Not configured', 'cf-r2-offload-cdn' ),
			'status' => $cf_configured ? 'ok' : 'warning',
		);

		// Worker Status.
		$worker_deployed = ! empty( $settings['worker_deployed'] );
		$statuses[]      = array(
			'label'  => __( 'Worker Status', 'cf-r2-offload-cdn' ),
			'value'  => $worker_deployed ? __( 'Deployed', 'cf-r2-offload-cdn' ) : __( 'Not deployed', 'cf-r2-offload-cdn' ),
			'status' => $worker_deployed ? 'ok' : 'warning',
		);

		// CDN Status.
		$cdn_enabled = ! empty( $settings['cdn_enabled'] ) && ! empty( $settings['cdn_url'] );
		$statuses[]  = array(
			'label'  => __( 'CDN Status', 'cf-r2-offload-cdn' ),
			'value'  => $cdn_enabled ? __( 'Enabled', 'cf-r2-offload-cdn' ) : __( 'Disabled', 'cf-r2-offload-cdn' ),
			'status' => $cdn_enabled ? 'ok' : 'info',
		);

		// cURL extension.
		$curl_enabled = function_exists( 'curl_version' );
		$statuses[]   = array(
			'label'  => __( 'cURL Extension', 'cf-r2-offload-cdn' ),
			'value'  => $curl_enabled ? __( 'Enabled', 'cf-r2-offload-cdn' ) : __( 'Not available', 'cf-r2-offload-cdn' ),
			'status' => $curl_enabled ? 'ok' : 'error',
		);

		// OpenSSL extension.
		$openssl_enabled = extension_loaded( 'openssl' );
		$statuses[]      = array(
			'label'  => __( 'OpenSSL Extension', 'cf-r2-offload-cdn' ),
			'value'  => $openssl_enabled ? __( 'Enabled', 'cf-r2-offload-cdn' ) : __( 'Not available', 'cf-r2-offload-cdn' ),
			'status' => $openssl_enabled ? 'ok' : 'error',
		);

		// Memory limit.
		$memory_limit  = ini_get( 'memory_limit' );
		$memory_bytes  = wp_convert_hr_to_bytes( $memory_limit );
		$memory_min    = 128 * 1024 * 1024; // 128MB.
		$memory_status = $memory_bytes >= $memory_min ? 'ok' : 'warning';
		$statuses[]    = array(
			'label'  => __( 'PHP Memory Limit', 'cf-r2-offload-cdn' ),
			'value'  => $memory_limit,
			'status' => $memory_status,
		);

		// Max execution time.
		$max_execution = ini_get( 'max_execution_time' );
		$exec_status   = ( 0 === (int) $max_execution || (int) $max_execution >= 60 ) ? 'ok' : 'warning';
		$statuses[]    = array(
			'label'  => __( 'Max Execution Time', 'cf-r2-offload-cdn' ),
			'value'  => 0 === (int) $max_execution ? __( 'Unlimited', 'cf-r2-offload-cdn' ) : $max_execution . 's',
			'status' => $exec_status,
		);

		return $statuses;
	}

	/**
	 * Render debug information section.
	 *
	 * @param array $settings Plugin settings.
	 */
	private static function render_debug_info( array $settings ): void {
		$debug_info = self::get_debug_info( $settings );
		?>
		<div class="settings-section cfr2-debug-info">
			<h3><?php esc_html_e( 'Debug Information', 'cf-r2-offload-cdn' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Copy this information when requesting support.', 'cf-r2-offload-cdn' ); ?></p>
			<textarea id="cfr2-debug-textarea" class="cfr2-debug-textarea" readonly rows="20"><?php echo esc_textarea( $debug_info ); ?></textarea>
			<p>
				<button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('cfr2-debug-textarea').value); this.textContent='Copied!';">
					<?php esc_html_e( 'Copy to Clipboard', 'cf-r2-offload-cdn' ); ?>
				</button>
			</p>
		</div>
		<?php
	}

	/**
	 * Get debug information as text.
	 *
	 * @param array $settings Plugin settings.
	 * @return string Debug info text.
	 */
	private static function get_debug_info( array $settings ): string {
		global $wpdb;

		$lines = array();

		$lines[] = '### CloudFlare R2 Offload CDN - Debug Info ###';
		$lines[] = '';

		// Plugin Info.
		$lines[] = '## Plugin';
		$lines[] = 'Version: ' . \CFR2_VERSION;
		$lines[] = '';

		// WordPress Info.
		$lines[] = '## WordPress';
		$lines[] = 'Version: ' . get_bloginfo( 'version' );
		$lines[] = 'Multisite: ' . ( is_multisite() ? 'Yes' : 'No' );
		$lines[] = 'Site URL: ' . get_site_url();
		$lines[] = 'Home URL: ' . get_home_url();
		$lines[] = 'Language: ' . get_locale();
		$lines[] = 'Timezone: ' . wp_timezone_string();
		$lines[] = '';

		// Server Info.
		$lines[] = '## Server';
		$lines[] = 'PHP Version: ' . PHP_VERSION;
		$lines[] = 'MySQL Version: ' . $wpdb->db_version();
		$lines[] = 'Web Server: ' . ( isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'Unknown' );
		$lines[] = 'Memory Limit: ' . ini_get( 'memory_limit' );
		$lines[] = 'Max Execution Time: ' . ini_get( 'max_execution_time' ) . 's';
		$lines[] = 'Max Upload Size: ' . size_format( wp_max_upload_size() );
		$lines[] = 'Post Max Size: ' . ini_get( 'post_max_size' );
		$lines[] = '';

		// PHP Extensions.
		$lines[]    = '## PHP Extensions';
		// Note: gd/imagick not needed - image processing done by Cloudflare Workers.
		$extensions = array( 'curl', 'openssl', 'json', 'mbstring' );
		foreach ( $extensions as $ext ) {
			$lines[] = $ext . ': ' . ( extension_loaded( $ext ) ? 'Enabled' : 'Disabled' );
		}
		$lines[] = '';

		// Plugin Settings (sanitized).
		$lines[] = '## Plugin Settings';
		$lines[] = 'R2 Account ID: ' . ( ! empty( $settings['r2_account_id'] ) ? substr( $settings['r2_account_id'], 0, 8 ) . '...' : 'Not set' );
		$lines[] = 'R2 Bucket: ' . ( $settings['r2_bucket'] ?? 'Not set' );
		$lines[] = 'R2 Credentials: ' . ( ! empty( $settings['r2_secret_access_key'] ) ? 'Configured' : 'Not configured' );
		$lines[] = 'CF API Token: ' . ( ! empty( $settings['cf_api_token'] ) ? 'Configured' : 'Not configured' );
		$lines[] = 'Auto Offload: ' . ( ! empty( $settings['auto_offload'] ) ? 'Enabled' : 'Disabled' );
		$lines[] = 'Batch Size: ' . ( $settings['batch_size'] ?? 25 );
		$lines[] = 'Keep Local Files: ' . ( ! empty( $settings['keep_local_files'] ) ? 'Yes' : 'No' );
		$lines[] = 'CDN Enabled: ' . ( ! empty( $settings['cdn_enabled'] ) ? 'Yes' : 'No' );
		$lines[] = 'CDN URL: ' . ( $settings['cdn_url'] ?? 'Not set' );
		$lines[] = 'Quality: ' . ( $settings['quality'] ?? 85 );
		$lines[] = 'Image Format: ' . ( $settings['image_format'] ?? 'webp' );
		$lines[] = 'Smart Sizes: ' . ( ! empty( $settings['smart_sizes'] ) ? 'Yes' : 'No' );
		$lines[] = 'Worker Deployed: ' . ( ! empty( $settings['worker_deployed'] ) ? 'Yes' : 'No' );
		$lines[] = 'Worker Name: ' . ( $settings['worker_name'] ?? 'Not set' );
		$lines[] = '';

		// Media Stats.
		$lines[]           = '## Media Statistics';
		$total_attachments = wp_count_posts( 'attachment' );
		$total_count       = $total_attachments->inherit ?? 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Debug info aggregation.
		$offloaded_count = $wpdb->get_var(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_cfr2_offloaded' AND meta_value = '1'"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom queue table.
		$pending_count = $wpdb->get_var(
			"SELECT COUNT(DISTINCT attachment_id) FROM {$wpdb->prefix}cfr2_offload_queue WHERE status IN ('pending', 'processing')"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom queue table.
		$failed_count = $wpdb->get_var(
			"SELECT COUNT(DISTINCT attachment_id) FROM {$wpdb->prefix}cfr2_offload_queue WHERE status = 'failed'"
		);

		$lines[] = 'Total Media: ' . $total_count;
		$lines[] = 'Offloaded: ' . $offloaded_count;
		$lines[] = 'Pending: ' . $pending_count;
		$lines[] = 'Failed (24h): ' . $failed_count;
		$lines[] = 'Local: ' . ( $total_count - $offloaded_count - $pending_count );
		$lines[] = '';

		// Database Tables.
		$lines[] = '## Database Tables';
		$tables  = array(
			$wpdb->prefix . 'cfr2_offload_status',
			$wpdb->prefix . 'cfr2_offload_queue',
			$wpdb->prefix . 'cfr2_stats',
		);
		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check.
			$exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
			$lines[] = basename( $table ) . ': ' . ( $exists ? 'Exists' : 'Missing' );
		}
		$lines[] = '';

		// Active Theme.
		$lines[]      = '## Active Theme';
		$theme        = wp_get_theme();
		$lines[]      = 'Name: ' . $theme->get( 'Name' );
		$lines[]      = 'Version: ' . $theme->get( 'Version' );
		$parent_theme = $theme->parent();
		if ( $parent_theme ) {
			$lines[] = 'Parent Theme: ' . $parent_theme->get( 'Name' ) . ' ' . $parent_theme->get( 'Version' );
		}
		$lines[] = '';

		// Active Plugins.
		$lines[]        = '## Active Plugins';
		$active_plugins = get_option( 'active_plugins', array() );
		foreach ( $active_plugins as $plugin ) {
			$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );
			$lines[]     = $plugin_data['Name'] . ' ' . $plugin_data['Version'];
		}
		$lines[] = '';

		$lines[] = '### End Debug Info ###';

		return implode( "\n", $lines );
	}
}
