<?php
/**
 * Deactivation Handler class.
 *
 * Adds confirmation dialog when deactivating the plugin.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Admin;

defined( 'ABSPATH' ) || exit;

use ThachPN165\CFR2OffLoad\Database\Schema;
use ThachPN165\CFR2OffLoad\Interfaces\HookableInterface;

/**
 * DeactivationHandler class - handles deactivation with cleanup option.
 */
class DeactivationHandler implements HookableInterface {

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_deactivation_dialog_script' ) );
		add_action( 'wp_ajax_cfr2_cleanup_data', array( $this, 'ajax_cleanup_data' ) );
	}

	/**
	 * Enqueue deactivation dialog JavaScript.
	 *
	 * @param string $hook Current admin hook suffix.
	 */
	public function enqueue_deactivation_dialog_script( string $hook ): void {
		if ( 'plugins.php' !== $hook ) {
			return;
		}

		$config = array(
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'pluginFile'     => \CFR2_BASENAME,
			'cleanupNonce'   => wp_create_nonce( 'cfr2_cleanup_nonce' ),
			'confirmMessage' => __(
				"Do you want to delete all plugin data?\n\n- Click OK to delete all settings, database tables, and media metadata\n- Click Cancel to keep data (you can reinstall later)\n\nNote: Files on R2 storage will not be deleted.",
				'cf-r2-offload-cdn'
			),
		);

		$script  = "jQuery(function($) {\n";
		$script .= "\tconst config = " . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ";\n";
		$script .= "\tconst pluginRow = $('tr[data-plugin=\"' + config.pluginFile + '\"]');\n";
		$script .= "\tconst deactivateLink = pluginRow.find('.deactivate a');\n";
		$script .= "\tconst originalHref = deactivateLink.attr('href');\n";
		$script .= "\n";
		$script .= "\tif (!deactivateLink.length || !originalHref) {\n";
		$script .= "\t\treturn;\n";
		$script .= "\t}\n";
		$script .= "\n";
		$script .= "\tdeactivateLink.on('click', function(event) {\n";
		$script .= "\t\tevent.preventDefault();\n";
		$script .= "\n";
		$script .= "\t\tif (!window.confirm(config.confirmMessage)) {\n";
		$script .= "\t\t\twindow.location.href = originalHref;\n";
		$script .= "\t\t\treturn;\n";
		$script .= "\t\t}\n";
		$script .= "\n";
		$script .= "\t\t$.ajax({\n";
		$script .= "\t\t\turl: config.ajaxUrl,\n";
		$script .= "\t\t\ttype: 'POST',\n";
		$script .= "\t\t\tdata: {\n";
		$script .= "\t\t\t\taction: 'cfr2_cleanup_data',\n";
		$script .= "\t\t\t\tnonce: config.cleanupNonce\n";
		$script .= "\t\t\t}\n";
		$script .= "\t\t}).always(function() {\n";
		$script .= "\t\t\twindow.location.href = originalHref;\n";
		$script .= "\t\t});\n";
		$script .= "\t});\n";
		$script .= "});";

		wp_enqueue_script( 'jquery' );
		wp_add_inline_script( 'jquery', $script, 'after' );
	}

	/**
	 * AJAX handler to cleanup all plugin data.
	 */
	public function ajax_cleanup_data(): void {
		check_ajax_referer( 'cfr2_cleanup_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Permission denied.', 'cf-r2-offload-cdn' ) ),
				403
			);
		}

		global $wpdb;

		// 1. Securely wipe sensitive data.
		$settings = get_option( 'cloudflare_r2_offload_cdn_settings', array() );
		if ( ! empty( $settings['r2_secret_access_key'] ) ) {
			$settings['r2_secret_access_key'] = str_repeat( '0', strlen( $settings['r2_secret_access_key'] ) );
		}
		if ( ! empty( $settings['api_key'] ) ) {
			$settings['api_key'] = str_repeat( '0', strlen( $settings['api_key'] ) );
		}

		// 2. Remove plugin options.
		delete_option( 'cloudflare_r2_offload_cdn_settings' );
		delete_option( 'cfr2_db_version' );

		// 3. Drop custom database tables.
		Schema::drop_tables();

		// 4. Remove all post meta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
				'_cfr2_%'
			)
		);

		// 5. Clean up transients.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_cfr2_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_cfr2_' ) . '%'
			)
		);

		// 6. Clear scheduled cron events.
		wp_clear_scheduled_hook( 'cfr2_process_queue' );

		wp_send_json_success(
			array( 'message' => __( 'Data cleaned.', 'cf-r2-offload-cdn' ) )
		);
	}
}
