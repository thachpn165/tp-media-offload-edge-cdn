<?php
/**
 * REST API Helper class.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Integrations;

defined( 'ABSPATH' ) || exit;

use ThachPN165\CFR2OffLoad\Traits\CredentialsHelperTrait;

/**
 * RestApiHelper class - helper methods for REST API.
 */
class RestApiHelper {

	use CredentialsHelperTrait;

	/**
	 * Validate attachment ID parameter.
	 *
	 * @param mixed $param Parameter value.
	 * @return bool True if valid.
	 */
	public static function validate_attachment_id( $param ): bool {
		return is_numeric( $param ) && $param > 0;
	}

	/**
	 * Verify attachment exists and is valid.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return \WP_Post|false Attachment post or false.
	 */
	public static function verify_attachment( int $attachment_id ) {
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return false;
		}
		return $attachment;
	}

	/**
	 * Get R2 credentials from settings.
	 *
	 * @return array R2 credentials.
	 */
	public static function get_credentials(): array {
		return self::get_r2_credentials();
	}

	/**
	 * Check read permission.
	 *
	 * @return bool True if has permission.
	 */
	public static function check_read_permission(): bool {
		return current_user_can( 'upload_files' );
	}

	/**
	 * Check attachment endpoint permission.
	 *
	 * @return bool True if has permission.
	 */
	public static function check_attachment_permission(): bool {
		$is_public = (bool) apply_filters( 'cfr2_rest_public_attachment_endpoint', false );
		if ( $is_public ) {
			return true;
		}

		return self::check_read_permission();
	}

	/**
	 * Check write permission.
	 *
	 * @return bool True if has permission.
	 */
	public static function check_write_permission(): bool {
		return current_user_can( 'upload_files' );
	}

	/**
	 * Check admin permission.
	 *
	 * @return bool True if has permission.
	 */
	public static function check_admin_permission(): bool {
		return current_user_can( 'manage_options' );
	}
}
