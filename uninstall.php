<?php
/**
 * Uninstall script.
 *
 * Fired when the plugin is deleted (not deactivated).
 * Removes all plugin data: options, tables, post meta, transients.
 *
 * @package CFR2OffLoad
 */

// Exit if not called by WordPress.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// 1. Securely wipe sensitive data before deletion.
$cfr2_settings = get_option( 'cloudflare_r2_offload_cdn_settings', array() );
if ( ! empty( $cfr2_settings['r2_secret_access_key'] ) ) {
	$cfr2_settings['r2_secret_access_key'] = str_repeat( '0', strlen( $cfr2_settings['r2_secret_access_key'] ) );
}
if ( ! empty( $cfr2_settings['api_key'] ) ) {
	$cfr2_settings['api_key'] = str_repeat( '0', strlen( $cfr2_settings['api_key'] ) );
}
update_option( 'cloudflare_r2_offload_cdn_settings', $cfr2_settings );

// 2. Remove plugin options.
delete_option( 'cloudflare_r2_offload_cdn_settings' );
delete_option( 'cfr2_db_version' );

// 3. Drop custom database tables.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cfr2_offload_status" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cfr2_offload_queue" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cfr2_stats" );
// phpcs:enable

// 4. Remove all post meta created by this plugin.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
		'_cfr2_%'
	)
);

// 5. Clean up all plugin transients.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_cfr2_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_cfr2_' ) . '%'
	)
);

// 6. Clear any scheduled cron events.
wp_clear_scheduled_hook( 'cfr2_process_queue' );
