<?php
/**
 * Settings Page class.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Admin;

use ThachPN165\CFR2OffLoad\Admin\Tabs\DashboardTab;
use ThachPN165\CFR2OffLoad\Admin\Tabs\StorageTab;
use ThachPN165\CFR2OffLoad\Admin\Tabs\CdnTab;
use ThachPN165\CFR2OffLoad\Admin\Tabs\OffloadTab;
use ThachPN165\CFR2OffLoad\Admin\Tabs\BulkActionsTab;
use ThachPN165\CFR2OffLoad\Admin\Tabs\SystemInfoTab;

defined( 'ABSPATH' ) || exit;

/**
 * SettingsPage class - renders the settings page with tabbed layout.
 */
class SettingsPage {

	/**
	 * Render the settings page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'thachpham-offload-cdn-cloudflare-r2' ) );
		}

		// Add frame-busting headers to prevent clickjacking.
		if ( ! headers_sent() ) {
			header( 'X-Frame-Options: SAMEORIGIN' );
			header( 'Content-Security-Policy: frame-ancestors \'self\'' );
		}

		$settings = get_option( 'cloudflare_r2_offload_cdn_settings', array() );
		$tabs     = self::get_tabs();
		?>
		<div class="wrap cloudflare-r2-offload-cdn-settings-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<!-- Toast notification container -->
			<div class="cloudflare-r2-offload-cdn-toast" id="cloudflare-r2-offload-cdn-toast"></div>

			<div class="cloudflare-r2-offload-cdn-settings-container">
				<?php self::render_sidebar( $tabs ); ?>
				<?php self::render_content( $settings ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Get tabs configuration.
	 *
	 * @return array Tabs configuration.
	 */
	private static function get_tabs(): array {
		return array(
			'dashboard'    => array(
				'label' => __( 'Dashboard', 'thachpham-offload-cdn-cloudflare-r2' ),
				'icon'  => 'dashicons-dashboard',
			),
			'storage'      => array(
				'label' => __( 'Storage', 'thachpham-offload-cdn-cloudflare-r2' ),
				'icon'  => 'dashicons-cloud-saved',
			),
			'cdn'          => array(
				'label' => __( 'CDN', 'thachpham-offload-cdn-cloudflare-r2' ),
				'icon'  => 'dashicons-performance',
			),
			'offload'      => array(
				'label' => __( 'Offload', 'thachpham-offload-cdn-cloudflare-r2' ),
				'icon'  => 'dashicons-upload',
			),
			'bulk-actions' => array(
				'label' => __( 'Bulk Actions', 'thachpham-offload-cdn-cloudflare-r2' ),
				'icon'  => 'dashicons-update',
			),
			'system-info'  => array(
				'label' => __( 'System Info', 'thachpham-offload-cdn-cloudflare-r2' ),
				'icon'  => 'dashicons-info',
			),
		);
	}

	/**
	 * Render sidebar with tabs.
	 *
	 * @param array $tabs Tabs configuration.
	 */
	private static function render_sidebar( array $tabs ): void {
		?>
		<div class="cloudflare-r2-offload-cdn-sidebar">
			<ul class="cloudflare-r2-offload-cdn-tabs">
				<?php
				$first = true;
				foreach ( $tabs as $tab_id => $tab ) :
					?>
					<li data-tab="<?php echo esc_attr( $tab_id ); ?>" class="<?php echo $first ? 'active' : ''; ?>">
						<span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>"></span>
						<?php echo esc_html( $tab['label'] ); ?>
					</li>
					<?php
					$first = false;
				endforeach;
				?>
			</ul>

			<div class="cfr2-sidebar-footer">
				<a href="https://thachpham.com/contact" target="_blank" rel="noopener" class="button cfr2-support-btn">
					<span class="dashicons dashicons-sos"></span>
					<?php esc_html_e( 'Get Support', 'thachpham-offload-cdn-cloudflare-r2' ); ?>
				</a>
				<div class="cfr2-plugin-info">
					<span class="cfr2-version">v<?php echo esc_html( \CFR2_VERSION ); ?></span>
					<span class="cfr2-author"><?php esc_html_e( 'by', 'thachpham-offload-cdn-cloudflare-r2' ); ?> <a href="https://thachpham.com" target="_blank" rel="noopener">ThachPham</a></span>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render content area with tab panels.
	 *
	 * @param array $settings Current settings.
	 */
	private static function render_content( array $settings ): void {
		?>
		<div class="cloudflare-r2-offload-cdn-content">
			<?php DashboardTab::render(); ?>

			<form id="cloudflare-r2-offload-cdn-settings-form">
				<?php wp_nonce_field( 'cloudflare_r2_offload_cdn_save_settings', 'cloudflare_r2_offload_cdn_nonce' ); ?>

				<?php StorageTab::render( $settings ); ?>
				<?php CdnTab::render( $settings ); ?>
				<?php OffloadTab::render( $settings ); ?>
				<?php BulkActionsTab::render(); ?>
				<?php SystemInfoTab::render(); ?>

				<div class="cloudflare-r2-offload-cdn-form-actions">
					<button type="submit" class="button button-primary cloudflare-r2-offload-cdn-save-btn">
						<span class="cloudflare-r2-offload-cdn-save-text"><?php esc_html_e( 'Save Settings', 'thachpham-offload-cdn-cloudflare-r2' ); ?></span>
						<span class="cloudflare-r2-offload-cdn-save-loading spinner"></span>
					</button>
				</div>
			</form>

			<div class="cloudflare-r2-offload-cdn-disclaimer" style="margin-top: 30px; padding: 15px; background: #f9f9f9; border-left: 4px solid #ccc; color: #666; font-size: 12px;">
				<p style="margin: 0;">
					<strong><?php esc_html_e( 'Disclaimer:', 'thachpham-offload-cdn-cloudflare-r2' ); ?></strong>
					<?php esc_html_e( 'This plugin is an independent, third-party project and is not affiliated with, endorsed by, or officially associated with Cloudflare, Inc. "Cloudflare" and "R2" are trademarks of Cloudflare, Inc. The use of these names is solely for descriptive purposes to indicate compatibility with Cloudflare services.', 'thachpham-offload-cdn-cloudflare-r2' ); ?>
				</p>
			</div>
		</div>
		<?php
	}
}
