<?php

namespace Paygent\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

/**
 * Tests for payment_status → WooCommerce order status mapping in webhook handlers.
 *
 * Verifies that each Paygent payment_status code routes to the correct
 * WooCommerce status for CV (convenience store), BN (bank net), and MB (carrier).
 */
class WebhookStatusMappingTest extends TestCase {

	/** @var \WC_Paygent_Endpoint */
	private $endpoint;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'register_rest_route' )->justReturn( true );

		require_once dirname( __DIR__, 2 ) . '/includes/gateways/paygent/class-wc-paygent-endpoint.php';

		$this->endpoint = new \WC_Paygent_Endpoint();
	}

	// -------------------------------------------------------------------------
	// Helper: build a mock order that records update_status() calls.
	// -------------------------------------------------------------------------

	private function make_order_mock( string $type = 'shop_order' ): object {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_type' )->andReturn( $type )->byDefault();
		$order->shouldReceive( 'get_id' )->andReturn( 1 )->byDefault();
		$order->shouldReceive( 'get_status' )->andReturn( 'pending' )->byDefault();
		$order->shouldReceive( 'add_order_note' )->andReturn( true )->byDefault();
		return $order;
	}

	// -------------------------------------------------------------------------
	// CV (convenience store) status mapping
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider cv_status_provider
	 */
	public function test_cv_webhook_maps_payment_status( string $payment_status, string $expected_wc_status ): void {
		$order = $this->make_order_mock();
		$order->shouldReceive( 'update_status' )
			->once()
			->with( $expected_wc_status, Mockery::any() );

		$this->endpoint->paygent_cv_webhook( $order, array( 'payment_status' => $payment_status ) );
	}

	public static function cv_status_provider(): array {
		return array(
			'10 → on-hold'    => array( '10', 'on-hold' ),
			'12 → cancelled'  => array( '12', 'cancelled' ),
			'40 → processing' => array( '40', 'processing' ),
			'43 → processing' => array( '43', 'processing' ),
			'61 → cancelled'  => array( '61', 'cancelled' ),
		);
	}

	public function test_cv_webhook_ignores_unknown_status(): void {
		$order = $this->make_order_mock();
		$order->shouldNotReceive( 'update_status' );

		$this->endpoint->paygent_cv_webhook( $order, array( 'payment_status' => '99' ) );
	}

	// -------------------------------------------------------------------------
	// BN (bank net) status mapping
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider bn_status_provider
	 */
	public function test_bn_webhook_maps_payment_status( string $payment_status, string $expected_wc_status ): void {
		$order = $this->make_order_mock();
		$order->shouldReceive( 'update_status' )
			->once()
			->with( $expected_wc_status, Mockery::any() );

		$this->endpoint->paygent_bn_webhook( $order, array( 'payment_status' => $payment_status ) );
	}

	public static function bn_status_provider(): array {
		return array(
			'10 → on-hold'    => array( '10', 'on-hold' ),
			'15 → cancelled'  => array( '15', 'cancelled' ),
			'40 → processing' => array( '40', 'processing' ),
		);
	}

	// -------------------------------------------------------------------------
	// MB (carrier) subscription renewal status mapping
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider mb_renewal_status_provider
	 */
	public function test_mb_webhook_renewal_order_updates_both_order_and_subscription(
		string $payment_status,
		string $expected_order_status,
		string $expected_subscription_status
	): void {
		$order = $this->make_order_mock();

		if ( 'not_set' !== $expected_order_status ) {
			$order->shouldReceive( 'update_status' )
				->once()
				->with( $expected_order_status, Mockery::any() );
		} else {
			$order->shouldNotReceive( 'update_status' );
		}

		$subscription = $this->make_order_mock( 'shop_subscription' );

		if ( 'not_set' !== $expected_subscription_status ) {
			$subscription->shouldReceive( 'update_status' )
				->once()
				->with( $expected_subscription_status, Mockery::any() );
		} else {
			$subscription->shouldNotReceive( 'update_status' );
		}

		Functions\when( 'wcs_order_contains_renewal' )->justReturn( true );
		Functions\when( 'wcs_order_contains_subscription' )->justReturn( false );
		Functions\when( 'wcs_get_subscriptions_for_order' )->justReturn( array( $subscription ) );

		$this->endpoint->paygent_mb_webhook( $order, array( 'payment_status' => $payment_status ) );
	}

	public static function mb_renewal_status_provider(): array {
		return array(
			'10 → on-hold / on-hold'     => array( '10', 'on-hold', 'on-hold' ),
			'20 → processing / active'   => array( '20', 'processing', 'active' ),
			'40 → processing / active'   => array( '40', 'processing', 'active' ),
			'50 → cancelled / cancelled' => array( '50', 'cancelled', 'cancelled' ),
			'21 → processing / not_set'  => array( '21', 'processing', 'not_set' ),
			'41 → not_set / not_set'     => array( '41', 'not_set', 'not_set' ),
		);
	}

	/**
	 * payment_status=60 maps renewal to 'refunded', but paygent_update_status_webhook
	 * only calls add_order_note() for 'refunded' (no status change — WC handles refunds
	 * separately). The subscription still gets update_status('cancelled').
	 */
	public function test_mb_webhook_payment_status_60_adds_note_for_renewal_cancels_subscription(): void {
		$order = $this->make_order_mock();
		$order->shouldNotReceive( 'update_status' );
		$order->shouldReceive( 'add_order_note' )->atLeast()->once();

		$subscription = $this->make_order_mock( 'shop_subscription' );
		$subscription->shouldReceive( 'update_status' )
			->once()
			->with( 'cancelled', Mockery::any() );

		Functions\when( 'wcs_order_contains_renewal' )->justReturn( true );
		Functions\when( 'wcs_order_contains_subscription' )->justReturn( false );
		Functions\when( 'wcs_get_subscriptions_for_order' )->justReturn( array( $subscription ) );

		$this->endpoint->paygent_mb_webhook( $order, array( 'payment_status' => '60' ) );
	}
}
