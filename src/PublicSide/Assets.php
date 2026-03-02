<?php
/**
 * Assets class.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\PublicSide;

defined( 'ABSPATH' ) || exit;

use ThachPN165\CFR2OffLoad\Interfaces\HookableInterface;

/**
 * Assets class - handles script and style enqueuing.
 */
class Assets implements HookableInterface {

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_assets' ) );
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		$is_plugin_page = strpos( $hook, 'cf-r2-offload-cdn' ) !== false;
		$is_media_page  = in_array( $hook, array( 'upload.php', 'post.php' ), true );

		// Check if editing an attachment.
		if ( 'post.php' === $hook ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
			if ( $post_id && 'attachment' !== get_post_type( $post_id ) ) {
				$is_media_page = false;
			}
		}

		// Only load on plugin pages or media pages.
		if ( ! $is_plugin_page && ! $is_media_page ) {
			return;
		}

		wp_enqueue_style(
			'cloudflare-r2-offload-cdn-admin',
			\CFR2_URL . 'assets/css/admin.css',
			array(),
			\CFR2_VERSION
		);

		wp_enqueue_script(
			'cloudflare-r2-offload-cdn-admin',
			\CFR2_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			\CFR2_VERSION,
			true
		);

		wp_localize_script(
			'cloudflare-r2-offload-cdn-admin',
			'myPluginAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'cloudflare_r2_offload_cdn_save_settings' ),
				'strings' => array(
					'confirm' => __( 'Are you sure?', 'cf-r2-offload-cdn' ),
					'saving'  => __( 'Saving...', 'cf-r2-offload-cdn' ),
					'saved'   => __( 'Settings saved.', 'cf-r2-offload-cdn' ),
					'error'   => __( 'An error occurred. Please try again.', 'cf-r2-offload-cdn' ),
				),
			)
		);
	}

	/**
	 * Enqueue public assets.
	 */
	public function enqueue_public_assets(): void {
		// Check if should load public assets.
		$settings = get_option( 'cloudflare_r2_offload_cdn_settings', array() );
		if ( empty( $settings['enable_feature'] ) ) {
			return;
		}

		wp_enqueue_style(
			'cloudflare-r2-offload-cdn-public',
			\CFR2_URL . 'assets/css/public.css',
			array(),
			\CFR2_VERSION
		);

		wp_enqueue_script(
			'cloudflare-r2-offload-cdn-public',
			\CFR2_URL . 'assets/js/public.js',
			array(),
			\CFR2_VERSION,
			true
		);
	}
}
