<?php
/**
 * Gutenberg Integration class.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Integrations;

defined( 'ABSPATH' ) || exit;

use ThachPN165\CFR2OffLoad\Interfaces\HookableInterface;
use ThachPN165\CFR2OffLoad\Traits\CdnUrlRewriterTrait;

/**
 * GutenbergIntegration class - handles block editor output.
 */
class GutenbergIntegration implements HookableInterface {

	use CdnUrlRewriterTrait;

	/**
	 * Plugin settings.
	 *
	 * @var array
	 */
	private array $settings;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->settings = get_option( 'cfr2_settings', array() );
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		if ( empty( $this->settings['cdn_enabled'] ) ) {
			return;
		}

		// Filter rendered image block output.
		add_filter( 'render_block_core/image', array( $this, 'filter_image_block' ), 10, 2 );

		// Filter rendered gallery block.
		add_filter( 'render_block_core/gallery', array( $this, 'filter_gallery_block' ), 10, 2 );

		// Filter cover block (has background image).
		add_filter( 'render_block_core/cover', array( $this, 'filter_cover_block' ), 10, 2 );

		// Filter media-text block.
		add_filter( 'render_block_core/media-text', array( $this, 'filter_media_text_block' ), 10, 2 );
	}

	/**
	 * Filter Image block output.
	 *
	 * @param string $block_content Block HTML.
	 * @param array  $block         Block data.
	 * @return string Modified HTML.
	 */
	public function filter_image_block( string $block_content, array $block ): string {
		if ( empty( $block_content ) ) {
			return $block_content;
		}

		$attachment_id = $block['attrs']['id'] ?? 0;
		if ( ! $attachment_id || ! get_post_meta( $attachment_id, '_cfr2_offloaded', true ) ) {
			return $block_content;
		}

		return $this->rewrite_img_tags_in_html( $block_content, $this->settings );
	}

	/**
	 * Filter Gallery block output.
	 *
	 * @param string $block_content Block HTML.
	 * @param array  $block         Block data.
	 * @return string Modified HTML.
	 */
	public function filter_gallery_block( string $block_content, array $block ): string {
		if ( empty( $block_content ) ) {
			return $block_content;
		}
		return $this->rewrite_img_tags_in_html( $block_content, $this->settings );
	}

	/**
	 * Filter Cover block output.
	 *
	 * @param string $block_content Block HTML.
	 * @param array  $block         Block data.
	 * @return string Modified HTML.
	 */
	public function filter_cover_block( string $block_content, array $block ): string {
		if ( empty( $block_content ) ) {
			return $block_content;
		}

		$block_content = $this->rewrite_img_tags_in_html( $block_content, $this->settings );
		return $this->rewrite_background_images( $block_content );
	}

	/**
	 * Filter Media & Text block.
	 *
	 * @param string $block_content Block HTML.
	 * @param array  $block         Block data.
	 * @return string Modified HTML.
	 */
	public function filter_media_text_block( string $block_content, array $block ): string {
		if ( empty( $block_content ) ) {
			return $block_content;
		}
		return $this->rewrite_img_tags_in_html( $block_content, $this->settings );
	}

	/**
	 * Rewrite background-image CSS.
	 *
	 * @param string $html HTML content.
	 * @return string Modified HTML.
	 */
	private function rewrite_background_images( string $html ): string {
		return preg_replace_callback(
			'/background-image:\s*url\(["\']?([^"\')\s]+)["\']?\)/i',
			function ( $matches ) {
				$url = $this->get_cdn_url_for_image( $matches[1], $this->settings );
				return 'background-image:url(' . esc_url( $url ) . ')';
			},
			$html
		);
	}
}
