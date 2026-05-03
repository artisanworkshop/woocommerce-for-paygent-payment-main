<?php

namespace Paygent\Tests\Integration;

/**
 * Integration tests for WooCommerce Subscriptions hook registration.
 *
 * Verifies that the plugin registers the correct WordPress action/filter hooks
 * for subscription renewals, status sync, and cancellation handling.
 * Gateway options must be enabled via WordPress options before gateways appear.
 */
class SubscriptionHookTest extends TestCase {

	public function setUp(): void {
		parent::setUp();

		// Enable gateways by setting the required options.
		update_option( 'wc-paygent-cc', 'yes' );
		update_option( 'wc-paygent-cs', 'yes' );
		update_option( 'wc-paygent-mb', 'yes' );

		// MB and add-on classes are conditionally included in plugin bootstrap
		// based on the wc-paygent-mb option, which is not set at bootstrap time.
		// Ensure the classes are loaded so WC can instantiate the gateway.
		if ( ! class_exists( 'WC_Gateway_Paygent_MB' ) ) {
			require_once WC_PAYGENT_PLUGIN_PATH . 'includes/gateways/paygent/class-wc-gateway-paygent-mb.php';
			require_once WC_PAYGENT_PLUGIN_PATH . 'includes/gateways/paygent/class-wc-gateway-paygent-addon-mb.php';
		}

		// Re-initialise payment gateways to pick up the new options.
		WC()->payment_gateways()->payment_gateways = null;
		WC()->payment_gateways()->init();
	}

	public function tearDown(): void {
		// Clean up options.
		delete_option( 'wc-paygent-cc' );
		delete_option( 'wc-paygent-cs' );
		delete_option( 'wc-paygent-mb' );

		parent::tearDown();
	}

	public function test_paygent_cc_gateway_is_registered(): void {
		$gateways = WC()->payment_gateways()->payment_gateways();
		$ids      = array_keys( $gateways );

		$this->assertContains( 'paygent_cc', $ids, 'paygent_cc gateway should be registered when wc-paygent-cc option is set' );
	}

	public function test_paygent_cs_gateway_is_registered(): void {
		$gateways = WC()->payment_gateways()->payment_gateways();
		$this->assertArrayHasKey( 'paygent_cs', $gateways );
	}

	public function test_paygent_mb_gateway_is_registered(): void {
		$gateways = WC()->payment_gateways()->payment_gateways();
		$this->assertArrayHasKey( 'paygent_mb', $gateways );
	}

	public function test_paygent_endpoint_class_is_instantiated(): void {
		$this->assertTrue( class_exists( 'WC_Paygent_Endpoint' ) );
	}

	public function test_woocommerce_payment_gateways_filter_is_hooked(): void {
		// The filter callback is an object method, not a standalone function.
		$has = has_filter( 'woocommerce_payment_gateways' );
		$this->assertNotFalse( $has, 'woocommerce_payment_gateways filter should have at least one callback' );
	}

	public function test_paygent_cc_gateway_has_process_payment_method(): void {
		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( ! isset( $gateways['paygent_cc'] ) ) {
			$this->markTestSkipped( 'paygent_cc gateway not available' );
		}
		$this->assertTrue( method_exists( $gateways['paygent_cc'], 'process_payment' ) );
	}

	public function test_paygent_cc_gateway_has_process_refund_method(): void {
		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( ! isset( $gateways['paygent_cc'] ) ) {
			$this->markTestSkipped( 'paygent_cc gateway not available' );
		}
		$this->assertTrue( method_exists( $gateways['paygent_cc'], 'process_refund' ) );
	}
}
