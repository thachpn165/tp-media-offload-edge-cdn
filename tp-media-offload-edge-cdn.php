<?php
/**
 * Plugin Name: TP Media Offload & Edge CDN
 * Plugin URI:  https://wordpress.org/plugins/tp-media-offload-edge-cdn/
 * Description: Offload WordPress media to Cloudflare R2 storage and serve via CDN with automatic image optimization (WebP/AVIF, responsive sizes, quality control).
 * Version:           1.0.0
 * Author:      TP
 * Author URI:  https://profiles.wordpress.org/thachpn165/
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: tp-media-offload-edge-cdn
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 *
 * @package CFR2OffLoad
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'CFR2_VERSION', '1.0.0' );
define( 'CFR2_FILE', __FILE__ );
define( 'CFR2_PATH', plugin_dir_path( __FILE__ ) );
define( 'CFR2_URL', plugin_dir_url( __FILE__ ) );
define( 'CFR2_BASENAME', plugin_basename( __FILE__ ) );

// Composer autoload.
if ( file_exists( CFR2_PATH . 'vendor/autoload.php' ) ) {
	require_once CFR2_PATH . 'vendor/autoload.php';
}

// Initialize plugin.
add_action(
	'plugins_loaded',
	function () {
		\ThachPN165\CFR2OffLoad\Plugin::instance();
	}
);

// Activation/Deactivation hooks.
register_activation_hook( __FILE__, array( \ThachPN165\CFR2OffLoad\Core\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \ThachPN165\CFR2OffLoad\Core\Deactivator::class, 'deactivate' ) );

// Register WP-CLI commands.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'cfr2', \ThachPN165\CFR2OffLoad\CLI\Commands::class );
}
