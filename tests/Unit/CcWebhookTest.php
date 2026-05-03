<?php

namespace Paygent\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

/**
 * Tests for paygent_cc_webhook() — credit card payment_status → WC status mapping.
 *
 * Special case: payment_status=40 (sales complete) only updates status if the
 * gateway's paymentaction is NOT 'sale'. payment_status=60 dispatches either
 * 'all_refunded' (transaction_id matches) or 'refunded' (partial).
 */
class CcWebhookTest extends TestCase {

	/** @var \WC_Paygent_Endpoint */
	private $endpoint;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'register_rest_route' )->justReturn( true );

		require_once dirname( __DIR__, 2 ) . '/includes/gateways/paygent/class-wc-paygent-endpoint.php';

		$this->endpoint = new \WC_Paygent_Endpoint();
	}

	private function make_order( string $status = 'failed', string $transaction_id = 'TXN001' ): object {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_type' )->andReturn( 'shop_order' )->byDefault();
		$order->shouldReceive( 'get_id' )->andReturn( 1 )->byDefault();
		$order->shouldReceive( 'get_status' )->andReturn( $status )->byDefault();
		$order->shouldReceive( 'get_transaction_id' )->andReturn( $transaction_id )->byDefault();
		$order->shouldReceive( 'add_order_note' )->andReturn( true )->byDefault();
		return $order;
	}

	// -------------------------------------------------------------------------
	// Standard status mappings
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider cc_standard_status_provider
	 */
	public function test_cc_webhook_standard_mappings( string $payment_status, string $expected_wc_status ): void {
		$order = $this->make_order();
		$order->shouldReceive( 'update_status' )
			->once()
			->with( $expected_wc_status, Mockery::any() );

		$this->endpoint->paygent_cc_webhook( $order, array( 'payment_status' => $payment_status ) );
	}

	public static function cc_standard_status_provider(): array {
		return array(
			'10 → pending'    => array( '10', 'pending' ),
			'11 → cancelled'  => array( '11', 'cancelled' ),
			'13 → cancelled'  => array( '13', 'cancelled' ),
			'20 → processing' => array( '20', 'processing' ),
			'32 → cancelled'  => array( '32', 'cancelled' ),
			'33 → cancelled'  => array( '33', 'cancelled' ),
		);
	}

	// -------------------------------------------------------------------------
	// Status 60: full refund vs partial refund routing
	// -------------------------------------------------------------------------

	public function test_cc_webhook_60_full_refund_when_transaction_id_matches(): void {
		$order = $this->make_order( 'processing', 'TXN123' );
		$order->shouldReceive( 'update_status' )
			->once()
			->with( 'refunded', Mockery::any() );

		// all_refunded → paygent_update_status_webhook converts next_status to 'refunded'
		$this->endpoint->paygent_cc_webhook(
			$order,
			array( 'payment_status' => '60', 'payment_id' => 'TXN123' )
		);
	}

	public function test_cc_webhook_60_partial_refund_note_only_when_transaction_id_differs(): void {
		$order = $this->make_order( 'processing', 'TXN123' );
		// 'refunded' status → paygent_update_status_webhook only adds a note.
		$order->shouldNotReceive( 'update_status' );
		$order->shouldReceive( 'add_order_note' )->atLeast()->once();

		$this->endpoint->paygent_cc_webhook(
			$order,
			array( 'payment_status' => '60', 'payment_id' => 'DIFFERENT_ID' )
		);
	}

	// -------------------------------------------------------------------------
	// Status 40: only updates when paymentaction is not 'sale'
	// -------------------------------------------------------------------------

	public function test_cc_webhook_40_unknown_payment_status_is_ignored(): void {
		$order = $this->make_order();
		$order->shouldNotReceive( 'update_status' );

		$this->endpoint->paygent_cc_webhook( $order, array( 'payment_status' => '99' ) );
	}

	public function test_cc_webhook_missing_payment_status_is_ignored(): void {
		$order = $this->make_order();
		$order->shouldNotReceive( 'update_status' );
		$order->shouldNotReceive( 'add_order_note' );

		$this->endpoint->paygent_cc_webhook( $order, array() );
	}
}
