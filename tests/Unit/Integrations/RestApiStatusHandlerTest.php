<?php
/**
 * RestApiStatusHandler Unit Tests
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Tests\Unit\Integrations;

use PHPUnit\Framework\TestCase;
use ThachPN165\CFR2OffLoad\Constants\MetaKeys;
use ThachPN165\CFR2OffLoad\Constants\Settings;
use ThachPN165\CFR2OffLoad\Integrations\RestApiStatusHandler;

/**
 * RestApiStatusHandlerTest class.
 */
class RestApiStatusHandlerTest extends TestCase {

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
	 * Test returns 404 when attachment does not exist.
	 */
	public function test_get_attachment_returns_404_for_missing_attachment(): void {
		$request  = new \WP_REST_Request( array( 'id' => 999 ) );
		$response = RestApiStatusHandler::get_attachment( $request );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'Attachment not found', $response->get_data()['error'] );
	}

	/**
	 * Test CDN URL is derived from configured R2 public domain when possible.
	 */
	public function test_get_attachment_builds_cdn_url_from_r2_url(): void {
		global $_test_posts, $_test_attachment_urls, $_test_attachment_metadata, $_test_attachment_mime_types, $_test_attachment_files;

		update_option(
			Settings::OPTION_KEY,
			array(
				'cdn_enabled'       => 1,
				'cdn_url'           => 'https://cdn.example.com/',
				'r2_public_domain'  => 'https://pub.example.com',
			)
		);

		$_test_posts[123]                 = (object) array( 'ID' => 123, 'post_type' => 'attachment' );
		$_test_attachment_urls[123]       = 'https://site.local/wp-content/uploads/pic.jpg';
		$_test_attachment_metadata[123]   = array( 'width' => 1920, 'height' => 1080 );
		$_test_attachment_mime_types[123] = 'image/jpeg';

		update_post_meta( 123, MetaKeys::OFFLOADED, true );
		update_post_meta( 123, MetaKeys::R2_URL, 'https://pub.example.com/uploads/2026/03/pic.jpg' );

		$tmp_file = tempnam( sys_get_temp_dir(), 'cfr2' );
		file_put_contents( $tmp_file, 'image-content' );
		$this->tmp_files[] = $tmp_file;
		$_test_attachment_files[123] = $tmp_file;

		$request  = new \WP_REST_Request( array( 'id' => 123 ) );
		$response = RestApiStatusHandler::get_attachment( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'https://cdn.example.com/uploads/2026/03/pic.jpg', $data['urls']['cdn'] );
		$this->assertSame( filesize( $tmp_file ), $data['file_size'] );
		$this->assertSame( 1920, $data['width'] );
		$this->assertSame( 1080, $data['height'] );
	}

	/**
	 * Test CDN URL falls back to R2 key when stored R2 URL does not match configured domain.
	 */
	public function test_get_attachment_falls_back_to_r2_key_for_cdn_url(): void {
		global $_test_posts;

		update_option(
			Settings::OPTION_KEY,
			array(
				'cdn_enabled'      => 1,
				'cdn_url'          => 'https://cdn.example.com',
				'r2_public_domain' => 'https://pub.example.com',
			)
		);

		$_test_posts[55] = (object) array( 'ID' => 55, 'post_type' => 'attachment' );
		update_post_meta( 55, MetaKeys::OFFLOADED, true );
		update_post_meta( 55, MetaKeys::R2_URL, 'https://legacy.example.net/path/file.png' );
		update_post_meta( 55, MetaKeys::R2_KEY, 'uploads/2026/03/file.png' );

		$request  = new \WP_REST_Request( array( 'id' => 55 ) );
		$response = RestApiStatusHandler::get_attachment( $request );
		$data     = $response->get_data();

		$this->assertSame( 'https://cdn.example.com/uploads/2026/03/file.png', $data['urls']['cdn'] );
		$this->assertNull( $data['urls']['local'] );
		$this->assertFalse( $data['local_exists'] );
		$this->assertNull( $data['file_size'] );
	}
}
