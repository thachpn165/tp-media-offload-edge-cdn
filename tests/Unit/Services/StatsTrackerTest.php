<?php
/**
 * StatsTracker Unit Tests
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use ThachPN165\CFR2OffLoad\Services\StatsTracker;

/**
 * StatsTrackerTest class - tests stats tracking logic.
 */
class StatsTrackerTest extends TestCase {

	/**
	 * Original wpdb instance.
	 *
	 * @var object
	 */
	private object $original_wpdb;

	/**
	 * Fake wpdb instance.
	 *
	 * @var object
	 */
	private object $fake_wpdb;

	/**
	 * Setup test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		cfr2_test_reset_wp_state();

		$this->original_wpdb = $GLOBALS['wpdb'];
		$this->fake_wpdb     = new class {
			public string $prefix = 'wp_';
			public array $queries = array();
			public array $rows = array();
			public array $results = array();

			public function prepare( $query, ...$args ) {
				return $query;
			}

			public function query( $query ) {
				$this->queries[] = $query;
				return 1;
			}

			public function get_row( $query, $output = OBJECT ) {
				$this->queries[] = $query;
				return array_shift( $this->rows );
			}

			public function get_results( $query, $output = OBJECT ) {
				$this->queries[] = $query;
				return $this->results;
			}
		};

		$GLOBALS['wpdb'] = $this->fake_wpdb;
	}

	/**
	 * Restore global wpdb.
	 */
	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb;
		parent::tearDown();
	}

	/**
	 * Test StatsTracker class exists.
	 */
	public function test_stats_tracker_class_exists(): void {
		$this->assertTrue( class_exists( StatsTracker::class ) );
	}

	/**
	 * Test static methods are callable.
	 */
	public function test_static_methods_callable(): void {
		$this->assertTrue( method_exists( StatsTracker::class, 'increment' ) );
		$this->assertTrue( method_exists( StatsTracker::class, 'get_monthly_summary' ) );
		$this->assertTrue( method_exists( StatsTracker::class, 'cleanup_old_stats' ) );
	}

	/**
	 * Test increment writes an atomic insert/update statement.
	 */
	public function test_increment_executes_insert_update_query(): void {
		StatsTracker::increment( 3 );

		$this->assertNotEmpty( $this->fake_wpdb->queries );
		$this->assertStringContainsString( 'INSERT INTO wp_cfr2_stats', $this->fake_wpdb->queries[0] );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $this->fake_wpdb->queries[0] );
	}

	/**
	 * Test get_stats returns data provided by db layer.
	 */
	public function test_get_stats_returns_result_rows(): void {
		$this->fake_wpdb->results = array(
			array(
				'date'            => '2026-03-01',
				'transformations' => '10',
				'bandwidth_bytes' => '2048',
			),
		);

		$stats = StatsTracker::get_stats( '2026-03-01', '2026-03-02' );

		$this->assertCount( 1, $stats );
		$this->assertSame( '2026-03-01', $stats[0]['date'] );
	}

	/**
	 * Test monthly summary returns safe zero defaults when db returns null.
	 */
	public function test_get_monthly_summary_handles_empty_db_row(): void {
		$this->fake_wpdb->rows = array( null );

		$summary = StatsTracker::get_monthly_summary( 2026, 3 );

		$this->assertSame(
			array(
				'transformations' => 0,
				'bandwidth'       => 0,
				'days_active'     => 0,
			),
			$summary
		);
	}

	/**
	 * Test cleanup_old_stats issues delete statement.
	 */
	public function test_cleanup_old_stats_executes_delete_query(): void {
		StatsTracker::cleanup_old_stats();

		$this->assertNotEmpty( $this->fake_wpdb->queries );
		$last_query = end( $this->fake_wpdb->queries );
		$this->assertStringContainsString( 'DELETE FROM wp_cfr2_stats', $last_query );
	}
}
