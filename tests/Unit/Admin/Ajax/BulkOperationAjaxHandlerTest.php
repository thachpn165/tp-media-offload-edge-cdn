<?php
/**
 * BulkOperationAjaxHandler Unit Tests
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Tests\Unit\Admin\Ajax;

use PHPUnit\Framework\TestCase;
use ThachPN165\CFR2OffLoad\Admin\Ajax\BulkOperationAjaxHandler;
use ThachPN165\CFR2OffLoad\Services\QueueManager;

/**
 * BulkOperationAjaxHandlerTest class.
 */
class BulkOperationAjaxHandlerTest extends TestCase {

	/**
	 * Original wpdb instance.
	 *
	 * @var object
	 */
	private object $original_wpdb;

	/**
	 * Setup test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		cfr2_test_reset_wp_state();
		$this->original_wpdb = $GLOBALS['wpdb'];
	}

	/**
	 * Restore original wpdb.
	 */
	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb;
		parent::tearDown();
	}

	/**
	 * Test non-offloaded enqueue runs in batches and skips pending duplicates.
	 */
	public function test_enqueue_non_offloaded_attachments_batches_and_skips_pending_items(): void {
		$handler       = new BulkOperationAjaxHandler();
		$queue_manager = new class extends QueueManager {
			public array $enqueued_ids = array();

			public function enqueue( int $attachment_id, string $action ): bool {
				$this->enqueued_ids[] = $attachment_id;
				return true;
			}
		};

		$wpdb = new class {
			public string $prefix   = 'wp_';
			public string $posts    = 'wp_posts';
			public string $postmeta = 'wp_postmeta';
			public array $offload_batches = array(
				array(),
				array(),
			);
			public array $pending_ids = array( 2, 501 );

			public function __construct() {
				$this->offload_batches[0] = range( 1, 500 );
				$this->offload_batches[1] = array( 501, 502 );
			}

			public function prepare( $query, ...$args ) {
				return $query;
			}

			public function get_col( $query ) {
				if ( false !== strpos( $query, 'SELECT p.ID' ) ) {
					return array_shift( $this->offload_batches );
				}

				if ( false !== strpos( $query, 'SELECT attachment_id FROM' ) ) {
					preg_match( '/IN \(([^)]+)\)/', $query, $matches );
					$ids = array();
					if ( ! empty( $matches[1] ) ) {
						foreach ( explode( ',', $matches[1] ) as $id ) {
							$ids[] = (int) trim( $id );
						}
					}
					return array_values( array_intersect( $this->pending_ids, $ids ) );
				}

				return array();
			}
		};
		$GLOBALS['wpdb'] = $wpdb;

		$reflection = new \ReflectionClass( $handler );

		$queue_property = $reflection->getProperty( 'queue_manager' );
		$queue_property->setAccessible( true );
		$queue_property->setValue( $handler, $queue_manager );

		$method = $reflection->getMethod( 'enqueue_non_offloaded_attachments' );
		$method->setAccessible( true );
		$queued_total = $method->invoke( $handler );

		$this->assertSame( 500, $queued_total );
		$this->assertCount( 500, $queue_manager->enqueued_ids );
		$this->assertContains( 1, $queue_manager->enqueued_ids );
		$this->assertContains( 500, $queue_manager->enqueued_ids );
		$this->assertContains( 502, $queue_manager->enqueued_ids );
		$this->assertNotContains( 2, $queue_manager->enqueued_ids );
		$this->assertNotContains( 501, $queue_manager->enqueued_ids );
	}
}
