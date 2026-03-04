<?php
/**
 * Nonce Actions Constants.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Constants;

defined( 'ABSPATH' ) || exit;

/**
 * Nonce action names for AJAX security.
 */
class NonceActions {
	public const SETTINGS = 'cfr2_settings_nonce';
	public const BULK     = self::SETTINGS;
	public const WORKER   = self::SETTINGS;
	public const ACTIVITY = self::SETTINGS;
	public const MEDIA    = 'cfr2_media_action_';
}
