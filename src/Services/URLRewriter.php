<?php
/**
 * URL Rewriter Service class.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Services;

defined( 'ABSPATH' ) || exit;

use ThachPN165\CFR2OffLoad\Interfaces\HookableInterface;

/**
 * URLRewriter class - handles URL transformation for CDN.
 */
class URLRewriter implements HookableInterface {

	/**
	 * Responsive breakpoints for srcset.
	 */
	private const BREAKPOINTS = array( 320, 640, 768, 1024, 1280, 1536 );

	/**
	 * Tracked attachment IDs per request to avoid duplicate counting.
	 *
	 * @var array<int, bool>
	 */
	private static array $tracked_transformations = array();

	/**
	 * Plugin settings.
	 *
	 * @var array
	 */
	private array $settings;

	/**
	 * CDN availability status.
	 *
	 * @var bool
	 */
	private bool $cdn_available;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->settings      = get_option( 'cloudflare_r2_offload_cdn_settings', array() );
		$this->cdn_available = $this->check_cdn_availability();
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		// Register if CDN enabled OR R2 public domain is set.
		$has_cdn    = ! empty( $this->settings['cdn_enabled'] ) && ! empty( $this->settings['cdn_url'] );
		$has_r2_pub = ! empty( $this->settings['r2_public_domain'] );

		if ( ! $has_cdn && ! $has_r2_pub ) {
			return;
		}

		add_filter( 'wp_get_attachment_url', array( $this, 'rewrite_attachment_url' ), 99, 2 );
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'add_lazy_loading' ), 10, 3 );

		// Srcset/size transforms only when CDN Worker is enabled.
		if ( $has_cdn ) {
			add_filter( 'wp_get_attachment_image_src', array( $this, 'rewrite_image_src' ), 99, 4 );
			add_filter( 'wp_calculate_image_srcset', array( $this, 'generate_srcset' ), 99, 5 );

			// Wrap images in <picture> element for AVIF/WebP support (WP 6.0+).
			add_filter( 'wp_content_img_tag', array( $this, 'wrap_with_picture_element' ), 10, 3 );
		}
	}

	/**
	 * Rewrite attachment URL to CDN or R2 public domain.
	 *
	 * @param string $url Attachment URL.
	 * @param int    $attachment_id Attachment ID.
	 * @return string Rewritten URL.
	 */
	public function rewrite_attachment_url( string $url, int $attachment_id ): string {
		// Check if offloaded.
		$is_offloaded = get_post_meta( $attachment_id, '_cfr2_offloaded', true );
		if ( ! $is_offloaded ) {
			return $url;
		}

		// Get R2 key.
		$r2_key = get_post_meta( $attachment_id, '_cfr2_r2_key', true );
		if ( ! $r2_key ) {
			return $url;
		}

		// Priority 1: CDN with Worker (if enabled and available).
		$cdn_enabled = ! empty( $this->settings['cdn_enabled'] ) && ! empty( $this->settings['cdn_url'] );
		if ( $cdn_enabled && $this->cdn_available ) {
			// Track transformation for stats.
			$this->track_transformation( $attachment_id );

			return $this->build_cdn_url(
				$r2_key,
				array(
					'q' => $this->settings['quality'] ?? 85,
					'f' => 'auto',
				)
			);
		}

		// Priority 2: R2 public domain (no transforms, just direct URL).
		if ( ! empty( $this->settings['r2_public_domain'] ) ) {
			$r2_domain = rtrim( $this->settings['r2_public_domain'], '/' );
			return "{$r2_domain}/{$r2_key}";
		}

		// Fallback: stored local URL.
		$local_url = get_post_meta( $attachment_id, '_cfr2_local_url', true );
		return $local_url ?: $url;
	}

	/**
	 * Track transformation asynchronously.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function track_transformation( int $attachment_id ): void {
		if ( isset( self::$tracked_transformations[ $attachment_id ] ) ) {
			return;
		}

		self::$tracked_transformations[ $attachment_id ] = true;
		add_action(
			'shutdown',
			static function (): void {
				StatsTracker::increment();
			}
		);
	}

	/**
	 * Rewrite image src with size.
	 *
	 * @param array|false $image Image data or false.
	 * @param int         $attachment_id Attachment ID.
	 * @param string|int[] $size Requested size.
	 * @param bool        $icon Whether to use icon.
	 * @return array|false Modified image data.
	 */
	public function rewrite_image_src( $image, int $attachment_id, $size, bool $icon ) {
		if ( ! $image || $icon ) {
			return $image;
		}

		$is_offloaded = get_post_meta( $attachment_id, '_cfr2_offloaded', true );
		if ( ! $is_offloaded || ! $this->cdn_available ) {
			return $image;
		}

		$r2_key = get_post_meta( $attachment_id, '_cfr2_r2_key', true );
		if ( ! $r2_key ) {
			return $image;
		}

		// Get requested dimensions.
		$width  = $image[1];
		$height = $image[2];

		$image[0] = $this->build_cdn_url(
			$r2_key,
			array(
				'w'   => $width,
				'h'   => $height,
				'q'   => $this->settings['quality'] ?? 85,
				'f'   => 'auto',
				'fit' => 'cover',
			)
		);

		return $image;
	}

	/**
	 * Generate responsive srcset with CDN URLs.
	 *
	 * @param array $sources Srcset sources.
	 * @param array $size_array Size array.
	 * @param string $image_src Image src.
	 * @param array $image_meta Image metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array Modified sources.
	 */
	public function generate_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ): array {
		$is_offloaded = get_post_meta( $attachment_id, '_cfr2_offloaded', true );
		if ( ! $is_offloaded || ! $this->cdn_available ) {
			return $sources;
		}

		$r2_key = get_post_meta( $attachment_id, '_cfr2_r2_key', true );
		if ( ! $r2_key ) {
			return $sources;
		}

		// Generate sources for each breakpoint.
		$new_sources    = array();
		$quality        = $this->settings['quality'] ?? 85;
		$original_width = $image_meta['width'] ?? 1920;

		foreach ( self::BREAKPOINTS as $width ) {
			// Skip if larger than original.
			if ( $width > $original_width ) {
				continue;
			}

			$new_sources[ $width ] = array(
				'url'        => $this->build_cdn_url(
					$r2_key,
					array(
						'w' => $width,
						'q' => $quality,
						'f' => 'auto',
					)
				),
				'descriptor' => 'w',
				'value'      => $width,
			);
		}

		// Add original size if not in breakpoints.
		if ( ! isset( $new_sources[ $original_width ] ) ) {
			$new_sources[ $original_width ] = array(
				'url'        => $this->build_cdn_url(
					$r2_key,
					array(
						'q' => $quality,
						'f' => 'auto',
					)
				),
				'descriptor' => 'w',
				'value'      => $original_width,
			);
		}

		return $new_sources;
	}

	/**
	 * Add lazy loading attribute.
	 *
	 * @param array    $attr Attributes.
	 * @param \WP_Post $attachment Attachment post.
	 * @param string|int[] $size Size.
	 * @return array Modified attributes.
	 */
	public function add_lazy_loading( array $attr, \WP_Post $attachment, $size ): array {
		// Add loading="lazy" if not already set.
		if ( ! isset( $attr['loading'] ) ) {
			$attr['loading'] = 'lazy';
		}

		return $attr;
	}

	/**
	 * Wrap img tag with picture element for AVIF/WebP support.
	 *
	 * @param string $filtered_image Full img tag.
	 * @param string $context Context (the_content, etc).
	 * @param int    $attachment_id Attachment ID.
	 * @return string Modified HTML with picture element.
	 */
	public function wrap_with_picture_element( string $filtered_image, string $context, int $attachment_id ): string {
		// Skip if already wrapped in picture element (prevent double-wrapping).
		if ( str_contains( $filtered_image, '<picture' ) || str_contains( $filtered_image, 'data-cfr2-picture' ) ) {
			return $filtered_image;
		}

		// Skip if not offloaded or CDN not available.
		$is_offloaded = get_post_meta( $attachment_id, '_cfr2_offloaded', true );
		if ( ! $is_offloaded || ! $this->cdn_available ) {
			return $filtered_image;
		}

		$r2_key = get_post_meta( $attachment_id, '_cfr2_r2_key', true );
		if ( ! $r2_key ) {
			return $filtered_image;
		}

		// Skip non-image formats.
		if ( ! preg_match( '/\.(jpe?g|png|gif)$/i', $r2_key ) ) {
			return $filtered_image;
		}

		// Get image metadata for original width.
		$image_meta     = wp_get_attachment_metadata( $attachment_id );
		$original_width = $image_meta['width'] ?? 1920;
		$quality        = $this->settings['quality'] ?? 85;
		$image_format   = $this->settings['image_format'] ?? 'webp';

		// If using original format, return without picture element wrapping.
		if ( 'original' === $image_format ) {
			return $filtered_image;
		}

		// Extract or calculate sizes attribute.
		$sizes = $this->get_sizes_attribute( $filtered_image, $original_width );

		// Build picture element with marker to prevent double-wrapping.
		$picture = '<picture data-cfr2-picture="1">';

		// AVIF source (if avif format selected).
		if ( 'avif' === $image_format ) {
			$avif_srcset = $this->build_format_srcset( $r2_key, 'avif', $original_width, $quality );
			if ( $avif_srcset ) {
				$picture .= sprintf(
					'<source type="image/avif" srcset="%s" sizes="%s">',
					esc_attr( $avif_srcset ),
					esc_attr( $sizes )
				);
			}
		}

		// WebP source (for both webp and avif formats - avif falls back to webp).
		$webp_srcset = $this->build_format_srcset( $r2_key, 'webp', $original_width, $quality );
		if ( $webp_srcset ) {
			$picture .= sprintf(
				'<source type="image/webp" srcset="%s" sizes="%s">',
				esc_attr( $webp_srcset ),
				esc_attr( $sizes )
			);
		}

		// Original img tag as fallback.
		$picture .= $filtered_image;
		$picture .= '</picture>';

		return $picture;
	}

	/**
	 * Build srcset for specific format.
	 *
	 * @param string $r2_key R2 object key.
	 * @param string $format Target format (avif, webp).
	 * @param int    $original_width Original image width.
	 * @param int    $quality Image quality.
	 * @return string Srcset string.
	 */
	private function build_format_srcset( string $r2_key, string $format, int $original_width, int $quality ): string {
		$srcset_parts = array();

		foreach ( self::BREAKPOINTS as $width ) {
			if ( $width > $original_width ) {
				continue;
			}

			$url            = $this->build_cdn_url(
				$r2_key,
				array(
					'w' => $width,
					'q' => $quality,
					'f' => $format,
				)
			);
			$srcset_parts[] = "{$url} {$width}w";
		}

		// Add original size.
		if ( ! in_array( $original_width, self::BREAKPOINTS, true ) ) {
			$url            = $this->build_cdn_url(
				$r2_key,
				array(
					'q' => $quality,
					'f' => $format,
				)
			);
			$srcset_parts[] = "{$url} {$original_width}w";
		}

		return implode( ', ', $srcset_parts );
	}

	/**
	 * Get optimal sizes attribute.
	 *
	 * @param string $filtered_image Original img tag.
	 * @param int    $original_width Original image width.
	 * @return string Sizes attribute value.
	 */
	private function get_sizes_attribute( string $filtered_image, int $original_width ): string {
		// Extract existing sizes from img tag.
		$existing_sizes = '100vw';
		if ( preg_match( '/sizes=["\']([^"\']+)["\']/', $filtered_image, $matches ) ) {
			$existing_sizes = $matches[1];
		}

		// Return existing if smart sizes disabled.
		if ( empty( $this->settings['smart_sizes'] ) ) {
			return $existing_sizes;
		}

		// Get content max width from settings.
		$content_max_width = absint( $this->settings['content_max_width'] ?? 800 );

		// Calculate smart sizes based on content width.
		// Mobile: 100vw (full width)
		// Tablet: min(100vw, content_max_width)
		// Desktop: content_max_width (capped)
		$sizes_parts = array();

		// Mobile breakpoint (up to 640px viewport).
		$sizes_parts[] = '(max-width: 640px) 100vw';

		// Tablet breakpoint (641px to 1024px).
		if ( $content_max_width < 1024 ) {
			$sizes_parts[] = "(max-width: 1024px) min(100vw, {$content_max_width}px)";
		} else {
			$sizes_parts[] = '(max-width: 1024px) 100vw';
		}

		// Desktop: use content max width or original image width (whichever is smaller).
		$desktop_size   = min( $content_max_width, $original_width );
		$sizes_parts[]  = "{$desktop_size}px";

		return implode( ', ', $sizes_parts );
	}

	/**
	 * Build CDN URL with transformation params.
	 *
	 * @param string $r2_key R2 object key.
	 * @param array  $params Transform params.
	 * @return string CDN URL.
	 */
	private function build_cdn_url( string $r2_key, array $params = array() ): string {
		$cdn_url = rtrim( $this->settings['cdn_url'], '/' );
		$url     = "{$cdn_url}/{$r2_key}";

		if ( ! empty( $params ) ) {
			$url .= '?' . http_build_query( $params );
		}

		return $url;
	}

	/**
	 * Check if CDN is available (cached check).
	 *
	 * @return bool True if available.
	 */
	private function check_cdn_availability(): bool {
		// Skip check if not configured.
		if ( empty( $this->settings['cdn_url'] ) ) {
			return false;
		}

		// Check cache.
		$cache_key = 'cfr2_cdn_available';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return 'yes' === $cached;
		}

		// Perform health check.
		$test_url = rtrim( $this->settings['cdn_url'], '/' ) . '/health';
		$response = wp_remote_head(
			$test_url,
			array(
				'timeout'   => 5,
				'sslverify' => true,
			)
		);

		$is_available = ! is_wp_error( $response ) &&
						wp_remote_retrieve_response_code( $response ) < 500;

		// Cache for 5 minutes.
		set_transient( $cache_key, $is_available ? 'yes' : 'no', 300 );

		return $is_available;
	}

	/**
	 * Force CDN availability check (clear cache).
	 */
	public static function clear_availability_cache(): void {
		delete_transient( 'cfr2_cdn_available' );
	}
}
