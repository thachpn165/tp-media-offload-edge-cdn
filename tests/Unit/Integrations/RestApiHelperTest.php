<?php
/**
 * RestApiHelper Unit Tests
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Tests\Unit\Integrations;

use PHPUnit\Framework\TestCase;
use ThachPN165\CFR2OffLoad\Integrations\RestApiHelper;

/**
 * RestApiHelperTest class.
 */
class RestApiHelperTest extends TestCase {

	/**
	 * Setup test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		cfr2_test_reset_wp_state();
	}

	/**
	 * Test attachment permission defaults to capability check.
	 */
	public function test_check_attachment_permission_defaults_to_capability(): void {
		global $_test_current_user_caps;

		$_test_current_user_caps['upload_files'] = false;
		$this->assertFalse( RestApiHelper::check_attachment_permission() );

		$_test_current_user_caps['upload_files'] = true;
		$this->assertTrue( RestApiHelper::check_attachment_permission() );
	}

	/**
	 * Test attachment permission can be made public via filter.
	 */
	public function test_check_attachment_permission_allows_filter_override(): void {
		global $_test_current_user_caps;

		$_test_current_user_caps['upload_files'] = false;
		add_filter(
			'cfr2_rest_public_attachment_endpoint',
			static function ( $is_public ) {
				return true;
			}
		);

		$this->assertTrue( RestApiHelper::check_attachment_permission() );
	}
}
