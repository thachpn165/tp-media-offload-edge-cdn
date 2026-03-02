<?php
/**
 * Offload Workflow Integration Tests
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ThachPN165\CFR2OffLoad\Services\OffloadService;
use ThachPN165\CFR2OffLoad\Services\R2Client;

/**
 * OffloadWorkflowTest class - tests offload workflow integration.
 */
class OffloadWorkflowTest extends TestCase {

	/**
	 * OffloadService instance.
	 *
	 * @var OffloadService
	 */
	private OffloadService $service;

	/**
	 * Queue table name.
	 *
	 * @var string
	 */
	private string $queue_table;

	/**
	 * Setup test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		cfr2_test_reset_wp_state();

		// Mock R2Client.
		$r2_mock = $this->createMock( R2Client::class );
		$r2_mock->method( 'test_connection' )->willReturn( array( 'success' => true ) );

		$this->service = new OffloadService( $r2_mock );
	}

	/**
	 * Test OffloadService can be instantiated.
	 */
	public function test_offload_service_instantiation(): void {
		$this->assertInstanceOf( OffloadService::class, $this->service );
	}

	/**
	 * Test OffloadService has required methods.
	 */
	public function test_offload_service_has_methods(): void {
		$this->assertTrue( method_exists( $this->service, 'queue_offload' ) );
		$this->assertTrue( method_exists( $this->service, 'is_offloaded' ) );
		$this->assertTrue( method_exists( $this->service, 'offload' ) );
		$this->assertTrue( method_exists( $this->service, 'restore' ) );
		$this->assertTrue( method_exists( $this->service, 'get_r2_url' ) );
		$this->assertTrue( method_exists( $this->service, 'get_local_url' ) );
	}

	/**
	 * Test offload rejects unsupported MIME types before touching filesystem.
	 */
	public function test_offload_rejects_disallowed_mime_type(): void {
		global $_test_attachment_mime_types;

		$_test_attachment_mime_types[500] = 'application/x-msdownload';
		$result                           = $this->service->offload( 500 );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not allowed for offload', $result['message'] );
	}

	/**
	 * Test offload can be blocked by filter even when MIME is allowed.
	 */
	public function test_offload_respects_should_offload_filter(): void {
		global $_test_attachment_mime_types;

		$_test_attachment_mime_types[501] = 'image/jpeg';
		add_filter(
			'cfr2_should_offload',
			static function ( $should, $attachment_id ) {
				return false;
			},
			10,
			2
		);

		$result = $this->service->offload( 501 );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Offload blocked by filter', $result['message'] );
	}
}
