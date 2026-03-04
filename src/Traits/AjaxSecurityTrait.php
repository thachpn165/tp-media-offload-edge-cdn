<?php
/**
 * AJAX Security Trait.
 *
 * Provides shared AJAX security verification methods.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Traits;

defined( 'ABSPATH' ) || exit;

use ThachPN165\CFR2OffLoad\Constants\NonceActions;

/**
 * AjaxSecurityTrait - shared AJAX security verification.
 */
trait AjaxSecurityTrait {

	/**
	 * Verify nonce.
	 *
	 * @param string $action     Nonce action.
	 * @param string $param_name Nonce parameter name.
	 * @return bool True if verified, sends error otherwise.
	 */
	protected function verify_ajax_nonce(
		string $action = NonceActions::BULK,
		string $param_name = 'nonce'
	): bool {
		if ( ! check_ajax_referer( $action, $param_name, false ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Security check failed.', 'tp-media-offload-edge-cdn' ) ),
				403
			);
			return false;
		}

		return true;
	}

	/**
	 * Check manage_options capability.
	 *
	 * @return bool True if has permission, sends error otherwise.
	 */
	protected function verify_manage_options(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Permission denied.', 'tp-media-offload-edge-cdn' ) ),
				403
			);
			return false;
		}

		return true;
	}
}
