<?php
/**
 * REST API Integration Tests
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ThachPN165\CFR2OffLoad\Integrations\RestApiIntegration;
use ThachPN165\CFR2OffLoad\Integrations\RestApiHelper;

/**
 * RestApiTest class - tests REST API endpoints.
 */
class RestApiTest extends TestCase {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	private string $namespace = 'cfr2/v1';

	/**
	 * RestApiIntegration instance.
	 *
	 * @var RestApiIntegration
	 */
	private RestApiIntegration $integration;

	/**
	 * Setup test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		cfr2_test_reset_wp_state();
		$this->integration = new RestApiIntegration();
	}

	/**
	 * Test RestApiIntegration can be instantiated.
	 */
	public function test_rest_api_integration_instantiation(): void {
		$this->assertInstanceOf( RestApiIntegration::class, $this->integration );
	}

	/**
	 * Test RestApiIntegration has register_routes method.
	 */
	public function test_rest_api_has_register_routes(): void {
		$this->assertTrue( method_exists( $this->integration, 'register_routes' ) );
	}

	/**
	 * Test REST API namespace is correctly set.
	 */
	public function test_rest_api_namespace(): void {
		$this->assertEquals( 'cfr2/v1', $this->namespace );
	}

	/**
	 * Test routes are registered with expected permission callbacks.
	 */
	public function test_register_routes_with_expected_permissions(): void {
		global $_test_registered_rest_routes;

		$this->integration->register_routes();

		$attachment_key = 'cfr2/v1/attachment/(?P<id>\d+)';
		$stats_key      = 'cfr2/v1/stats';

		$this->assertArrayHasKey( $attachment_key, $_test_registered_rest_routes );
		$this->assertArrayHasKey( $stats_key, $_test_registered_rest_routes );

		$attachment_route = $_test_registered_rest_routes[ $attachment_key ];
		$this->assertSame(
			array( RestApiHelper::class, 'check_attachment_permission' ),
			$attachment_route['permission_callback']
		);
		$this->assertSame(
			array( RestApiHelper::class, 'validate_attachment_id' ),
			$attachment_route['args']['id']['validate_callback']
		);

		$stats_route = $_test_registered_rest_routes[ $stats_key ];
		$this->assertSame(
			array( RestApiHelper::class, 'check_read_permission' ),
			$stats_route['permission_callback']
		);
		$this->assertSame( array( 'week', 'month' ), $stats_route['args']['period']['enum'] );
	}
}
