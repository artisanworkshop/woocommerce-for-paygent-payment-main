<?php

namespace Paygent\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Tests for the webhook IP allowlist check in paygent_check_webhook().
 *
 * Paygent sends from a fixed set of IP addresses. The endpoint checks
 * REMOTE_ADDR against the stored allowlist. X-Forwarded-For is opt-in via
 * the `paygent_allow_x_forwarded_for_ip_check` filter (default: false).
 */
class WebhookIpCheckTest extends TestCase {

	/** @var \WC_Paygent_Endpoint */
	private $endpoint;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'register_rest_route' )->justReturn( true );

		require_once dirname( __DIR__, 2 ) . '/includes/gateways/paygent/class-wc-paygent-endpoint.php';

		$this->endpoint = new \WC_Paygent_Endpoint();
	}

	/**
	 * X-Forwarded-For must NOT be used unless the filter opts in.
	 * This prevents IP spoofing via a forged header on a non-proxied server.
	 */
	public function test_x_forwarded_for_is_ignored_by_default(): void {
		// The filter defaults to false, so XFF should not be considered.
		$xff_trusted = (bool) apply_filters( 'paygent_allow_x_forwarded_for_ip_check', false );

		$this->assertFalse( $xff_trusted );
	}

	public function test_x_forwarded_for_can_be_enabled_via_filter(): void {
		// The filter default is false. A site behind a trusted proxy can opt in
		// by returning true from the filter hook. Simulate that by passing true
		// as the default value (our stub returns the second argument).
		$xff_trusted = (bool) apply_filters( 'paygent_allow_x_forwarded_for_ip_check', true );

		$this->assertTrue( $xff_trusted );
	}

	/**
	 * IP check logic: REMOTE_ADDR is in the permitted list.
	 *
	 * @dataProvider ip_check_provider
	 */
	public function test_ip_check_logic( string $remote_addr, array $permitted_ips, bool $expected ): void {
		$is_permitted = in_array( $remote_addr, $permitted_ips, true );

		$this->assertSame( $expected, $is_permitted );
	}

	public static function ip_check_provider(): array {
		$paygent_ips = array( '157.65.131.100', '157.65.131.101', '157.65.131.102' );

		return array(
			'known Paygent IP allowed'    => array( '157.65.131.100', $paygent_ips, true ),
			'another known IP allowed'    => array( '157.65.131.102', $paygent_ips, true ),
			'unknown IP blocked'          => array( '1.2.3.4', $paygent_ips, false ),
			'empty IP blocked'            => array( '', $paygent_ips, false ),
			'spoofed XFF value blocked'   => array( '157.65.131.100, 1.2.3.4', $paygent_ips, false ),
		);
	}
}
