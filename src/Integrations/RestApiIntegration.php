<?php
/**
 * REST API Integration class.
 *
 * Provides read-only REST API endpoints for querying attachment status.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Integrations;

defined( 'ABSPATH' ) || exit;

use ThachPN165\CFR2OffLoad\Interfaces\HookableInterface;

/**
 * RestApiIntegration class - handles REST API endpoints.
 */
class RestApiIntegration implements HookableInterface {

	/**
	 * REST API namespace.
	 */
	private const NAMESPACE = 'cfr2/v1';

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		// Get attachment offload status and URLs.
		register_rest_route(
			self::NAMESPACE,
			'/attachment/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( RestApiStatusHandler::class, 'get_attachment' ),
				'permission_callback' => array( RestApiHelper::class, 'check_attachment_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'validate_callback' => array( RestApiHelper::class, 'validate_attachment_id' ),
					),
				),
			)
		);

		// Get plugin statistics (requires authentication).
		register_rest_route(
			self::NAMESPACE,
			'/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( RestApiStatusHandler::class, 'get_stats' ),
				'permission_callback' => array( RestApiHelper::class, 'check_read_permission' ),
				'args'                => array(
					'period' => array(
						'default' => 'month',
						'enum'    => array( 'week', 'month' ),
					),
				),
			)
		);
	}
}
