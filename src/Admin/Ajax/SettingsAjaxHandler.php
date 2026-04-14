<?php
/**
 * Settings AJAX Handler class.
 *
 * Handles AJAX requests for settings operations.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

use ThachPN165\CFR2OffLoad\Constants\NonceActions;
use ThachPN165\CFR2OffLoad\Constants\RateLimit;
use ThachPN165\CFR2OffLoad\Constants\Settings;
use ThachPN165\CFR2OffLoad\Constants\BatchConfig;
use ThachPN165\CFR2OffLoad\Constants\TransientKeys;
use ThachPN165\CFR2OffLoad\Services\EncryptionService;
use ThachPN165\CFR2OffLoad\Services\PluginSettings;
use ThachPN165\CFR2OffLoad\Services\R2Client;
use ThachPN165\CFR2OffLoad\Services\SettingsValidator;
use ThachPN165\CFR2OffLoad\Services\CloudflareAPI;
use ThachPN165\CFR2OffLoad\Services\URLRewriter;

/**
 * SettingsAjaxHandler class - handles settings-related AJAX requests.
 */
class SettingsAjaxHandler {

	/**
	 * Register AJAX hooks.
	 */
	public function register_hooks(): void {
		add_action( 'wp_ajax_cfr2_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_cfr2_test_r2', array( $this, 'ajax_test_r2_connection' ) );
		add_action( 'wp_ajax_cfr2_validate_cdn_dns', array( $this, 'ajax_validate_cdn_dns' ) );
		add_action( 'wp_ajax_cfr2_enable_dns_proxy', array( $this, 'ajax_enable_dns_proxy' ) );
	}

	/**
	 * Verify settings nonce.
	 *
	 * @param string $field Nonce request field name.
	 * @return bool
	 */
	private function verify_settings_nonce( string $field = 'cfr2_nonce' ): bool {
		if ( ! check_ajax_referer( NonceActions::SETTINGS, $field, false ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Security check failed.', 'tp-media-offload-edge-cdn' ) ),
				403
			);
			return false;
		}

		return true;
	}

	/**
	 * Check current user capability for settings actions.
	 *
	 * @return bool
	 */
	private function check_settings_permissions(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Permission denied.', 'tp-media-offload-edge-cdn' ) ),
				403
			);
			return false;
		}

		return true;
	}

	/**
	 * Handle AJAX settings save.
	 */
	public function ajax_save_settings(): void {
		if ( ! $this->verify_settings_nonce() || ! $this->check_settings_permissions() ) {
			return;
		}

		// Rate limiting - prevent spam/DoS.
		$user_id    = get_current_user_id();
		$rate_key   = TransientKeys::RATE_PREFIX . $user_id;
		$save_count = get_transient( $rate_key );

		if ( false !== $save_count && (int) $save_count >= RateLimit::MAX_SAVES ) {
			wp_send_json_error(
				array( 'message' => __( 'Too many requests. Please try again later.', 'tp-media-offload-edge-cdn' ) ),
				429
			);
			return;
		}

		// Increment rate limit counter.
		set_transient( $rate_key, ( $save_count ? (int) $save_count + 1 : 1 ), RateLimit::WINDOW_SEC );

		// Get and sanitize form data.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$input = array(
			'r2_account_id'        => sanitize_text_field( wp_unslash( $_POST['r2_account_id'] ?? '' ) ),
			'r2_access_key_id'     => sanitize_text_field( wp_unslash( $_POST['r2_access_key_id'] ?? '' ) ),
			'r2_secret_access_key' => sanitize_text_field( wp_unslash( $_POST['r2_secret_access_key'] ?? '' ) ),
			'r2_bucket'            => sanitize_text_field( wp_unslash( $_POST['r2_bucket'] ?? '' ) ),
			'r2_public_domain'     => esc_url_raw( wp_unslash( $_POST['r2_public_domain'] ?? '' ) ),
			'auto_offload'         => ! empty( $_POST['auto_offload'] ) ? 1 : 0,
			'batch_size'           => absint( $_POST['batch_size'] ?? BatchConfig::DEFAULT_SIZE ),
			'keep_local_files'     => ! empty( $_POST['keep_local_files'] ) ? 1 : 0,
			'sync_delete'          => ! empty( $_POST['sync_delete'] ) ? 1 : 0,
			'cdn_enabled'          => ! empty( $_POST['cdn_enabled'] ) ? 1 : 0,
			'cdn_url'              => esc_url_raw( wp_unslash( $_POST['cdn_url'] ?? '' ) ),
			'quality'              => absint( $_POST['quality'] ?? 85 ),
			'image_format'         => sanitize_text_field( wp_unslash( $_POST['image_format'] ?? 'webp' ) ),
			'smart_sizes'          => ! empty( $_POST['smart_sizes'] ) ? 1 : 0,
			'content_max_width'    => absint( $_POST['content_max_width'] ?? 800 ),
			'cf_api_token'         => sanitize_text_field( wp_unslash( $_POST['cf_api_token'] ?? '' ) ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$existing = PluginSettings::get();

		// Sanitize settings.
		$sanitized = $this->sanitize_settings( $input );

		// Update option.
		$updated = update_option( Settings::OPTION_KEY, $sanitized );

		if ( $existing['cdn_url'] !== $sanitized['cdn_url'] || $existing['cdn_enabled'] !== $sanitized['cdn_enabled'] ) {
			URLRewriter::clear_availability_cache( (string) $existing['cdn_url'] );
			URLRewriter::clear_availability_cache( (string) $sanitized['cdn_url'] );
		}

		if ( false === $updated ) {
			// Check if genuinely unchanged (use strict comparison).
			$current = get_option( Settings::OPTION_KEY );

			if ( false !== $current && $current === $sanitized ) {
				wp_send_json_success(
					array( 'message' => __( 'No changes detected.', 'tp-media-offload-edge-cdn' ) )
				);
				return;
			}

			wp_send_json_error(
				array( 'message' => __( 'Failed to save settings. Please try again.', 'tp-media-offload-edge-cdn' ) ),
				500
			);
			return;
		}

		wp_send_json_success(
			array( 'message' => __( 'Settings saved.', 'tp-media-offload-edge-cdn' ) )
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Input data.
	 * @return array Sanitized data.
	 */
	public function sanitize_settings( array $input ): array {
		$sanitized = array();
		$existing  = PluginSettings::get();

		// R2 Credentials - sanitize and encrypt.
		$sanitized['r2_account_id']    = preg_replace( '/[^a-zA-Z0-9]/', '', $input['r2_account_id'] ?? '' );
		$sanitized['r2_access_key_id'] = sanitize_text_field( $input['r2_access_key_id'] ?? '' );

		// R2 Secret Access Key - only encrypt if not placeholder.
		$secret = $input['r2_secret_access_key'] ?? '';
		if ( ! empty( $secret ) && '********' !== $secret ) {
			$encryption                        = EncryptionService::get_instance();
			$sanitized['r2_secret_access_key'] = $encryption->encrypt( $secret );
		} else {
			$sanitized['r2_secret_access_key'] = $existing['r2_secret_access_key'] ?? '';
		}

		// R2 Bucket - lowercase alphanumeric + hyphens only.
		$sanitized['r2_bucket'] = preg_replace( '/[^a-z0-9\-]/', '', strtolower( $input['r2_bucket'] ?? '' ) );

		// R2 Public Domain - custom domain for public access.
		$sanitized['r2_public_domain'] = esc_url_raw( $input['r2_public_domain'] ?? '' );

		// Offload settings.
		$sanitized['auto_offload']     = ! empty( $input['auto_offload'] ) ? 1 : 0;
		$batch_size                    = absint( $input['batch_size'] ?? BatchConfig::DEFAULT_SIZE );
		$sanitized['batch_size']       = max( BatchConfig::MIN_SIZE, min( $batch_size, BatchConfig::MAX_SIZE ) );
		$sanitized['keep_local_files'] = ! empty( $input['keep_local_files'] ) ? 1 : 0;
		$sanitized['sync_delete']      = ! empty( $input['sync_delete'] ) ? 1 : 0;

		// CDN settings.
		$sanitized['cdn_enabled'] = ! empty( $input['cdn_enabled'] ) ? 1 : 0;
		$sanitized['cdn_url']     = $this->sanitize_url_field( $input['cdn_url'] ?? '' );

		// Quality: 1-100.
		$quality              = absint( $input['quality'] ?? 85 );
		$sanitized['quality'] = max( 1, min( $quality, 100 ) );

		// Image format: original, webp, or avif.
		$allowed_formats           = array( 'original', 'webp', 'avif' );
		$image_format              = $input['image_format'] ?? 'webp';
		$sanitized['image_format'] = in_array( $image_format, $allowed_formats, true ) ? $image_format : 'webp';

		// Smart sizes settings.
		$sanitized['smart_sizes']       = ! empty( $input['smart_sizes'] ) ? 1 : 0;
		$content_max_width              = absint( $input['content_max_width'] ?? 800 );
		$sanitized['content_max_width'] = max( 320, min( $content_max_width, 1920 ) );

		// Cloudflare API Token - only encrypt if not placeholder.
		$cf_token = $input['cf_api_token'] ?? '';
		if ( ! empty( $cf_token ) && '********' !== $cf_token ) {
			$encryption                = EncryptionService::get_instance();
			$sanitized['cf_api_token'] = $encryption->encrypt( $cf_token );
		} else {
			$sanitized['cf_api_token'] = $existing['cf_api_token'] ?? '';
		}

		// Worker deployment internal fields (preserve).
		$sanitized['worker_deployed']    = $existing['worker_deployed'] ?? false;
		$sanitized['worker_name']        = $existing['worker_name'] ?? '';
		$sanitized['worker_deployed_at'] = $existing['worker_deployed_at'] ?? '';

		return $sanitized;
	}

	/**
	 * Sanitize URL field - removes trailing slash and validates.
	 *
	 * @param string $url URL to sanitize.
	 * @return string Sanitized URL.
	 */
	private function sanitize_url_field( string $url ): string {
		$url = esc_url_raw( $url );
		return rtrim( $url, '/' );
	}

	/**
	 * Test R2 connection via AJAX.
	 */
	public function ajax_test_r2_connection(): void {
		if ( ! $this->verify_settings_nonce() || ! $this->check_settings_permissions() ) {
			return;
		}

		// Rate limiting - 5 attempts per minute.
		$user_id  = get_current_user_id();
		$rate_key = TransientKeys::R2_TEST_PREFIX . $user_id;
		$count    = get_transient( $rate_key );

		if ( false !== $count && (int) $count >= 5 ) {
			wp_send_json_error(
				array( 'message' => __( 'Too many attempts. Wait 60 seconds.', 'tp-media-offload-edge-cdn' ) ),
				429
			);
			return;
		}
		set_transient( $rate_key, ( $count ? (int) $count + 1 : 1 ), 60 );

		// Get credentials from form values (for testing before save).
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$account_id    = sanitize_text_field( wp_unslash( $_POST['r2_account_id'] ?? '' ) );
		$access_key_id = sanitize_text_field( wp_unslash( $_POST['r2_access_key_id'] ?? '' ) );
		$secret_key    = sanitize_text_field( wp_unslash( $_POST['r2_secret_access_key'] ?? '' ) );
		$bucket        = sanitize_text_field( wp_unslash( $_POST['r2_bucket'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// If secret is placeholder, get from saved settings.
		if ( '********' === $secret_key || empty( $secret_key ) ) {
			$settings   = PluginSettings::get();
			$encryption = EncryptionService::get_instance();
			$secret_key = $encryption->decrypt( $settings['r2_secret_access_key'] ?? '' );
		}

		$credentials = array(
			'account_id'        => $account_id,
			'access_key_id'     => $access_key_id,
			'secret_access_key' => $secret_key,
			'bucket'            => $bucket,
		);

		$error_message = SettingsValidator::validate_r2_credentials( $credentials );
		if ( null !== $error_message ) {
			wp_send_json_error( array( 'message' => $error_message ) );
			return;
		}

		// Test connection.
		$r2     = new R2Client( $credentials );
		$result = $r2->test_connection();

		if ( $result['success'] ) {
			wp_send_json_success(
				array( 'message' => __( 'Connection successful!', 'tp-media-offload-edge-cdn' ) )
			);
			return;
		}

		wp_send_json_error( array( 'message' => $result['message'] ) );
	}

	/**
	 * AJAX handler for validate CDN DNS.
	 */
	public function ajax_validate_cdn_dns(): void {
		if ( ! $this->verify_settings_nonce( 'nonce' ) || ! $this->check_settings_permissions() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$cdn_url = isset( $_POST['cdn_url'] ) ? esc_url_raw( wp_unslash( $_POST['cdn_url'] ) ) : '';

		if ( empty( $cdn_url ) ) {
			wp_send_json_error( array( 'message' => __( 'CDN URL is required.', 'tp-media-offload-edge-cdn' ) ) );
			return;
		}

		$settings      = PluginSettings::get();
		$error_message = SettingsValidator::validate_cloudflare_settings( $settings );

		if ( null !== $error_message ) {
			wp_send_json_error( array( 'message' => $error_message ) );
			return;
		}

		$encryption = EncryptionService::get_instance();
		$api_token  = $encryption->decrypt( $settings['cf_api_token'] );

		$api    = new CloudflareAPI( $api_token, $settings['r2_account_id'] );
		$result = $api->validate_cdn_dns( $cdn_url );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
			return;
		}

		wp_send_json_error( $result );
	}

	/**
	 * AJAX handler for enable DNS proxy.
	 */
	public function ajax_enable_dns_proxy(): void {
		if ( ! $this->verify_settings_nonce( 'nonce' ) || ! $this->check_settings_permissions() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$zone_id   = isset( $_POST['zone_id'] ) ? sanitize_text_field( wp_unslash( $_POST['zone_id'] ) ) : '';
		$record_id = isset( $_POST['record_id'] ) ? sanitize_text_field( wp_unslash( $_POST['record_id'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( empty( $zone_id ) || empty( $record_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing zone or record ID.', 'tp-media-offload-edge-cdn' ) ) );
			return;
		}

		$settings      = PluginSettings::get();
		$error_message = SettingsValidator::validate_cloudflare_settings( $settings );

		if ( null !== $error_message ) {
			wp_send_json_error( array( 'message' => $error_message ) );
			return;
		}

		$encryption = EncryptionService::get_instance();
		$api_token  = $encryption->decrypt( $settings['cf_api_token'] ?? '' );

		$api    = new CloudflareAPI( $api_token, $settings['r2_account_id'] ?? '' );
		$result = $api->enable_dns_proxy( $zone_id, $record_id );

		if ( $result['success'] ) {
			wp_send_json_success( array( 'message' => __( 'Proxy enabled successfully!', 'tp-media-offload-edge-cdn' ) ) );
			return;
		}

		wp_send_json_error( array( 'message' => $result['errors'][0]['message'] ?? __( 'Failed to enable proxy.', 'tp-media-offload-edge-cdn' ) ) );
	}
}
