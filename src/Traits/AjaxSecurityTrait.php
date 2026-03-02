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
	 * Verify nonce with fallback to legacy.
	 *
	 * @param string $legacy_action Legacy nonce action.
	 * @param string $new_action    New nonce action.
	 * @param string $param_name    Nonce parameter name.
	 * @return bool True if verified, sends error and exits otherwise.
	 */
	protected function verify_ajax_nonce(
		string $legacy_action = NonceActions::LEGACY,
		string $new_action = NonceActions::BULK,
		string $param_name = 'nonce'
	): bool {
		$is_valid = check_ajax_referer( $legacy_action, $param_name, false )
			|| check_ajax_referer( $new_action, 'cfr2_nonce', false );

		if ( ! $is_valid ) {
			wp_send_json_error(
				array( 'message' => __( 'Security check failed.', 'cf-r2-offload-cdn' ) ),
				403
			);
		}

		return $is_valid;
	}

	/**
	 * Check manage_options capability.
	 *
	 * @return bool True if has permission, sends error otherwise.
	 */
	protected function verify_manage_options(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Permission denied.', 'cf-r2-offload-cdn' ) ),
				403
			);
			return false;
		}

		return true;
	}
}
