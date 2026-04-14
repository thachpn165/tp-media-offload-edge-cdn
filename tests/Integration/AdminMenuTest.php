<?php
/**
 * AdminMenu Integration Tests.
 *
 * @package CFR2OffLoad\Tests\Integration
 */

namespace ThachPN165\CFR2OffLoad\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ThachPN165\CFR2OffLoad\Admin\AdminMenu;

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

}
