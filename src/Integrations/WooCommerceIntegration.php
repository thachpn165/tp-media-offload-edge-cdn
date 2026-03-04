<?php
/**
 * WooCommerce Integration class.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Integrations;

defined( 'ABSPATH' ) || exit;

use ThachPN165\CFR2OffLoad\Interfaces\HookableInterface;
use ThachPN165\CFR2OffLoad\Traits\CdnUrlRewriterTrait;

/**
 * WooCommerceIntegration class - handles WC product images.
 */
class WooCommerceIntegration implements HookableInterface {

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
		// Only if WooCommerce active and CDN enabled.
		if ( ! class_exists( 'WooCommerce' ) || empty( $this->settings['cdn_enabled'] ) ) {
			return;
		}

		// Product image filters.
		add_filter( 'woocommerce_single_product_image_thumbnail_html', array( $this, 'filter_gallery_thumbnail' ), 10, 2 );
		add_filter( 'woocommerce_product_get_image', array( $this, 'filter_product_image' ), 10, 5 );
		add_filter( 'woocommerce_cart_item_thumbnail', array( $this, 'filter_cart_thumbnail' ), 10, 3 );

		// Admin: Queue new product images.
		add_action( 'woocommerce_process_product_meta', array( $this, 'on_product_save' ), 20 );
	}

	/**
	 * Filter product image HTML.
	 *
	 * @param string      $html        Product image HTML.
	 * @param \WC_Product $product     Product object.
	 * @param string      $size        Image size.
	 * @param array       $attr        Image attributes.
	 * @param bool        $placeholder Whether is placeholder.
	 * @return string Modified HTML.
	 */
	public function filter_product_image( string $html, $product, string $size, array $attr, bool $placeholder ): string {
		if ( ! $html || $placeholder ) {
			return $html;
		}
		return $this->rewrite_img_tags_in_html( $html, $this->settings );
	}

	/**
	 * Filter gallery thumbnail HTML.
	 *
	 * @param string $html          Thumbnail HTML.
	 * @param int    $attachment_id Attachment ID.
	 * @return string Modified HTML.
	 */
	public function filter_gallery_thumbnail( string $html, int $attachment_id ): string {
		if ( ! $html || ! get_post_meta( $attachment_id, '_cfr2_offloaded', true ) ) {
			return $html;
		}
		return $this->rewrite_img_tags_in_html( $html, $this->settings );
	}

	/**
	 * Filter cart thumbnail.
	 *
	 * @param string $thumbnail     Thumbnail HTML.
	 * @param array  $cart_item     Cart item.
	 * @param string $cart_item_key Cart item key.
	 * @return string Modified HTML.
	 */
	public function filter_cart_thumbnail( string $thumbnail, array $cart_item, string $cart_item_key ): string {
		return $this->rewrite_img_tags_in_html( $thumbnail, $this->settings );
	}

	/**
	 * Queue product images for offload on save.
	 *
	 * @param int $product_id Product ID.
	 */
	public function on_product_save( int $product_id ): void {
		// Skip if auto-offload disabled.
		if ( empty( $this->settings['auto_offload'] ) ) {
			return;
		}

		// Get product image ID.
		$image_id = get_post_thumbnail_id( $product_id );
		if ( $image_id ) {
			$this->maybe_queue_attachment( $image_id );
		}

		// Get gallery image IDs.
		$gallery_ids = get_post_meta( $product_id, '_product_image_gallery', true );
		if ( $gallery_ids ) {
			$ids = array_filter( array_map( 'absint', explode( ',', $gallery_ids ) ) );
			foreach ( $ids as $attachment_id ) {
				$this->maybe_queue_attachment( $attachment_id );
			}
		}
	}

	/**
	 * Queue attachment for offload if not already.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function maybe_queue_attachment( int $attachment_id ): void {
		if ( get_post_meta( $attachment_id, '_cfr2_offloaded', true ) ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom queue table.
		$wpdb->insert(
			$wpdb->prefix . 'cfr2_offload_queue',
			array(
				'attachment_id' => $attachment_id,
				'action'        => 'offload',
				'status'        => 'pending',
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}
}
