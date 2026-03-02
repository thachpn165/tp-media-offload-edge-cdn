<?php
/**
 * REST API Status Handler class.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Integrations;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use ThachPN165\CFR2OffLoad\Constants\MetaKeys;
use ThachPN165\CFR2OffLoad\Constants\Settings;

/**
 * RestApiStatusHandler class - handles read-only status endpoints.
 */
class RestApiStatusHandler {

	/**
	 * Get attachment info with URLs.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public static function get_attachment( WP_REST_Request $request ): WP_REST_Response {
		$attachment_id = (int) $request->get_param( 'id' );

		// Verify attachment exists.
		if ( ! RestApiHelper::verify_attachment( $attachment_id ) ) {
			return new WP_REST_Response(
				array( 'error' => 'Attachment not found' ),
				404
			);
		}

		$is_offloaded  = (bool) get_post_meta( $attachment_id, MetaKeys::OFFLOADED, true );
		$r2_url        = get_post_meta( $attachment_id, MetaKeys::R2_URL, true );
		$file_path     = get_attached_file( $attachment_id );
		$local_exists  = is_string( $file_path ) && '' !== $file_path && file_exists( $file_path );

		// Get local URL.
		$local_url = null;
		if ( $local_exists ) {
			$local_url = wp_get_attachment_url( $attachment_id );
		}

		// Build CDN URL if enabled.
		$cdn_url  = null;
		$settings = get_option( Settings::OPTION_KEY, array() );
		$cdn_base = ! empty( $settings['cdn_url'] ) ? rtrim( (string) $settings['cdn_url'], '/' ) : '';
		$r2_base  = ! empty( $settings['r2_public_domain'] ) ? rtrim( (string) $settings['r2_public_domain'], '/' ) : '';

		if ( $is_offloaded && ! empty( $settings['cdn_enabled'] ) && '' !== $cdn_base ) {
			$r2_url_string = is_string( $r2_url ) ? $r2_url : '';

			// Keep original object path when converting from R2 public URL to CDN URL.
			if ( '' !== $r2_url_string && '' !== $r2_base && str_starts_with( $r2_url_string, $r2_base . '/' ) ) {
				$cdn_url = $cdn_base . substr( $r2_url_string, strlen( $r2_base ) );
			} else {
				$r2_key = get_post_meta( $attachment_id, MetaKeys::R2_KEY, true );
				if ( is_string( $r2_key ) && '' !== $r2_key ) {
					$cdn_url = $cdn_base . '/' . ltrim( $r2_key, '/' );
				}
			}
		}

		// Get attachment metadata.
		$metadata  = wp_get_attachment_metadata( $attachment_id );
		$file_size = null;
		$mime_type = get_post_mime_type( $attachment_id );

		if ( $local_exists ) {
			$size = filesize( $file_path );
			if ( false !== $size ) {
				$file_size = $size;
			}
		}

		return new WP_REST_Response(
			array(
				'id'          => $attachment_id,
				'offloaded'   => $is_offloaded,
				'urls'        => array(
					'local' => $local_url,
					'r2'    => $r2_url ? $r2_url : null,
					'cdn'   => $cdn_url,
				),
				'local_exists' => $local_exists,
				'mime_type'    => $mime_type,
				'file_size'    => $file_size,
				'width'        => $metadata['width'] ?? null,
				'height'       => $metadata['height'] ?? null,
			)
		);
	}

	/**
	 * Get usage statistics.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public static function get_stats( WP_REST_Request $request ): WP_REST_Response {
		$period        = $request->get_param( 'period' );
		$days          = 'week' === $period ? 7 : 30;
		$daily_stats   = \ThachPN165\CFR2OffLoad\Services\StatsTracker::get_daily_stats( $days );
		$current_month = \ThachPN165\CFR2OffLoad\Services\StatsTracker::get_current_month_transformations();

		// Get offload counts from status table.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregating stats requires fresh data.
		$counts = $wpdb->get_row(
			"SELECT
				COUNT(*) as total,
				SUM(CASE WHEN local_exists = 1 THEN 1 ELSE 0 END) as with_local,
				SUM(CASE WHEN local_exists = 0 THEN 1 ELSE 0 END) as r2_only
			FROM {$wpdb->prefix}cfr2_offload_status"
		);

		return new WP_REST_Response(
			array(
				'transformations' => array(
					'current_month' => $current_month,
					'daily'         => $daily_stats,
				),
				'offload'         => array(
					'total_offloaded' => (int) ( $counts->total ?? 0 ),
					'with_local'      => (int) ( $counts->with_local ?? 0 ),
					'r2_only'         => (int) ( $counts->r2_only ?? 0 ),
				),
			)
		);
	}
}
