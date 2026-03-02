<?php
/**
 * Worker AJAX Handler class.
 *
 * Handles AJAX requests for Cloudflare Worker operations.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

use ThachPN165\CFR2OffLoad\Constants\NonceActions;
use ThachPN165\CFR2OffLoad\Constants\Settings;
use ThachPN165\CFR2OffLoad\Services\EncryptionService;
use ThachPN165\CFR2OffLoad\Services\CloudflareAPI;
use ThachPN165\CFR2OffLoad\Services\WorkerDeployer;

/**
 * WorkerAjaxHandler class - handles Worker-related AJAX requests.
 */
class WorkerAjaxHandler {

	/**
	 * Register AJAX hooks.
	 */
	public function register_hooks(): void {
		add_action( 'wp_ajax_cfr2_deploy_worker', array( $this, 'ajax_deploy_worker' ) );
		add_action( 'wp_ajax_cfr2_remove_worker', array( $this, 'ajax_remove_worker' ) );
		add_action( 'wp_ajax_cfr2_worker_status', array( $this, 'ajax_worker_status' ) );
	}

	/**
	 * Verify nonce for worker operations.
	 *
	 * @return bool True if valid, sends error response otherwise.
	 */
	private function verify_worker_nonce(): bool {
		// Support both legacy and new nonces during transition.
		$nonce_valid = check_ajax_referer( NonceActions::LEGACY, 'nonce', false );
		if ( false === $nonce_valid ) {
			$nonce_valid = check_ajax_referer( NonceActions::WORKER, 'cfr2_nonce', false );
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
	 * AJAX handler for deploy worker.
	 */
	public function ajax_deploy_worker(): void {
		$this->verify_worker_nonce();
		$this->check_permissions();

		$settings = get_option( Settings::OPTION_KEY, array() );

		// Validate required fields.
		if ( empty( $settings['cf_api_token'] ) || empty( $settings['r2_account_id'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing Cloudflare API Token or Account ID.', 'cf-r2-offload-cdn' ) ) );
		}

		// Validate R2 bucket is configured.
		if ( empty( $settings['r2_bucket'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'R2 Bucket name is required. Please configure it in the Storage tab first.', 'cf-r2-offload-cdn' ),
				)
			);
		}

		// Decrypt API token.
		$encryption = EncryptionService::get_instance();
		$api_token  = $encryption->decrypt( $settings['cf_api_token'] );

		// Initialize services.
		$api      = new CloudflareAPI( $api_token, $settings['r2_account_id'] );
		$deployer = new WorkerDeployer( $api );

		// Deploy with R2 bucket binding (direct access).
		$result = $deployer->deploy(
			array(
				'r2_bucket'     => $settings['r2_bucket'],
				'custom_domain' => $settings['cdn_url'] ?? '',
				'image_format'  => $settings['image_format'] ?? 'webp',
			)
		);

		if ( $result['success'] ) {
			// Save deployment info.
			$settings['worker_deployed']    = true;
			$settings['worker_name']        = $result['worker_name'];
			$settings['worker_deployed_at'] = current_time( 'mysql' );
			update_option( Settings::OPTION_KEY, $settings );

			wp_send_json_success(
				array(
					'message'  => __( 'Worker deployed successfully!', 'cf-r2-offload-cdn' ),
					'steps'    => $result['steps'],
					'warnings' => $result['warnings'] ?? array(),
				)
			);
		} else {
			wp_send_json_error(
				array(
					'message'  => $result['message'],
					'steps'    => $result['steps'],
					'warnings' => $result['warnings'] ?? array(),
				)
			);
		}
	}

	/**
	 * AJAX handler for remove worker.
	 */
	public function ajax_remove_worker(): void {
		$this->verify_worker_nonce();
		$this->check_permissions();

		$settings = get_option( Settings::OPTION_KEY, array() );

		$encryption = EncryptionService::get_instance();
		$api_token  = $encryption->decrypt( $settings['cf_api_token'] ?? '' );

		$api      = new CloudflareAPI( $api_token, $settings['r2_account_id'] ?? '' );
		$deployer = new WorkerDeployer( $api );

		$result = $deployer->undeploy();

		if ( $result['success'] ) {
			$settings['worker_deployed'] = false;
			unset( $settings['worker_name'], $settings['worker_deployed_at'] );
			update_option( Settings::OPTION_KEY, $settings );

			wp_send_json_success( array( 'message' => __( 'Worker removed.', 'cf-r2-offload-cdn' ) ) );
		} else {
			wp_send_json_error( array( 'message' => $result['errors'][0]['message'] ?? 'Unknown error' ) );
		}
	}

	/**
	 * AJAX handler for worker status.
	 */
	public function ajax_worker_status(): void {
		$this->verify_worker_nonce();
		$this->check_permissions();

		$settings = get_option( Settings::OPTION_KEY, array() );

		if ( empty( $settings['worker_deployed'] ) ) {
			wp_send_json_success( array( 'deployed' => false ) );
			return;
		}

		$encryption = EncryptionService::get_instance();
		$api_token  = $encryption->decrypt( $settings['cf_api_token'] ?? '' );

		$api      = new CloudflareAPI( $api_token, $settings['r2_account_id'] ?? '' );
		$deployer = new WorkerDeployer( $api );

		$status = $deployer->get_status();

		wp_send_json_success(
			array(
				'deployed'    => true,
				'worker_name' => $settings['worker_name'] ?? '',
				'deployed_at' => $settings['worker_deployed_at'] ?? '',
				'status'      => $status,
			)
		);
	}
}
