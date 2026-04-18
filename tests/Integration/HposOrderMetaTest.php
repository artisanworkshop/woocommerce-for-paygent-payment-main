<?php

namespace Paygent\Tests\Integration;

/**
 * Integration tests for HPOS-compatible order meta read/write.
 *
 * Verifies that all Paygent meta keys are read and written via the HPOS-safe
 * WC_Order API (`get_meta` / `update_meta_data`) rather than `get_post_meta` /
 * `update_post_meta` on order data.
 *
 * These tests run against a real WooCommerce database inside wp-env.
 */
class HposOrderMetaTest extends TestCase {

	/** @var \WC_Order */
	private $order;

	public function setUp(): void {
		parent::setUp();
		$this->order = $this->create_test_order( 'paygent_cc', 5000 );
	}

	public function tearDown(): void {
		$this->delete_test_order( $this->order );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// HPOS read/write round-trips for Paygent meta keys
	// -------------------------------------------------------------------------

	public function test_trading_id_stored_and_retrieved_via_hpos(): void {
		$this->order->update_meta_data( 'trading_id', 'TXN-12345' );
		$this->order->save();

		$fresh = wc_get_order( $this->order->get_id() );
		$this->assertSame( 'TXN-12345', $fresh->get_meta( 'trading_id' ) );
	}

	public function test_fingerprint_stored_and_retrieved_via_hpos(): void {
		$this->order->update_meta_data( '_paygent_fingerprint', 'FP-ABC' );
		$this->order->save();

		$fresh = wc_get_order( $this->order->get_id() );
		$this->assertSame( 'FP-ABC', $fresh->get_meta( '_paygent_fingerprint' ) );
	}

	public function test_3ds_auth_id_stored_and_retrieved_via_hpos(): void {
		$this->order->update_meta_data( '_3ds_auth_id', 'AUTH-XYZ' );
		$this->order->save();

		$fresh = wc_get_order( $this->order->get_id() );
		$this->assertSame( 'AUTH-XYZ', $fresh->get_meta( '_3ds_auth_id' ) );
	}

	public function test_card_save_preference_stored_and_retrieved_via_hpos(): void {
		$this->order->update_meta_data( '_paygent_save_card_preference', '1' );
		$this->order->save();

		$fresh = wc_get_order( $this->order->get_id() );
		$this->assertSame( '1', $fresh->get_meta( '_paygent_save_card_preference' ) );
	}

	public function test_running_id_stored_and_retrieved_via_hpos(): void {
		$this->order->update_meta_data( '_paygent_running_id', 'RUN-999' );
		$this->order->save();

		$fresh = wc_get_order( $this->order->get_id() );
		$this->assertSame( 'RUN-999', $fresh->get_meta( '_paygent_running_id' ) );
	}

	// -------------------------------------------------------------------------
	// HPOS compliance: order created with correct payment method
	// -------------------------------------------------------------------------

	public function test_order_payment_method_readable_via_hpos(): void {
		$this->assertSame( 'paygent_cc', $this->order->get_payment_method() );
	}

	public function test_order_total_readable_via_hpos(): void {
		$this->assertSame( '5000.00', $this->order->get_total() );
	}

	// -------------------------------------------------------------------------
	// HPOS compliance: wc_get_order returns HPOS-backed object
	// -------------------------------------------------------------------------

	public function test_wc_get_order_returns_wc_order_instance(): void {
		$fetched = wc_get_order( $this->order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fetched );
	}

	public function test_meta_overwrite_works_correctly(): void {
		$this->order->update_meta_data( 'trading_id', 'FIRST' );
		$this->order->save();

		$this->order->update_meta_data( 'trading_id', 'SECOND' );
		$this->order->save();

		$fresh = wc_get_order( $this->order->get_id() );
		$this->assertSame( 'SECOND', $fresh->get_meta( 'trading_id' ) );
	}
}
