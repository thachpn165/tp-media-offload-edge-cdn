<?php
/**
 * R2Client Unit Tests
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use ThachPN165\CFR2OffLoad\Services\R2Client;

/**
 * R2ClientTest class - tests R2Client service.
 */
class R2ClientTest extends TestCase {

	/**
	 * Test credentials.
	 *
	 * @var array
	 */
	private array $test_credentials;

	/**
	 * Temp files created during tests.
	 *
	 * @var array<int, string>
	 */
	private array $tmp_files = array();

	/**
	 * Setup test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		cfr2_test_reset_wp_state();
		$this->test_credentials = array(
			'account_id'        => 'test_account_123',
			'access_key_id'     => 'test_access_key',
			'secret_access_key' => 'test_secret_key',
			'bucket'            => 'test-bucket',
		);
	}

	/**
	 * Cleanup temp files.
	 */
	protected function tearDown(): void {
		foreach ( $this->tmp_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		parent::tearDown();
	}

	/**
	 * Test constructor sets credentials correctly.
	 */
	public function test_constructor_sets_credentials(): void {
		$client = new R2Client( $this->test_credentials );

		// Use reflection to verify private properties.
		$reflection  = new \ReflectionClass( $client );
		$account_prop = $reflection->getProperty( 'account_id' );
		$account_prop->setAccessible( true );

		$this->assertEquals( 'test_account_123', $account_prop->getValue( $client ) );

		$bucket_prop = $reflection->getProperty( 'bucket' );
		$bucket_prop->setAccessible( true );

		$this->assertEquals( 'test-bucket', $bucket_prop->getValue( $client ) );
	}

	/**
	 * Test build_r2_url generates correct format.
	 */
	public function test_build_r2_url_format(): void {
		$client = new R2Client( $this->test_credentials );

		$reflection = new \ReflectionClass( $client );
		$method     = $reflection->getMethod( 'build_r2_url' );
		$method->setAccessible( true );

		$url = $method->invoke( $client, 'uploads/2026/01/image.jpg' );

		$this->assertStringContainsString( 'test-bucket', $url );
		$this->assertStringContainsString( 'test_account_123', $url );
		$this->assertStringContainsString( 'uploads/2026/01/image.jpg', $url );
		$this->assertStringStartsWith( 'https://', $url );
	}

	/**
	 * Test upload_file returns clear error when local file is missing.
	 */
	public function test_upload_file_returns_error_for_missing_file(): void {
		$client = new R2Client( $this->test_credentials );

		$result = $client->upload_file( '/tmp/definitely-missing-file.jpg', 'uploads/file.jpg' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'Local file not found', $result['message'] );
	}

	/**
	 * Test upload_file rejects files larger than 70MB before any remote call.
	 */
	public function test_upload_file_rejects_oversized_file(): void {
		$client = new R2Client( $this->test_credentials );

		$tmp_file = tempnam( sys_get_temp_dir(), 'cfr2-large-' );
		$this->assertNotFalse( $tmp_file );
		$this->tmp_files[] = $tmp_file;

		$handle = fopen( $tmp_file, 'wb' );
		$this->assertNotFalse( $handle );
		ftruncate( $handle, 71 * 1024 * 1024 );
		fclose( $handle );

		$result = $client->upload_file( $tmp_file, 'uploads/too-large.bin' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'File exceeds 70MB limit', $result['message'] );
	}
}
