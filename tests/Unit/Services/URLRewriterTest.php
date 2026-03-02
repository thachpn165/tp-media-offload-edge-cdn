<?php
/**
 * URLRewriter Unit Tests
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use ThachPN165\CFR2OffLoad\Services\URLRewriter;

/**
 * URLRewriterTest class - tests URL rewriting logic.
 */
class URLRewriterTest extends TestCase {

	/**
	 * URLRewriter instance.
	 *
	 * @var URLRewriter
	 */
	private URLRewriter $rewriter;

	/**
	 * Setup test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		cfr2_test_reset_wp_state();

		// Set up test settings.
		update_option(
			'cloudflare_r2_offload_cdn_settings',
			array(
				'cdn_enabled' => true,
				'cdn_url'     => 'https://cdn.example.com',
				'quality'     => 85,
			)
		);

		$this->rewriter = new URLRewriter();
	}

	/**
	 * Cleanup after tests.
	 */
	public function tearDown(): void {
		delete_option( 'cloudflare_r2_offload_cdn_settings' );
		parent::tearDown();
	}

	/**
	 * Test URLRewriter can be instantiated.
	 */
	public function test_urlrewriter_instantiation(): void {
		$this->assertInstanceOf( URLRewriter::class, $this->rewriter );
	}

	/**
	 * Test add_lazy_loading adds loading attribute.
	 */
	public function test_add_lazy_loading_attribute(): void {
		$attr = array();
		$post = new \WP_Post( (object) array( 'ID' => 1 ) );

		$result = $this->rewriter->add_lazy_loading( $attr, $post, 'medium' );

		$this->assertArrayHasKey( 'loading', $result );
		$this->assertEquals( 'lazy', $result['loading'] );
	}

	/**
	 * Test add_lazy_loading keeps existing loading attribute.
	 */
	public function test_add_lazy_loading_preserves_existing_attribute(): void {
		$attr = array( 'loading' => 'eager' );
		$post = new \WP_Post( (object) array( 'ID' => 1 ) );

		$result = $this->rewriter->add_lazy_loading( $attr, $post, 'medium' );

		$this->assertSame( 'eager', $result['loading'] );
	}

	/**
	 * Test get_sizes_attribute returns existing sizes when smart sizes are disabled.
	 */
	public function test_get_sizes_attribute_returns_existing_when_smart_sizes_disabled(): void {
		$method = new \ReflectionMethod( URLRewriter::class, 'get_sizes_attribute' );
		$method->setAccessible( true );

		$sizes = $method->invoke( $this->rewriter, '<img sizes="(max-width: 600px) 100vw, 600px">', 1200 );

		$this->assertSame( '(max-width: 600px) 100vw, 600px', $sizes );
	}

	/**
	 * Test get_sizes_attribute computes smart sizes when enabled.
	 */
	public function test_get_sizes_attribute_generates_smart_sizes_when_enabled(): void {
		update_option(
			'cloudflare_r2_offload_cdn_settings',
			array(
				'cdn_enabled'       => true,
				'cdn_url'           => 'https://cdn.example.com',
				'quality'           => 85,
				'smart_sizes'       => 1,
				'content_max_width' => 700,
			)
		);
		$rewriter = new URLRewriter();

		$method = new \ReflectionMethod( URLRewriter::class, 'get_sizes_attribute' );
		$method->setAccessible( true );

		$sizes = $method->invoke( $rewriter, '<img src="x.jpg">', 1600 );

		$this->assertSame( '(max-width: 640px) 100vw, (max-width: 1024px) min(100vw, 700px), 700px', $sizes );
	}

	/**
	 * Test rewrite_attachment_url returns original URL when attachment is not offloaded.
	 */
	public function test_rewrite_attachment_url_returns_original_when_not_offloaded(): void {
		$url = $this->rewriter->rewrite_attachment_url( 'https://site.local/uploads/a.jpg', 101 );
		$this->assertSame( 'https://site.local/uploads/a.jpg', $url );
	}

	/**
	 * Test rewrite_attachment_url uses R2 public domain when CDN is disabled.
	 */
	public function test_rewrite_attachment_url_uses_r2_public_domain_without_cdn(): void {
		update_option(
			'cloudflare_r2_offload_cdn_settings',
			array(
				'cdn_enabled'      => 0,
				'r2_public_domain' => 'https://pub.r2.dev',
			)
		);
		$rewriter = new URLRewriter();

		update_post_meta( 202, '_cfr2_offloaded', true );
		update_post_meta( 202, '_cfr2_r2_key', 'uploads/2026/03/example.jpg' );

		$url = $rewriter->rewrite_attachment_url( 'https://site.local/uploads/example.jpg', 202 );
		$this->assertSame( 'https://pub.r2.dev/uploads/2026/03/example.jpg', $url );
	}

	/**
	 * Test rewrite_attachment_url uses CDN transform URL when CDN is available.
	 */
	public function test_rewrite_attachment_url_uses_cdn_transform_when_enabled(): void {
		update_option(
			'cloudflare_r2_offload_cdn_settings',
			array(
				'cdn_enabled' => 1,
				'cdn_url'     => 'https://cdn.example.com',
				'quality'     => 77,
			)
		);
		$rewriter = new URLRewriter();

		update_post_meta( 303, '_cfr2_offloaded', true );
		update_post_meta( 303, '_cfr2_r2_key', 'uploads/2026/03/cdn-image.jpg' );

		$url = $rewriter->rewrite_attachment_url( 'https://site.local/uploads/cdn-image.jpg', 303 );

		$this->assertStringStartsWith( 'https://cdn.example.com/uploads/2026/03/cdn-image.jpg', $url );
		$this->assertStringContainsString( 'q=77', $url );
		$this->assertStringContainsString( 'f=auto', $url );
	}
}
