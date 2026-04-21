<?php

namespace Paygent\Tests\Integration;

/**
 * Integration tests for Paygent gateway settings and initialization.
 *
 * Verifies that:
 *  - Each gateway appears (or does not appear) in the WooCommerce payment
 *    gateways list based on the corresponding WordPress option.
 *  - The CC gateway exposes the expected admin settings fields.
 *  - process_payment() returns a failure result (not a fatal) when merchant
 *    credentials are not configured.
 *
 * Note: Paygent credentials (merchant_id, connect_id, etc.) are global plugin
 * options (wc-paygent-mid, wc-paygent-cid, …), not per-gateway form fields.
 * Per-gateway form fields are behavioural settings (title, paymentaction, etc.).
 */
class GatewaySettingsTest extends TestCase {

	public function setUp(): void {
		parent::setUp();

		// Start with a clean slate.
		delete_option( 'wc-paygent-cc' );
		delete_option( 'wc-paygent-cs' );
		delete_option( 'wc-paygent-atm' );
		delete_option( 'wc-paygent-mb' );
		delete_option( 'woocommerce_paygent_cc_settings' );

		WC()->payment_gateways()->payment_gateways = null;
		WC()->payment_gateways()->init();
	}

	public function tearDown(): void {
		delete_option( 'wc-paygent-cc' );
		delete_option( 'wc-paygent-cs' );
		delete_option( 'wc-paygent-atm' );
		delete_option( 'wc-paygent-mb' );
		delete_option( 'woocommerce_paygent_cc_settings' );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Gateway absent when its option is not set
	// -------------------------------------------------------------------------

	public function test_cc_gateway_absent_when_option_not_set(): void {
		WC()->payment_gateways()->payment_gateways = null;
		WC()->payment_gateways()->init();

		$gateways = WC()->payment_gateways()->payment_gateways();
		$this->assertArrayNotHasKey(
			'paygent_cc',
			$gateways,
			'paygent_cc should not be registered when wc-paygent-cc option is absent'
		);
	}

	// -------------------------------------------------------------------------
	// Gateway present when its option is set to "yes"
	// -------------------------------------------------------------------------

	public function test_cc_gateway_present_when_option_is_yes(): void {
		update_option( 'wc-paygent-cc', 'yes' );
		WC()->payment_gateways()->payment_gateways = null;
		WC()->payment_gateways()->init();

		$gateways = WC()->payment_gateways()->payment_gateways();
		$this->assertArrayHasKey( 'paygent_cc', $gateways );
	}

	public function test_cs_gateway_present_when_option_is_yes(): void {
		update_option( 'wc-paygent-cs', 'yes' );
		WC()->payment_gateways()->payment_gateways = null;
		WC()->payment_gateways()->init();

		$gateways = WC()->payment_gateways()->payment_gateways();
		$this->assertArrayHasKey( 'paygent_cs', $gateways );
	}

	public function test_atm_gateway_present_when_option_is_yes(): void {
		update_option( 'wc-paygent-atm', 'yes' );

		// The ATM class file is conditionally required at plugin-init time based on
		// the option value.  Since the option was not set at that point, we load it
		// manually — mirroring the pattern used in SubscriptionHookTest.
		if ( ! class_exists( 'WC_Gateway_Paygent_ATM' ) ) {
			require_once WC_PAYGENT_PLUGIN_PATH . 'includes/gateways/paygent/class-wc-gateway-paygent-atm.php';
		}

		WC()->payment_gateways()->payment_gateways = null;
		WC()->payment_gateways()->init();

		$gateways = WC()->payment_gateways()->payment_gateways();
		$this->assertArrayHasKey( 'paygent_atm', $gateways );
	}

	// -------------------------------------------------------------------------
	// CC gateway form fields (per-gateway behavioural settings)
	// -------------------------------------------------------------------------

	public function test_cc_gateway_defines_required_settings_fields(): void {
		update_option( 'wc-paygent-cc', 'yes' );
		WC()->payment_gateways()->payment_gateways = null;
		WC()->payment_gateways()->init();

		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( ! isset( $gateways['paygent_cc'] ) ) {
			$this->markTestSkipped( 'paygent_cc gateway not available' );
		}

		$fields = $gateways['paygent_cc']->get_form_fields();

		// Behavioural settings that must be present (credentials are stored as
		// separate global options, not in the gateway form fields).
		$required = array( 'enabled', 'title', 'paymentaction', 'tds2_check' );
		foreach ( $required as $key ) {
			$this->assertArrayHasKey(
				$key,
				$fields,
				"Expected form field '{$key}' to be defined in paygent_cc"
			);
		}
	}

	public function test_cc_gateway_defines_3ds2_field(): void {
		update_option( 'wc-paygent-cc', 'yes' );
		WC()->payment_gateways()->payment_gateways = null;
		WC()->payment_gateways()->init();

		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( ! isset( $gateways['paygent_cc'] ) ) {
			$this->markTestSkipped( 'paygent_cc gateway not available' );
		}

		$fields = $gateways['paygent_cc']->get_form_fields();
		$this->assertArrayHasKey( 'tds2_check', $fields );
		$this->assertArrayHasKey( 'tds2_hashkey', $fields );
	}

	// -------------------------------------------------------------------------
	// process_payment() without credentials returns failure, not a fatal
	// -------------------------------------------------------------------------

	public function test_process_payment_returns_failure_without_credentials(): void {
		update_option( 'wc-paygent-cc', 'yes' );
		update_option( 'woocommerce_paygent_cc_settings', array(
			'enabled'  => 'yes',
		) );
		// Ensure global credentials are empty.
		update_option( 'wc-paygent-test-mid', '' );
		update_option( 'wc-paygent-test-cid', '' );
		update_option( 'wc-paygent-testmode', '1' );

		WC()->payment_gateways()->payment_gateways = null;
		WC()->payment_gateways()->init();

		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( ! isset( $gateways['paygent_cc'] ) ) {
			$this->markTestSkipped( 'paygent_cc gateway not available' );
		}

		$order  = $this->create_test_order( 'paygent_cc', 1000 );
		$result = $gateways['paygent_cc']->process_payment( $order->get_id() );

		// Should return an array — never throw a fatal or return null.
		$this->assertIsArray( $result );
		// Result must NOT be 'success' when credentials are absent.
		$this->assertNotSame( 'success', $result['result'] ?? null );

		$this->delete_test_order( $order );
	}
}
