<?php
/**
 * Credentials Helper Trait.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Traits;

defined( 'ABSPATH' ) || exit;

use ThachPN165\CFR2OffLoad\Services\EncryptionService;

/**
 * Provides shared R2 credentials retrieval logic.
 */
trait CredentialsHelperTrait {

	/**
	 * Get R2 credentials from settings.
	 *
	 * @param array|null $settings Optional settings array.
	 * @return array R2 credentials array.
	 */
	protected static function get_r2_credentials( ?array $settings = null ): array {
		if ( null === $settings ) {
			$settings = get_option( 'cfr2_settings', array() );
		}

		$encryption = EncryptionService::get_instance();

		return array(
			'account_id'        => $settings['r2_account_id'] ?? '',
			'access_key_id'     => $settings['r2_access_key_id'] ?? '',
			'secret_access_key' => $encryption->decrypt( $settings['r2_secret_access_key'] ?? '' ),
			'bucket'            => $settings['r2_bucket'] ?? '',
		);
	}

	/**
	 * Get decrypted Cloudflare API token.
	 *
	 * @param array|null $settings Optional settings array.
	 * @return string Decrypted API token or empty string.
	 */
	protected static function get_cf_api_token( ?array $settings = null ): string {
		if ( null === $settings ) {
			$settings = get_option( 'cfr2_settings', array() );
		}

		$encryption = EncryptionService::get_instance();

		return $encryption->decrypt( $settings['cf_api_token'] ?? '' );
	}
}
