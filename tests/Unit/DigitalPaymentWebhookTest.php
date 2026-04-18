<?php

namespace Paygent\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

/**
 * Tests for Paidy, PayPay, and Rakuten Pay webhook status mappings.
 */
class DigitalPaymentWebhookTest extends TestCase {

	/** @var \WC_Paygent_Endpoint */
	private $endpoint;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'register_rest_route' )->justReturn( true );

		require_once dirname( __DIR__, 2 ) . '/includes/gateways/paygent/class-wc-paygent-endpoint.php';

		$this->endpoint = new \WC_Paygent_Endpoint();
	}

	// Use 'failed' — not in base_status — so the backward-transition guard is bypassed.
	// We're testing status mapping here, not the guard (covered in StatusTransitionTest).
	private function make_order( string $status = 'failed' ): object {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_type' )->andReturn( 'shop_order' )->byDefault();
		$order->shouldReceive( 'get_id' )->andReturn( 1 )->byDefault();
		$order->shouldReceive( 'get_status' )->andReturn( $status )->byDefault();
		$order->shouldReceive( 'add_order_note' )->andReturn( true )->byDefault();
		return $order;
	}

	// -------------------------------------------------------------------------
	// Paidy
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider paidy_status_provider
	 */
	public function test_paidy_webhook_maps_payment_status( string $payment_status, string $expected ): void {
		$order = $this->make_order();
		if ( 'not_set' === $expected ) {
			$order->shouldNotReceive( 'update_status' );
		} else {
			$order->shouldReceive( 'update_status' )->once()->with( $expected, Mockery::any() );
		}

		$this->endpoint->paygent_paidy_webhook( $order, array( 'payment_status' => $payment_status ) );
	}

	public static function paidy_status_provider(): array {
		return array(
			'20 → processing' => array( '20', 'processing' ),
			'30 → processing' => array( '30', 'processing' ),
			'31 → cancelled'  => array( '31', 'cancelled' ),
			'32 → cancelled'  => array( '32', 'cancelled' ),
			'33 → cancelled'  => array( '33', 'cancelled' ),
			'40 → completed'  => array( '40', 'completed' ),
			'41 → not_set'    => array( '41', 'not_set' ),
		);
	}

	// -------------------------------------------------------------------------
	// PayPay
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider paypay_status_provider
	 */
	public function test_paypay_webhook_maps_payment_status( string $payment_status, string $expected ): void {
		$order = $this->make_order();
		if ( 'not_set' === $expected ) {
			$order->shouldNotReceive( 'update_status' );
		} else {
			$order->shouldReceive( 'update_status' )->once()->with( $expected, Mockery::any() );
		}

		$this->endpoint->paygent_paypay_webhook( $order, array( 'payment_status' => $payment_status ) );
	}

	public static function paypay_status_provider(): array {
		return array(
			'10 → pending'    => array( '10', 'pending' ),
			'15 → cancelled'  => array( '15', 'cancelled' ),
			'40 → processing' => array( '40', 'processing' ),
			'41 → not_set'    => array( '41', 'not_set' ),
			// 60 (all_refunded) → update_status('refunded') via next_status path
			'60 → refunded'   => array( '60', 'refunded' ),
		);
	}

	// -------------------------------------------------------------------------
	// Rakuten Pay
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider rakuten_status_provider
	 */
	public function test_rakutenpay_webhook_maps_payment_status( string $payment_status, string $expected ): void {
		$order = $this->make_order();
		if ( 'not_set' === $expected ) {
			$order->shouldNotReceive( 'update_status' );
		} else {
			$order->shouldReceive( 'update_status' )->once()->with( $expected, Mockery::any() );
		}

		$this->endpoint->paygent_rakutenpay_webhook( $order, array( 'payment_status' => $payment_status ) );
	}

	public static function rakuten_status_provider(): array {
		return array(
			'15 → cancelled'  => array( '15', 'cancelled' ),
			'20 → processing' => array( '20', 'processing' ),
			'40 → processing' => array( '40', 'processing' ),
			'32 → cancelled'  => array( '32', 'cancelled' ),
			'60 → refunded'   => array( '60', 'refunded' ),
			'33 → cancelled'  => array( '33', 'cancelled' ),
			'41 → not_set'    => array( '41', 'not_set' ),
		);
	}
}
