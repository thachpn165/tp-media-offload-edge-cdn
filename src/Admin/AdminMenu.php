<?php
/**
 * Admin Menu class.
 *
 * Slim coordinator for admin menu registration and AJAX handler delegation.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Admin;

defined( 'ABSPATH' ) || exit;

use ThachPN165\CFR2OffLoad\Admin\Ajax\SettingsAjaxHandler;
use ThachPN165\CFR2OffLoad\Admin\Ajax\BulkOperationAjaxHandler;
use ThachPN165\CFR2OffLoad\Admin\Ajax\WorkerAjaxHandler;
use ThachPN165\CFR2OffLoad\Admin\Ajax\ActivityAjaxHandler;
use ThachPN165\CFR2OffLoad\Constants\Settings;
use ThachPN165\CFR2OffLoad\Constants\BatchConfig;
use ThachPN165\CFR2OffLoad\Interfaces\HookableInterface;

/**
 * AdminMenu class - handles admin menu registration and AJAX delegation.
 */
class AdminMenu implements HookableInterface {

	/**
	 * Admin menu slug.
	 */
	private const MENU_SLUG = 'tp-media-offload-edge-cdn';

	/**
	 * Inline menu icon style handle.
	 */
	private const MENU_ICON_STYLE_HANDLE = 'cfr2-menu-icon-style';

	/**
	 * Settings AJAX handler.
	 *
	 * @var SettingsAjaxHandler
	 */
	private SettingsAjaxHandler $settings_handler;

	/**
	 * Bulk operation AJAX handler.
	 *
	 * @var BulkOperationAjaxHandler
	 */
	private BulkOperationAjaxHandler $bulk_handler;

	/**
	 * Worker AJAX handler.
	 *
	 * @var WorkerAjaxHandler
	 */
	private WorkerAjaxHandler $worker_handler;

	/**
	 * Activity AJAX handler.
	 *
	 * @var ActivityAjaxHandler
	 */
	private ActivityAjaxHandler $activity_handler;

	/**
	 * Constructor - initialize AJAX handlers.
	 */
	public function __construct() {
		$this->settings_handler = new SettingsAjaxHandler();
		$this->bulk_handler     = new BulkOperationAjaxHandler();
		$this->worker_handler   = new WorkerAjaxHandler();
		$this->activity_handler = new ActivityAjaxHandler();
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_menu_icon_style' ) );

		// Delegate AJAX hooks to specialized handlers.
		$this->settings_handler->register_hooks();
		$this->bulk_handler->register_hooks();
		$this->worker_handler->register_hooks();
		$this->activity_handler->register_hooks();
	}

	/**
	 * Add menu page.
	 */
	public function add_menu_page(): void {
		add_menu_page(
			__( 'TP Media Offload & Edge CDN Settings', 'tp-media-offload-edge-cdn' ),
			__( 'TP Media Offload & Edge CDN', 'tp-media-offload-edge-cdn' ),
			'manage_options',
			self::MENU_SLUG,
			array( SettingsPage::class, 'render' ),
			'none',
			80
		);
	}

	/**
	 * Enqueue inline CSS for custom menu icon.
	 */
	public function enqueue_admin_menu_icon_style(): void {
		// phpcs:ignore Generic.Files.LineLength.TooLong
		$icon_base64 = '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';

		wp_register_style( self::MENU_ICON_STYLE_HANDLE, false, array(), \CFR2_VERSION );
		wp_enqueue_style( self::MENU_ICON_STYLE_HANDLE );

		$selector = '#adminmenu .toplevel_page_' . self::MENU_SLUG . ' .wp-menu-image';
		$css      = $selector . " {\n"
			. "background-image: url('data:image/png;base64,{$icon_base64}') !important;\n"
			. "background-repeat: no-repeat !important;\n"
			. "background-position: center center !important;\n"
			. "background-size: 20px 20px !important;\n"
			. "}\n"
			. $selector . ":before {\n"
			. "content: '' !important;\n"
			. '}';

		wp_add_inline_style( self::MENU_ICON_STYLE_HANDLE, $css );
	}

	/**
	 * Register settings.
	 */
	public function register_settings(): void {
		register_setting(
			Settings::SETTINGS_GROUP,
			Settings::OPTION_KEY,
			array(
				'type'              => 'array',
				'default'           => $this->get_default_settings(),
				'sanitize_callback' => array( $this, 'sanitize_registered_settings' ),
			)
		);
	}

	/**
	 * Sanitize settings registered through register_setting.
	 *
	 * @param mixed $input Raw option value.
	 * @return array Sanitized option value.
	 */
	public function sanitize_registered_settings( $input ): array {
		if ( ! is_array( $input ) ) {
			return $this->get_default_settings();
		}

		$existing = get_option( Settings::OPTION_KEY, array() );
		$merged   = wp_parse_args( $input, $existing );

		$secret_value = array_key_exists( 'r2_secret_access_key', $input )
			? sanitize_text_field( (string) $input['r2_secret_access_key'] )
			: '********';

		$token_value = array_key_exists( 'cf_api_token', $input )
			? sanitize_text_field( (string) $input['cf_api_token'] )
			: '********';

		$normalized = array(
			'r2_account_id'        => sanitize_text_field( (string) ( $merged['r2_account_id'] ?? '' ) ),
			'r2_access_key_id'     => sanitize_text_field( (string) ( $merged['r2_access_key_id'] ?? '' ) ),
			'r2_secret_access_key' => $secret_value,
			'r2_bucket'            => sanitize_text_field( (string) ( $merged['r2_bucket'] ?? '' ) ),
			'r2_public_domain'     => esc_url_raw( (string) ( $merged['r2_public_domain'] ?? '' ) ),
			'auto_offload'         => ! empty( $merged['auto_offload'] ) ? 1 : 0,
			'batch_size'           => absint( $merged['batch_size'] ?? BatchConfig::DEFAULT_SIZE ),
			'keep_local_files'     => ! empty( $merged['keep_local_files'] ) ? 1 : 0,
			'sync_delete'          => ! empty( $merged['sync_delete'] ) ? 1 : 0,
			'cdn_enabled'          => ! empty( $merged['cdn_enabled'] ) ? 1 : 0,
			'cdn_url'              => esc_url_raw( (string) ( $merged['cdn_url'] ?? '' ) ),
			'quality'              => absint( $merged['quality'] ?? 85 ),
			'image_format'         => sanitize_text_field( (string) ( $merged['image_format'] ?? 'webp' ) ),
			'smart_sizes'          => ! empty( $merged['smart_sizes'] ) ? 1 : 0,
			'content_max_width'    => absint( $merged['content_max_width'] ?? 800 ),
			'cf_api_token'         => $token_value,
		);

		return $this->settings_handler->sanitize_settings( $normalized );
	}

	/**
	 * Get default settings.
	 *
	 * @return array Default settings.
	 */
	private function get_default_settings(): array {
		return array(
			'r2_account_id'        => '',
			'r2_access_key_id'     => '',
			'r2_secret_access_key' => '',
			'r2_bucket'            => '',
			'r2_public_domain'     => '',
			'auto_offload'         => 0,
			'batch_size'           => BatchConfig::DEFAULT_SIZE,
			'keep_local_files'     => 1,
			'sync_delete'          => 0,
			'cdn_enabled'          => 0,
			'cdn_url'              => '',
			'quality'              => 85,
			'image_format'         => 'webp',
			'smart_sizes'          => 0,
			'content_max_width'    => 800,
			'cf_api_token'         => '',
			'worker_deployed'      => false,
			'worker_name'          => '',
			'worker_deployed_at'   => '',
		);
	}
}
