<?php
/**
 * Stats Tracker Service class.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Services;

defined( 'ABSPATH' ) || exit;

/**
 * StatsTracker class - tracks transformation counts.
 */
class StatsTracker {

	/**
	 * Table suffix.
	 */
	private const TABLE_SUFFIX = 'cfr2_stats';

	/**
	 * Get full stats table name.
	 *
	 * @param \wpdb $wpdb WordPress database object.
	 * @return string
	 */
	private static function get_table_name( \wpdb $wpdb ): string {
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Increment transformation count for today.
	 * Uses INSERT ON DUPLICATE KEY UPDATE for atomicity.
	 *
	 * @param int $count Count to increment.
	 */
	public static function increment( int $count = 1 ): void {
		global $wpdb;
		$table = self::get_table_name( $wpdb );
		$today = current_time( 'Y-m-d' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from wpdb prefix and fixed suffix.
				"INSERT INTO {$table} (date, transformations, bandwidth_bytes)
				 VALUES (%s, %d, 0)
				 ON DUPLICATE KEY UPDATE transformations = transformations + %d",
				$today,
				$count,
				$count
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Track bandwidth (for future use).
	 *
	 * @param int $bytes Bytes to track.
	 */
	public static function track_bandwidth( int $bytes ): void {
		global $wpdb;
		$table = self::get_table_name( $wpdb );
		$today = current_time( 'Y-m-d' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from wpdb prefix and fixed suffix.
				"INSERT INTO {$table} (date, transformations, bandwidth_bytes)
				 VALUES (%s, 0, %d)
				 ON DUPLICATE KEY UPDATE bandwidth_bytes = bandwidth_bytes + %d",
				$today,
				$bytes,
				$bytes
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Get stats for date range.
	 *
	 * @param string $start_date Start date (Y-m-d).
	 * @param string $end_date End date (Y-m-d).
	 * @return array Stats array.
	 */
	public static function get_stats( string $start_date, string $end_date ): array {
		global $wpdb;
		$table = self::get_table_name( $wpdb );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from wpdb prefix and fixed suffix.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT date, transformations, bandwidth_bytes
					 FROM {$table}
					 WHERE date BETWEEN %s AND %s
					 ORDER BY date ASC",
				$start_date,
				$end_date
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		return $results ?: array();
	}

	/**
	 * Get monthly summary.
	 *
	 * @param int $year Year.
	 * @param int $month Month (1-12).
	 * @return array Summary with transformations, bandwidth, days_active.
	 */
	public static function get_monthly_summary( int $year, int $month ): array {
		global $wpdb;
		$table = self::get_table_name( $wpdb );

		$start = sprintf( '%04d-%02d-01', $year, $month );
		$end   = gmdate( 'Y-m-t', strtotime( $start ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from wpdb prefix and fixed suffix.
		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
						SUM(transformations) as total_transformations,
						SUM(bandwidth_bytes) as total_bandwidth,
						COUNT(DISTINCT date) as days_with_activity
					 FROM {$table}
					 WHERE date BETWEEN %s AND %s",
				$start,
				$end
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		return array(
			'transformations' => (int) ( $result['total_transformations'] ?? 0 ),
			'bandwidth'       => (int) ( $result['total_bandwidth'] ?? 0 ),
			'days_active'     => (int) ( $result['days_with_activity'] ?? 0 ),
		);
	}

	/**
	 * Get current month transformations.
	 *
	 * @return int Transformation count.
	 */
	public static function get_current_month_transformations(): int {
		$summary = self::get_monthly_summary(
			(int) wp_date( 'Y' ),
			(int) wp_date( 'n' )
		);
		return $summary['transformations'];
	}

	/**
	 * Get last N days stats for chart.
	 *
	 * @param int $days Number of days.
	 * @return array Daily stats.
	 */
	public static function get_daily_stats( int $days = 30 ): array {
		$end   = current_time( 'Y-m-d' );
		$start = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		return self::get_stats( $start, $end );
	}

	/**
	 * Clean old stats (older than 90 days).
	 */
	public static function cleanup_old_stats(): void {
		global $wpdb;
		$table  = self::get_table_name( $wpdb );
		$cutoff = gmdate( 'Y-m-d', strtotime( '-90 days' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from wpdb prefix and fixed suffix.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE date < %s",
				$cutoff
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}
}
