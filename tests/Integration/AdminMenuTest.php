<?php
/**
 * AdminMenu Integration Tests.
 *
 * @package CFR2OffLoad\Tests\Integration
 */

namespace ThachPN165\CFR2OffLoad\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ThachPN165\CFR2OffLoad\Admin\AdminMenu;
use ThachPN165\CFR2OffLoad\Constants\BatchConfig;
use ThachPN165\CFR2OffLoad\Constants\Settings;

/**
 * Test AdminMenu class.
 */
class AdminMenuTest extends TestCase {

	/**
	 * Admin menu instance.
	 *
	 * @var AdminMenu
	 */
	private AdminMenu $admin_menu;

	/**
	 * Set up test.
	 */
	protected function setUp(): void {
		cfr2_test_reset_wp_state();
		$this->admin_menu = new AdminMenu();
	}

	/**
	 * Test admin menu can be instantiated.
	 */
	public function test_admin_menu_can_be_instantiated(): void {
		$this->assertInstanceOf( AdminMenu::class, $this->admin_menu );
	}

	/**
	 * Test register settings is called.
	 */
	public function test_register_settings(): void {
		// Register settings should be callable without error
		$this->admin_menu->register_settings();
		$this->assertTrue( true );
	}

	/**
	 * Test default settings.
	 */
	public function test_default_settings(): void {
		$method = new \ReflectionMethod( AdminMenu::class, 'get_default_settings' );
		$method->setAccessible( true );

		$defaults = $method->invoke( $this->admin_menu );

		$this->assertIsArray( $defaults );
		$this->assertArrayHasKey( 'r2_account_id', $defaults );
		$this->assertArrayHasKey( 'r2_access_key_id', $defaults );
		$this->assertArrayHasKey( 'batch_size', $defaults );
		$this->assertEquals( BatchConfig::DEFAULT_SIZE, $defaults['batch_size'] );
	}

	/**
	 * Test sanitize_registered_settings returns defaults for invalid input type.
	 */
	public function test_sanitize_registered_settings_returns_defaults_for_non_array(): void {
		$sanitized = $this->admin_menu->sanitize_registered_settings( 'not-an-array' );

		$this->assertIsArray( $sanitized );
		$this->assertEquals( BatchConfig::DEFAULT_SIZE, $sanitized['batch_size'] );
		$this->assertSame( 85, $sanitized['quality'] );
	}

	/**
	 * Test sanitize_registered_settings clamps and normalizes risky values.
	 */
	public function test_sanitize_registered_settings_clamps_values_and_normalizes_bucket(): void {
		update_option(
			Settings::OPTION_KEY,
			array(
				'r2_secret_access_key' => 'stored-secret',
				'cf_api_token'         => 'stored-token',
			)
		);

		$sanitized = $this->admin_menu->sanitize_registered_settings(
			array(
				'r2_bucket'         => 'My_Bucket@2026',
				'batch_size'        => 999,
				'quality'           => 500,
				'content_max_width' => 50,
			)
		);

		$this->assertSame( 'mybucket2026', $sanitized['r2_bucket'] );
		$this->assertSame( BatchConfig::MAX_SIZE, $sanitized['batch_size'] );
		$this->assertSame( 100, $sanitized['quality'] );
		$this->assertSame( 320, $sanitized['content_max_width'] );
		$this->assertSame( 'stored-secret', $sanitized['r2_secret_access_key'] );
		$this->assertSame( 'stored-token', $sanitized['cf_api_token'] );
	}
}
