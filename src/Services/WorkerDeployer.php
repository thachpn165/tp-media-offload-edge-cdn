<?php
/**
 * Worker Deployer Service class.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Services;

defined( 'ABSPATH' ) || exit;

/**
 * WorkerDeployer class - orchestrates Worker deployment.
 */
class WorkerDeployer {

	/**
	 * CloudflareAPI instance.
	 *
	 * @var CloudflareAPI
	 */
	private CloudflareAPI $api;

	/**
	 * Worker name.
	 *
	 * @var string
	 */
	private string $worker_name;

	/**
	 * Constructor.
	 *
	 * @param CloudflareAPI $api Cloudflare API instance.
	 */
	public function __construct( CloudflareAPI $api ) {
		$this->api         = $api;
		$this->worker_name = 'cfr2-image-transform';
	}

	/**
	 * Full deployment flow.
	 *
	 * @param array $config Configuration array.
	 * @return array Result array.
	 */
	public function deploy( array $config ): array {
		$steps = array();

		// Step 1: Verify token.
		$verify = $this->api->verify_token();
		if ( ! $verify['success'] ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid API token', 'cf-r2-offload-cdn' ),
				'steps'   => $steps,
			);
		}
		$steps[] = array(
			'step'   => 'verify_token',
			'status' => 'success',
		);

		// Step 2: Get Worker script.
		$script = $this->get_worker_script( $config );

		// Step 3: Upload script (creates Worker if not exists).
		$bindings = $this->get_bindings( $config );
		$upload   = $this->api->upload_version( $this->worker_name, $script, $bindings );

		if ( ! $upload['success'] ) {
			$error_msg = $upload['errors'][0]['message'] ?? 'Unknown error';
			$steps[]   = array(
				'step'   => 'upload_script',
				'status' => 'failed',
				'error'  => $error_msg,
			);
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Failed to upload Worker script: %s', 'cf-r2-offload-cdn' ),
					$error_msg
				),
				'steps'   => $steps,
			);
		}
		$steps[] = array(
			'step'   => 'upload_script',
			'status' => 'success',
		);

		// Step 4: Enable subdomain (simple deployment).
		$deploy  = $this->api->deploy_worker( $this->worker_name );
		$steps[] = array(
			'step'   => 'deploy',
			'status' => $deploy['success'] ? 'success' : 'warning',
		);

		// Step 5: Validate/Create DNS record if custom domain provided.
		$warnings = array();
		if ( ! empty( $config['custom_domain'] ) ) {
			$dns_result = $this->api->validate_cdn_dns( $config['custom_domain'] );
			$steps[]    = array(
				'step'   => 'dns_validation',
				'status' => $dns_result['success'] ? 'success' : 'warning',
				'action' => $dns_result['action'] ?? null,
			);

			if ( ! empty( $dns_result['warnings'] ) ) {
				$warnings = array_merge( $warnings, $dns_result['warnings'] );
			}

			// Step 6: Configure route.
			if ( $dns_result['success'] ) {
				$route_result = $this->configure_route( $config['custom_domain'] );
				$steps[]      = array(
					'step'   => 'configure_route',
					'status' => $route_result['success'] ? 'success' : 'failed',
					'route'  => $route_result['pattern'] ?? null,
				);
			}
		}

		return array(
			'success'     => true,
			'message'     => __( 'Worker deployed successfully', 'cf-r2-offload-cdn' ),
			'worker_name' => $this->worker_name,
			'steps'       => $steps,
			'warnings'    => $warnings,
		);
	}

	/**
	 * Get Worker status.
	 *
	 * @return array Status array.
	 */
	public function get_status(): array {
		return $this->api->get_worker_status( $this->worker_name );
	}

	/**
	 * Remove Worker.
	 *
	 * @return array Result array.
	 */
	public function undeploy(): array {
		return $this->api->delete_worker( $this->worker_name );
	}

	/**
	 * Generate Worker script from template.
	 *
	 * @param array $config Configuration array.
	 * @return string Worker script.
	 */
	private function get_worker_script( array $config ): string {
		$template_path = \CFR2_PATH . 'src/Templates/worker-script.js';
		$script        = file_get_contents( $template_path );

		// Replace placeholders.
		$script = str_replace( '{{VERSION}}', \CFR2_VERSION, $script );

		return $script;
	}

	/**
	 * Get Worker environment bindings.
	 *
	 * @param array $config Configuration array.
	 * @return array Bindings array.
	 */
	private function get_bindings( array $config ): array {
		$bindings = array();

		// R2 bucket binding (direct access - faster, no public access needed).
		if ( ! empty( $config['r2_bucket'] ) ) {
			$bindings[] = array(
				'type'        => 'r2_bucket',
				'name'        => 'BUCKET',
				'bucket_name' => $config['r2_bucket'],
			);
		}

		// Image format setting (original, webp, avif).
		$bindings[] = array(
			'type' => 'plain_text',
			'name' => 'IMAGE_FORMAT',
			'text' => $config['image_format'] ?? 'webp',
		);

		return $bindings;
	}

	/**
	 * Configure route for custom domain.
	 *
	 * @param string $domain Custom domain.
	 * @return array Result array.
	 */
	private function configure_route( string $domain ): array {
		// Extract base domain for zone lookup.
		$parts       = explode( '.', $domain );
		$base_domain = implode( '.', array_slice( $parts, -2 ) );

		// Get zone ID.
		$zone_id = $this->api->get_zone_id( $base_domain );
		if ( ! $zone_id ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: domain name */
					__( 'Zone not found for domain: %s', 'cf-r2-offload-cdn' ),
					$base_domain
				),
			);
		}

		// Configure route pattern.
		$pattern = "{$domain}/*";
		$result  = $this->api->configure_route( $zone_id, $pattern, $this->worker_name );

		if ( $result['success'] ) {
			return array(
				'success'  => true,
				'pattern'  => $pattern,
				'route_id' => $result['result']['id'] ?? null,
			);
		}

		return array(
			'success' => false,
			'message' => $result['errors'][0]['message'] ?? __( 'Failed to configure route', 'cf-r2-offload-cdn' ),
		);
	}
}
