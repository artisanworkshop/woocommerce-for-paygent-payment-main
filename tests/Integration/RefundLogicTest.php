<?php

namespace Paygent\Tests\Integration;

/**
 * Integration tests for Paygent CC refund logic.
 *
 * Covers:
 *  - Gateway-level guards (null amount → false)
 *  - make_error_message() routing for Paygent-specific response codes
 *  - Supported gateway features list
 *
 * These tests run against a real WooCommerce installation inside wp-env so that
 * WC_Payment_Gateway (parent of WC_Gateway_Paygent_CC) is available.
 */
class RefundLogicTest extends TestCase {

	/** @var \WC_Gateway_Paygent_CC */
	private $gateway;

	/** @var \WC_Order */
	private $order;

	public function setUp(): void {
		parent::setUp();

		update_option( 'wc-paygent-cc', 'yes' );
		update_option( 'woocommerce_paygent_cc_settings', array( 'enabled' => 'yes' ) );

		// Re-init payment gateways so the option change is picked up.
		WC()->payment_gateways()->payment_gateways = null;
		WC()->payment_gateways()->init();

		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( ! isset( $gateways['paygent_cc'] ) ) {
			$this->markTestSkipped( 'paygent_cc gateway not available' );
		}

		$this->gateway = $gateways['paygent_cc'];
		$this->order   = $this->create_test_order( 'paygent_cc', 5000 );
	}

	public function tearDown(): void {
		$this->delete_test_order( $this->order );
		delete_option( 'wc-paygent-cc' );
		delete_option( 'woocommerce_paygent_cc_settings' );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Gateway guard: null amount
	// -------------------------------------------------------------------------

	public function test_process_refund_returns_false_when_amount_is_null(): void {
		$result = $this->gateway->process_refund( $this->order->get_id(), null );
		$this->assertFalse( $result );
	}

	// -------------------------------------------------------------------------
	// make_error_message: P009 — digit count errors
	// -------------------------------------------------------------------------

	public function test_make_error_message_p009_security_code(): void {
		$msg = $this->gateway->make_error_message( array(
			'responseCode'   => 'P009',
			'responseDetail' => 'card_conf_number is invalid',
		) );
		$this->assertStringContainsString( 'Security code', $msg );
	}

	public function test_make_error_message_p009_expiry(): void {
		$msg = $this->gateway->make_error_message( array(
			'responseCode'   => 'P009',
			'responseDetail' => 'card_valid_term is invalid',
		) );
		$this->assertStringContainsString( 'Expiration date', $msg );
	}

	public function test_make_error_message_p009_card_number(): void {
		$msg = $this->gateway->make_error_message( array(
			'responseCode'   => 'P009',
			'responseDetail' => 'card_number is invalid',
		) );
		$this->assertStringContainsString( 'Credit Card Number', $msg );
	}

	public function test_make_error_message_p009_other_field_returns_generic_message(): void {
		$msg = $this->gateway->make_error_message( array(
			'responseCode'   => 'P009',
			'responseDetail' => 'some_other_field',
		) );
		$this->assertStringContainsString( 'illegal number of digits', $msg );
	}

	// -------------------------------------------------------------------------
	// make_error_message: P010 — invalid value errors
	// -------------------------------------------------------------------------

	public function test_make_error_message_p010_security_code(): void {
		$msg = $this->gateway->make_error_message( array(
			'responseCode'   => 'P010',
			'responseDetail' => 'card_conf_number',
		) );
		$this->assertStringContainsString( 'Security code', $msg );
		$this->assertStringContainsString( 'invalid value', $msg );
	}

	public function test_make_error_message_p010_expiry(): void {
		$msg = $this->gateway->make_error_message( array(
			'responseCode'   => 'P010',
			'responseDetail' => 'card_valid_term',
		) );
		$this->assertStringContainsString( 'Expiration date', $msg );
		$this->assertStringContainsString( 'invalid value', $msg );
	}

	public function test_make_error_message_p010_card_number(): void {
		$msg = $this->gateway->make_error_message( array(
			'responseCode'   => 'P010',
			'responseDetail' => 'card_number',
		) );
		$this->assertStringContainsString( 'Credit Card Number', $msg );
		$this->assertStringContainsString( 'invalid value', $msg );
	}

	public function test_make_error_message_p010_other_field(): void {
		$msg = $this->gateway->make_error_message( array(
			'responseCode'   => 'P010',
			'responseDetail' => 'unknown_field',
		) );
		$this->assertStringContainsString( 'invalid value', $msg );
	}

	// -------------------------------------------------------------------------
	// make_error_message: unknown code — falls back to responseDetail (SJIS→UTF-8)
	// -------------------------------------------------------------------------

	public function test_make_error_message_unknown_code_returns_response_detail(): void {
		// UTF-8 input survives the mb_convert_encoding pass (treated as UTF-8 input
		// converted to UTF-8, which is a no-op for ASCII-range text).
		$msg = $this->gateway->make_error_message( array(
			'responseCode'   => 'Z999',
			'responseDetail' => 'unexpected error detail',
		) );
		$this->assertStringContainsString( 'unexpected error detail', $msg );
	}

	// -------------------------------------------------------------------------
	// Gateway feature support
	// -------------------------------------------------------------------------

	public function test_gateway_supports_refunds(): void {
		$this->assertTrue(
			$this->gateway->supports( 'refunds' ),
			'paygent_cc gateway must declare "refunds" support'
		);
	}
}
