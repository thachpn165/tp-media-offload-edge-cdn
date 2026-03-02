<?php
/**
 * Stats Widget class.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Admin\Widgets;

defined( 'ABSPATH' ) || exit;

use ThachPN165\CFR2OffLoad\Services\StatsTracker;
use ThachPN165\CFR2OffLoad\Services\CloudflareAPI;
use ThachPN165\CFR2OffLoad\Services\EncryptionService;

/**
 * StatsWidget class - displays usage statistics.
 */
class StatsWidget {

	/**
	 * Cache key for worker analytics.
	 */
	private const CACHE_KEY = 'cfr2_worker_analytics';

	/**
	 * Cache duration in seconds (1 hour).
	 */
	private const CACHE_DURATION = 3600;

	/**
	 * Render stats widget content.
	 */
	public static function render(): void {
		$settings    = get_option( 'cloudflare_r2_offload_cdn_settings', array() );
		$account_id  = $settings['r2_account_id'] ?? '';
		$worker_name = $settings['worker_name'] ?? '';
		$is_deployed = ! empty( $settings['worker_deployed'] );

		// Get analytics from Cloudflare API (cached).
		$analytics = self::get_cached_analytics( $settings );

		$total_requests = $analytics['total_requests'] ?? 0;
		?>
		<div class="cfr2-stats-widget">
			<div class="cfr2-stats-summary">
				<div class="cfr2-stat-item cfr2-stat-single">
					<span class="cfr2-stat-value"><?php echo esc_html( number_format( $total_requests ) ); ?></span>
					<span class="cfr2-stat-label"><?php esc_html_e( 'Requests This Month', 'cf-r2-offload-cdn' ); ?></span>
				</div>
			</div>

			<?php if ( ! $is_deployed ) : ?>
				<div class="cfr2-stats-notice">
					<p><?php esc_html_e( 'Deploy the Worker to see analytics.', 'cf-r2-offload-cdn' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $account_id ) ) : ?>
				<div class="cfr2-stats-footer">
					<p>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %s: Cloudflare dashboard URL */
								__( 'View detailed analytics in your <a href="%s" target="_blank" rel="noopener">Cloudflare Dashboard</a>', 'cf-r2-offload-cdn' ),
								esc_url( "https://dash.cloudflare.com/{$account_id}/workers/overview" )
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get cached Worker analytics from Cloudflare API.
	 *
	 * @param array $settings Plugin settings.
	 * @return array Analytics data.
	 */
	private static function get_cached_analytics( array $settings ): array {
		$default = array(
			'total_requests' => 0,
		);

		// Check if worker is deployed.
		if ( empty( $settings['worker_deployed'] ) || empty( $settings['worker_name'] ) ) {
			return $default;
		}

		// Check cache first.
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return $cached;
		}

		// Fetch from API.
		if ( empty( $settings['cf_api_token'] ) || empty( $settings['r2_account_id'] ) ) {
			return $default;
		}

		$encryption = EncryptionService::get_instance();
		$api_token  = $encryption->decrypt( $settings['cf_api_token'] );

		$api       = new CloudflareAPI( $api_token, $settings['r2_account_id'] );
		$analytics = $api->get_worker_analytics( $settings['worker_name'], 30 );

		if ( ! $analytics['success'] ) {
			return $default;
		}

		// Cache the result.
		set_transient( self::CACHE_KEY, $analytics, self::CACHE_DURATION );

		return $analytics;
	}

	/**
	 * Get widget data as JSON for JS charts.
	 *
	 * @return array Chart data.
	 */
	public static function get_chart_data(): array {
		$settings  = get_option( 'cloudflare_r2_offload_cdn_settings', array() );
		$analytics = self::get_cached_analytics( $settings );
		$daily     = $analytics['daily'] ?? array();

		return array(
			'labels' => array_column( $daily, 'date' ),
			'values' => array_map( 'intval', array_column( $daily, 'requests' ) ),
		);
	}
}
