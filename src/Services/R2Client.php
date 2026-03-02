<?php
/**
 * R2 Client Service class.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Services;

defined( 'ABSPATH' ) || exit;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

/**
 * R2Client class - wraps AWS SDK for Cloudflare R2 operations.
 */
class R2Client {

	/**
	 * S3Client instance.
	 *
	 * @var S3Client|null
	 */
	private ?S3Client $client = null;

	/**
	 * R2 Account ID.
	 *
	 * @var string
	 */
	private string $account_id;

	/**
	 * R2 Access Key ID.
	 *
	 * @var string
	 */
	private string $access_key;

	/**
	 * R2 Secret Access Key.
	 *
	 * @var string
	 */
	private string $secret_key;

	/**
	 * R2 Bucket Name.
	 *
	 * @var string
	 */
	private string $bucket;

	/**
	 * Constructor.
	 *
	 * @param array $credentials R2 credentials array.
	 */
	public function __construct( array $credentials ) {
		$this->account_id = $credentials['account_id'] ?? '';
		$this->access_key = $credentials['access_key_id'] ?? '';
		$this->secret_key = $credentials['secret_access_key'] ?? '';
		$this->bucket     = $credentials['bucket'] ?? '';
	}

	/**
	 * Get S3Client instance (lazy initialization).
	 *
	 * @return S3Client
	 */
	public function get_client(): S3Client {
		if ( null === $this->client ) {
			$this->client = new S3Client(
				array(
					'version'     => '2006-03-01',
					'region'      => 'auto',
					'endpoint'    => "https://{$this->account_id}.r2.cloudflarestorage.com",
					'credentials' => array(
						'key'    => $this->access_key,
						'secret' => $this->secret_key,
					),
					'http'        => array(
						'timeout'         => 30,
						'connect_timeout' => 10,
					),
				)
			);
		}
		return $this->client;
	}

	/**
	 * Test R2 connection by checking bucket access.
	 *
	 * @return array Result array with success/message.
	 */
	public function test_connection(): array {
		try {
			// Use headBucket instead of listBuckets - works with bucket-scoped tokens.
			$this->get_client()->headBucket(
				array(
					'Bucket' => $this->bucket,
				)
			);
			return array(
				'success' => true,
				'message' => __( 'Connection successful', 'cf-r2-offload-cdn' ),
			);
		} catch ( AwsException $e ) {
			return array(
				'success' => false,
				'message' => $e->getAwsErrorMessage() ?: $e->getMessage(),
			);
		}
	}

	/**
	 * Upload file to R2.
	 *
	 * @param string $local_path Local file path.
	 * @param string $r2_key     R2 object key.
	 * @return array Result array with success/url/key/message.
	 */
	public function upload_file( string $local_path, string $r2_key ): array {
		if ( ! file_exists( $local_path ) ) {
			return array(
				'success' => false,
				'message' => __( 'Local file not found', 'cf-r2-offload-cdn' ),
			);
		}

		$file_size = filesize( $local_path );

		// Validate file size (max 70MB for Cloudflare).
		if ( $file_size > 70 * 1024 * 1024 ) {
			return array(
				'success' => false,
				'message' => __( 'File exceeds 70MB limit', 'cf-r2-offload-cdn' ),
			);
		}

		try {
			$result = $this->get_client()->putObject(
				array(
					'Bucket'      => $this->bucket,
					'Key'         => $r2_key,
					'SourceFile'  => $local_path,
					'ContentType' => mime_content_type( $local_path ) ?: 'application/octet-stream',
				)
			);

			return array(
				'success' => true,
				'url'     => $result['ObjectURL'] ?? $this->build_r2_url( $r2_key ),
				'key'     => $r2_key,
			);
		} catch ( AwsException $e ) {
			return array(
				'success' => false,
				'message' => $e->getAwsErrorMessage() ?: $e->getMessage(),
			);
		}
	}

	/**
	 * Delete file from R2.
	 *
	 * @param string $r2_key R2 object key.
	 * @return array Result array with success/message.
	 */
	public function delete_file( string $r2_key ): array {
		try {
			$this->get_client()->deleteObject(
				array(
					'Bucket' => $this->bucket,
					'Key'    => $r2_key,
				)
			);
			return array( 'success' => true );
		} catch ( AwsException $e ) {
			return array(
				'success' => false,
				'message' => $e->getAwsErrorMessage() ?: $e->getMessage(),
			);
		}
	}

	/**
	 * Check if file exists in R2.
	 *
	 * @param string $r2_key R2 object key.
	 * @return bool True if exists, false otherwise.
	 */
	public function file_exists( string $r2_key ): bool {
		try {
			$this->get_client()->headObject(
				array(
					'Bucket' => $this->bucket,
					'Key'    => $r2_key,
				)
			);
			return true;
		} catch ( AwsException $e ) {
			return false;
		}
	}

	/**
	 * Download file from R2 to local path.
	 *
	 * @param string $r2_key     R2 object key.
	 * @param string $local_path Local destination path.
	 * @return array Result array with success/message.
	 */
	public function download_file( string $r2_key, string $local_path ): array {
		try {
			// Ensure directory exists.
			$dir = dirname( $local_path );
			if ( ! file_exists( $dir ) ) {
				wp_mkdir_p( $dir );
			}

			$this->get_client()->getObject(
				array(
					'Bucket' => $this->bucket,
					'Key'    => $r2_key,
					'SaveAs' => $local_path,
				)
			);

			return array(
				'success' => true,
				'path'    => $local_path,
			);
		} catch ( AwsException $e ) {
			return array(
				'success' => false,
				'message' => $e->getAwsErrorMessage() ?: $e->getMessage(),
			);
		}
	}

	/**
	 * Build R2 URL for a given key.
	 *
	 * @param string $key R2 object key.
	 * @return string R2 URL.
	 */
	private function build_r2_url( string $key ): string {
		return "https://{$this->bucket}.{$this->account_id}.r2.cloudflarestorage.com/{$key}";
	}
}
