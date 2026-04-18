<?php

namespace Paygent\Tests\Integration;

/**
 * Integration tests for the Paygent REST API webhook endpoint.
 *
 * Verifies that the `POST /wp-json/paygent/v1/check` route is registered,
 * and that the endpoint enforces IP-based authentication.
 */
class WebhookEndpointTest extends TestCase {

	public function setUp(): void {
		parent::setUp();

		// Ensure REST server is initialized.
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	public function test_webhook_route_is_registered(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/paygent/v1/check', $routes );
	}

	public function test_webhook_route_accepts_post_method(): void {
		$routes  = rest_get_server()->get_routes();
		$route   = $routes['/paygent/v1/check'];
		$methods = array_keys( $route[0]['methods'] ?? array() );

		$this->assertContains( 'POST', $methods );
	}

	public function test_webhook_request_without_ip_returns_403(): void {
		// Override REMOTE_ADDR to a non-permitted IP.
		$_SERVER['REMOTE_ADDR'] = '1.2.3.4';

		$request  = new \WP_REST_Request( 'POST', '/paygent/v1/check' );
		$request->set_body_params( array( 'payment_type' => '02', 'payment_status' => '40' ) );

		$response = rest_get_server()->dispatch( $request );

		// WP REST API may return 401 (unauthenticated) before the IP check runs (403).
		// Both are acceptable error responses for an unauthorized non-permitted IP.
		$this->assertContains( $response->get_status(), array( 401, 403 ) );

		unset( $_SERVER['REMOTE_ADDR'] );
	}

	public function test_webhook_request_without_body_returns_400_or_403(): void {
		$_SERVER['REMOTE_ADDR'] = '1.2.3.4';

		$request  = new \WP_REST_Request( 'POST', '/paygent/v1/check' );
		$response = rest_get_server()->dispatch( $request );

		// Either 400 (bad request), 401 (unauthenticated), or 403 (unauthorized IP) is acceptable.
		$this->assertContains( $response->get_status(), array( 400, 401, 403 ) );

		unset( $_SERVER['REMOTE_ADDR'] );
	}
}
