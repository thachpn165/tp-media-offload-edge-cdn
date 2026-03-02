<?php
/**
 * Cloudflare API Service class.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Services;

defined( 'ABSPATH' ) || exit;

/**
 * CloudflareAPI class - handles Workers REST API operations.
 */
class CloudflareAPI {

	/**
	 * Cloudflare API base URL.
	 */
	private const API_BASE = 'https://api.cloudflare.com/client/v4';

	/**
	 * API token for authentication.
	 *
	 * @var string
	 */
	private string $api_token;

	/**
	 * Cloudflare account ID.
	 *
	 * @var string
	 */
	private string $account_id;

	/**
	 * Constructor.
	 *
	 * @param string $api_token API token.
	 * @param string $account_id Account ID.
	 */
	public function __construct( string $api_token, string $account_id ) {
		$this->api_token  = $api_token;
		$this->account_id = $account_id;
	}

	/**
	 * Upload a new version of Worker script.
	 *
	 * @param string $worker_name Worker name.
	 * @param string $script Script content.
	 * @param array  $bindings Environment bindings.
	 * @return array Response array.
	 */
	public function upload_version( string $worker_name, string $script, array $bindings = array() ): array {
		$metadata = array(
			'main_module'        => 'worker.js',
			'compatibility_date' => '2024-01-01',
			'bindings'           => $bindings,
		);

		// Multipart form data.
		$boundary = wp_generate_uuid4();
		$body     = $this->build_multipart_body(
			$boundary,
			array(
				array(
					'name'    => 'metadata',
					'content' => wp_json_encode( $metadata ),
					'type'    => 'application/json',
				),
				array(
					'name'     => 'worker.js',
					'filename' => 'worker.js', // Required for ES modules.
					'content'  => $script,
					'type'     => 'application/javascript+module',
				),
			)
		);

		return $this->request(
			'PUT',
			"/accounts/{$this->account_id}/workers/scripts/{$worker_name}",
			null,
			array( 'Content-Type' => "multipart/form-data; boundary={$boundary}" ),
			$body
		);
	}

	/**
	 * Deploy Worker to production (enable subdomain).
	 *
	 * @param string $worker_name Worker name.
	 * @return array Response array.
	 */
	public function deploy_worker( string $worker_name ): array {
		return $this->request(
			'PUT',
			"/accounts/{$this->account_id}/workers/scripts/{$worker_name}/subdomain",
			array( 'enabled' => true )
		);
	}

	/**
	 * Configure route for Worker.
	 *
	 * @param string $zone_id     Zone ID.
	 * @param string $pattern     Route pattern.
	 * @param string $worker_name Worker name.
	 * @return array Response array.
	 */
	public function configure_route( string $zone_id, string $pattern, string $worker_name ): array {
		return $this->request(
			'POST',
			"/zones/{$zone_id}/workers/routes",
			array(
				'pattern' => $pattern,
				'script'  => $worker_name,
			)
		);
	}

	/**
	 * Get zone ID by domain name.
	 *
	 * @param string $domain Domain name.
	 * @return string|null Zone ID or null if not found.
	 */
	public function get_zone_id( string $domain ): ?string {
		$response = $this->request( 'GET', '/zones', array( 'name' => $domain ) );

		if ( $response['success'] && ! empty( $response['result'] ) ) {
			return $response['result'][0]['id'];
		}

		return null;
	}

	/**
	 * Delete Worker.
	 *
	 * @param string $worker_name Worker name.
	 * @return array Response array.
	 */
	public function delete_worker( string $worker_name ): array {
		return $this->request(
			'DELETE',
			"/accounts/{$this->account_id}/workers/scripts/{$worker_name}"
		);
	}

	/**
	 * Get Worker status.
	 *
	 * @param string $worker_name Worker name.
	 * @return array Response array.
	 */
	public function get_worker_status( string $worker_name ): array {
		return $this->request(
			'GET',
			"/accounts/{$this->account_id}/workers/scripts/{$worker_name}"
		);
	}

	/**
	 * Verify API token has required permissions.
	 *
	 * @return array Response array.
	 */
	public function verify_token(): array {
		return $this->request( 'GET', '/user/tokens/verify' );
	}

	/**
	 * Get DNS record by name.
	 *
	 * @param string $zone_id Zone ID.
	 * @param string $name    Record name (e.g., cdn.example.com).
	 * @param string $type    Record type (default: CNAME).
	 * @return array|null DNS record or null if not found.
	 */
	public function get_dns_record( string $zone_id, string $name, string $type = 'CNAME' ): ?array {
		$response = $this->request(
			'GET',
			"/zones/{$zone_id}/dns_records",
			array(
				'name' => $name,
				'type' => $type,
			)
		);

		if ( $response['success'] && ! empty( $response['result'] ) ) {
			return $response['result'][0];
		}

		return null;
	}

	/**
	 * Create DNS record.
	 *
	 * @param string $zone_id Zone ID.
	 * @param array  $data    Record data (name, type, content, proxied).
	 * @return array Response array.
	 */
	public function create_dns_record( string $zone_id, array $data ): array {
		return $this->request(
			'POST',
			"/zones/{$zone_id}/dns_records",
			$data
		);
	}

	/**
	 * Update DNS record.
	 *
	 * @param string $zone_id   Zone ID.
	 * @param string $record_id Record ID.
	 * @param array  $data      Record data.
	 * @return array Response array.
	 */
	public function update_dns_record( string $zone_id, string $record_id, array $data ): array {
		return $this->request(
			'PATCH',
			"/zones/{$zone_id}/dns_records/{$record_id}",
			$data
		);
	}

	/**
	 * Validate and setup DNS record for CDN domain.
	 *
	 * @param string $cdn_url Full CDN URL (e.g., https://cdn.example.com).
	 * @return array Result with status and warnings.
	 */
	public function validate_cdn_dns( string $cdn_url ): array {
		$parsed = wp_parse_url( $cdn_url );
		if ( empty( $parsed['host'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid CDN URL', 'cf-r2-offload-cdn' ),
			);
		}

		$cdn_host = $parsed['host'];

		// Extract base domain for zone lookup.
		$parts       = explode( '.', $cdn_host );
		$base_domain = implode( '.', array_slice( $parts, -2 ) );

		// Get zone ID.
		$zone_id = $this->get_zone_id( $base_domain );
		if ( ! $zone_id ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: domain name */
					__( 'Domain "%s" not found in your Cloudflare account', 'cf-r2-offload-cdn' ),
					$base_domain
				),
			);
		}

		// Check for existing DNS record.
		$record = $this->get_dns_record( $zone_id, $cdn_host, 'CNAME' );
		if ( ! $record ) {
			// Try A record.
			$record = $this->get_dns_record( $zone_id, $cdn_host, 'A' );
		}

		$warnings = array();

		if ( $record ) {
			// Record exists - check configuration.
			if ( empty( $record['proxied'] ) ) {
				$warnings[] = __( 'DNS record exists but Cloudflare proxy is DISABLED. Worker will NOT work without proxy.', 'cf-r2-offload-cdn' );
			}

			return array(
				'success'   => true,
				'action'    => 'exists',
				'record'    => $record,
				'warnings'  => $warnings,
				'zone_id'   => $zone_id,
				'record_id' => $record['id'],
			);
		}

		// Record doesn't exist - create it.
		$create_result = $this->create_dns_record(
			$zone_id,
			array(
				'type'    => 'A',
				'name'    => $cdn_host,
				'content' => '192.0.2.1', // Dummy IP (TEST-NET-1, safe placeholder).
				'proxied' => true,
				'ttl'     => 1, // Auto TTL.
			)
		);

		if ( $create_result['success'] ) {
			return array(
				'success' => true,
				'action'  => 'created',
				'record'  => $create_result['result'],
				'message' => sprintf(
					/* translators: %s: CDN hostname */
					__( 'DNS record created for %s with Cloudflare proxy enabled.', 'cf-r2-offload-cdn' ),
					$cdn_host
				),
				'zone_id' => $zone_id,
			);
		}

		return array(
			'success' => false,
			'message' => $create_result['errors'][0]['message'] ?? __( 'Failed to create DNS record', 'cf-r2-offload-cdn' ),
		);
	}

	/**
	 * Enable proxy on existing DNS record.
	 *
	 * @param string $zone_id   Zone ID.
	 * @param string $record_id Record ID.
	 * @return array Response array.
	 */
	public function enable_dns_proxy( string $zone_id, string $record_id ): array {
		return $this->update_dns_record(
			$zone_id,
			$record_id,
			array( 'proxied' => true )
		);
	}

	/**
	 * Get Worker analytics from Cloudflare GraphQL API.
	 *
	 * @param string $worker_name Worker script name.
	 * @param int    $days        Number of days to fetch (default 30).
	 * @return array Analytics data with requests, cpuTime, etc.
	 */
	public function get_worker_analytics( string $worker_name, int $days = 30 ): array {
		$start_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$end_date   = gmdate( 'Y-m-d' );

		// GraphQL query for Workers Analytics.
		// phpcs:ignore Squiz.Strings.ConcatenationSpacing.PaddingFound -- Multiline GraphQL query for readability.
		$query = 'query GetWorkerAnalytics($accountId: String!, $scriptName: String!, $startDate: Date!, $endDate: Date!) { ' .
			'viewer { ' .
				'accounts(filter: {accountTag: $accountId}) { ' .
					'workersInvocationsAdaptive(' .
						'filter: {' .
							'scriptName: $scriptName,' .
							'date_geq: $startDate,' .
							'date_leq: $endDate' .
						'},' .
						'limit: 10000' .
					') { ' .
						'sum { requests subrequests errors } ' .
						'quantiles { cpuTimeP50 cpuTimeP99 } ' .
						'dimensions { date } ' .
					'} ' .
				'} ' .
			'} ' .
		'}';

		$variables = array(
			'accountId'  => $this->account_id,
			'scriptName' => $worker_name,
			'startDate'  => $start_date,
			'endDate'    => $end_date,
		);

		$response = $this->graphql_request( $query, $variables );

		if ( ! $response['success'] ) {
			return $response;
		}

		// Parse the response.
		$data   = $response['data']['viewer']['accounts'][0]['workersInvocationsAdaptive'] ?? array();
		$result = array(
			'success'            => true,
			'total_requests'     => 0,
			'total_errors'       => 0,
			'cpu_time_p50_avg'   => 0,
			'cpu_time_p99_avg'   => 0,
			'daily'              => array(),
		);

		if ( empty( $data ) ) {
			return $result;
		}

		$cpu_p50_sum = 0;
		$cpu_p99_sum = 0;
		$count       = 0;

		foreach ( $data as $entry ) {
			$result['total_requests'] += (int) ( $entry['sum']['requests'] ?? 0 );
			$result['total_errors']   += (int) ( $entry['sum']['errors'] ?? 0 );

			$cpu_p50 = (float) ( $entry['quantiles']['cpuTimeP50'] ?? 0 );
			$cpu_p99 = (float) ( $entry['quantiles']['cpuTimeP99'] ?? 0 );
			$cpu_p50_sum += $cpu_p50;
			$cpu_p99_sum += $cpu_p99;

			$result['daily'][] = array(
				'date'         => $entry['dimensions']['date'] ?? '',
				'requests'     => (int) ( $entry['sum']['requests'] ?? 0 ),
				'errors'       => (int) ( $entry['sum']['errors'] ?? 0 ),
				'cpu_time_p50' => $cpu_p50,
				'cpu_time_p99' => $cpu_p99,
			);
			++$count;
		}

		if ( $count > 0 ) {
			$result['cpu_time_p50_avg'] = round( $cpu_p50_sum / $count, 2 );
			$result['cpu_time_p99_avg'] = round( $cpu_p99_sum / $count, 2 );
		}

		return $result;
	}

	/**
	 * Make GraphQL API request.
	 *
	 * @param string $query     GraphQL query.
	 * @param array  $variables Query variables.
	 * @return array Response array.
	 */
	private function graphql_request( string $query, array $variables = array() ): array {
		$url = self::API_BASE . '/graphql';

		$args = array(
			'method'  => 'POST',
			'timeout' => 60,
			'headers' => array(
				'Authorization' => "Bearer {$this->api_token}",
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode(
				array(
					'query'     => $query,
					'variables' => $variables,
				)
			),
		);

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'errors'  => array( array( 'message' => $response->get_error_message() ) ),
			);
		}

		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( ! $decoded ) {
			return array(
				'success' => false,
				'errors'  => array( array( 'message' => 'Invalid JSON response' ) ),
			);
		}

		if ( ! empty( $decoded['errors'] ) ) {
			return array(
				'success' => false,
				'errors'  => $decoded['errors'],
			);
		}

		return array(
			'success' => true,
			'data'    => $decoded['data'] ?? array(),
		);
	}

	/**
	 * Make API request.
	 *
	 * @param string      $method HTTP method.
	 * @param string      $endpoint API endpoint.
	 * @param array|null  $data Request data.
	 * @param array       $headers Additional headers.
	 * @param string|null $raw_body Raw body content.
	 * @return array Response array.
	 */
	private function request(
		string $method,
		string $endpoint,
		?array $data = null,
		array $headers = array(),
		?string $raw_body = null
	): array {
		$url = self::API_BASE . $endpoint;

		// Add query params for GET requests.
		if ( 'GET' === $method && $data ) {
			$url .= '?' . http_build_query( $data );
			$data = null;
		}

		$args = array(
			'method'  => $method,
			'timeout' => 60,
			'headers' => array_merge(
				array(
					'Authorization' => "Bearer {$this->api_token}",
					'Content-Type'  => 'application/json',
				),
				$headers
			),
		);

		if ( $raw_body ) {
			$args['body'] = $raw_body;
		} elseif ( $data && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$args['body'] = wp_json_encode( $data );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'errors'  => array( array( 'message' => $response->get_error_message() ) ),
			);
		}

		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( ! $decoded ) {
			return array(
				'success' => false,
				'errors'  => array( array( 'message' => 'Invalid JSON response' ) ),
			);
		}

		return $decoded;
	}

	/**
	 * Build multipart form body.
	 *
	 * @param string $boundary Boundary string.
	 * @param array  $parts Parts array.
	 * @return string Multipart body.
	 */
	private function build_multipart_body( string $boundary, array $parts ): string {
		$body = '';

		foreach ( $parts as $part ) {
			$body .= "--{$boundary}\r\n";
			$body .= "Content-Disposition: form-data; name=\"{$part['name']}\"";

			if ( isset( $part['filename'] ) ) {
				$body .= "; filename=\"{$part['filename']}\"";
			}

			$body .= "\r\n";
			$body .= "Content-Type: {$part['type']}\r\n\r\n";
			$body .= $part['content'] . "\r\n";
		}

		$body .= "--{$boundary}--\r\n";

		return $body;
	}
}
