<?php
/**
 * Bulk Item Processor Service class.
 *
 * Generic processor for bulk queue items.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Services;

defined( 'ABSPATH' ) || exit;

use ThachPN165\CFR2OffLoad\Constants\QueueAction;
use ThachPN165\CFR2OffLoad\Traits\CredentialsHelperTrait;

/**
 * BulkItemProcessor class - processes bulk queue items.
 */
class BulkItemProcessor {

	use CredentialsHelperTrait;

	/**
	 * QueueManager instance.
	 *
	 * @var QueueManager
	 */
	private QueueManager $queue_manager;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->queue_manager = new QueueManager();
	}

	/**
	 * Process single queue item.
	 *
	 * @param object $item   Queue item from database.
	 * @param string $action Action type (offload|restore|delete_local).
	 * @return array Response array with success/error.
	 */
	public function process_item( object $item, string $action ): array {
		$this->queue_manager->mark_processing( $item->id );

		$attachment_id = (int) $item->attachment_id;
		$file_path     = get_attached_file( $attachment_id );
		$filename      = $file_path ? basename( $file_path ) : "ID: {$attachment_id}";

		$credentials = self::get_r2_credentials();
		if ( empty( $credentials['secret_access_key'] ) ) {
			return $this->handle_failure(
				$item->id,
				$attachment_id,
				$filename,
				__( 'R2 credentials not configured.', 'cf-r2-offload-cdn' )
			);
		}

		$offload = new OffloadService( new R2Client( $credentials ) );
		$result  = $this->execute_action( $offload, $attachment_id, $action );

		$this->update_queue_and_log( $item->id, $attachment_id, $result );

		return array(
			'status'   => $result['success'] ? 'success' : 'error',
			'filename' => $filename,
			'message'  => $this->format_response_message( $result, $action ),
		);
	}

	/**
	 * Handle item failure.
	 *
	 * @param int    $item_id       Queue item ID.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $filename      File name.
	 * @param string $error_msg     Error message.
	 * @return array Error response.
	 */
	private function handle_failure( int $item_id, int $attachment_id, string $filename, string $error_msg ): array {
		$this->queue_manager->mark_failed( $item_id, $error_msg );
		BulkOperationLogger::log( $attachment_id, 'error', $error_msg );

		return array(
			'status'   => 'error',
			'filename' => $filename,
			'message'  => $error_msg,
		);
	}

	/**
	 * Update queue status and log result.
	 *
	 * @param int   $item_id       Queue item ID.
	 * @param int   $attachment_id Attachment ID.
	 * @param array $result        Processing result.
	 */
	private function update_queue_and_log( int $item_id, int $attachment_id, array $result ): void {
		$log_type = $result['success'] ? 'success' : 'error';

		if ( $result['success'] ) {
			$this->queue_manager->mark_completed( $item_id, $result['message'] );
		} else {
			$this->queue_manager->mark_failed( $item_id, $result['message'] );
		}

		BulkOperationLogger::log( $attachment_id, $log_type, $result['message'] );
	}

	/**
	 * Execute action-specific processing.
	 *
	 * @param OffloadService $offload      OffloadService instance.
	 * @param int            $attachment_id Attachment ID.
	 * @param string         $action        Action type.
	 * @return array Result array with success and message.
	 */
	private function execute_action( OffloadService $offload, int $attachment_id, string $action ): array {
		switch ( $action ) {
			case QueueAction::OFFLOAD:
				return $offload->offload( $attachment_id );

			case QueueAction::RESTORE:
				return $offload->restore( $attachment_id );

			case QueueAction::DELETE_LOCAL:
				return $offload->delete_local_files( $attachment_id );

			default:
				return array(
					'success' => false,
					'message' => sprintf( 'Unknown action: %s', $action ),
				);
		}
	}

	/**
	 * Format response message based on action and result.
	 *
	 * @param array  $result Result from action execution.
	 * @param string $action Action type.
	 * @return string Formatted message.
	 */
	private function format_response_message( array $result, string $action ): string {
		if ( ! $result['success'] ) {
			return $result['message'] ?? __( 'Unknown error', 'cf-r2-offload-cdn' );
		}

		switch ( $action ) {
			case QueueAction::OFFLOAD:
				$message = __( 'Offloaded to R2', 'cf-r2-offload-cdn' );
				if ( ! empty( $result['thumbnails']['total'] ) ) {
					$message .= sprintf(
						' (+%d/%d thumbnails)',
						$result['thumbnails']['success'],
						$result['thumbnails']['total']
					);
				}
				return $message;

			case QueueAction::RESTORE:
				return __( 'Restored to local', 'cf-r2-offload-cdn' );

			case QueueAction::DELETE_LOCAL:
				if ( ! empty( $result['deleted_main'] ) ) {
					return sprintf(
						/* translators: %d: number of deleted thumbnail files */
						__( 'Deleted local files (+%d thumbnails)', 'cf-r2-offload-cdn' ),
						$result['deleted_thumbs'] ?? 0
					);
				}
				return __( 'No local files to delete', 'cf-r2-offload-cdn' );

			default:
				return $result['message'] ?? __( 'Completed', 'cf-r2-offload-cdn' );
		}
	}
}
