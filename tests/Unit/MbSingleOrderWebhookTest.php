<?php

namespace Paygent\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

/**
 * Tests for paygent_mb_webhook() — carrier payment on plain shop_order (no subscription).
 *
 * The webhook has three branches:
 * 1. Renewal/subscription order → handled in WebhookStatusMappingTest
 * 2. shop_order → tested here
 * 3. shop_subscription (standalone) → tested here
 */
class MbSingleOrderWebhookTest extends TestCase {

	/** @var \WC_Paygent_Endpoint */
	private $endpoint;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'register_rest_route' )->justReturn( true );
		// No subscription functions active → falls through to type-based branch.
		Functions\when( 'wcs_order_contains_renewal' )->justReturn( false );
		Functions\when( 'wcs_order_contains_subscription' )->justReturn( false );

		require_once dirname( __DIR__, 2 ) . '/includes/gateways/paygent/class-wc-paygent-endpoint.php';

		$this->endpoint = new \WC_Paygent_Endpoint();
	}

	private function make_order( string $type = 'shop_order', string $status = 'pending' ): object {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_type' )->andReturn( $type )->byDefault();
		$order->shouldReceive( 'get_id' )->andReturn( 1 )->byDefault();
		$order->shouldReceive( 'get_status' )->andReturn( $status )->byDefault();
		$order->shouldReceive( 'add_order_note' )->andReturn( true )->byDefault();
		return $order;
	}

	// -------------------------------------------------------------------------
	// shop_order branch
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider mb_shop_order_provider
	 */
	public function test_mb_webhook_shop_order_maps_status( string $payment_status, string $expected ): void {
		$order = $this->make_order( 'shop_order' );

		if ( 'not_set' === $expected ) {
			$order->shouldNotReceive( 'update_status' );
		} else {
			$order->shouldReceive( 'update_status' )->once()->with( $expected, Mockery::any() );
		}

		$this->endpoint->paygent_mb_webhook( $order, array( 'payment_status' => $payment_status ) );
	}

	public static function mb_shop_order_provider(): array {
		return array(
			'10 → on-hold'    => array( '10', 'on-hold' ),
			'15 → cancelled'  => array( '15', 'cancelled' ),
			'20 → processing' => array( '20', 'processing' ),
			'21 → processing' => array( '21', 'processing' ),
			'32 → cancelled'  => array( '32', 'cancelled' ),
			'33 → cancelled'  => array( '33', 'cancelled' ),
			'36 → on-hold'    => array( '36', 'on-hold' ),
			'40 → processing' => array( '40', 'processing' ),
			'41 → not_set'    => array( '41', 'not_set' ),
			'43 → processing' => array( '43', 'processing' ),
			'44 → processing' => array( '44', 'processing' ),
			'62 → cancelled'  => array( '62', 'cancelled' ),
		);
	}

	public function test_mb_webhook_shop_order_60_adds_note_only(): void {
		$order = $this->make_order( 'shop_order', 'processing' );
		$order->shouldNotReceive( 'update_status' );
		$order->shouldReceive( 'add_order_note' )->atLeast()->once();

		$this->endpoint->paygent_mb_webhook( $order, array( 'payment_status' => '60' ) );
	}

	// -------------------------------------------------------------------------
	// shop_subscription branch (standalone subscription, not a renewal order)
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider mb_subscription_provider
	 */
	public function test_mb_webhook_shop_subscription_maps_status( string $payment_status, string $expected ): void {
		$order = $this->make_order( 'shop_subscription' );

		if ( 'not_set' === $expected ) {
			$order->shouldNotReceive( 'update_status' );
		} else {
			$order->shouldReceive( 'update_status' )->once()->with( $expected, Mockery::any() );
		}

		$this->endpoint->paygent_mb_webhook( $order, array( 'payment_status' => $payment_status ) );
	}

	public static function mb_subscription_provider(): array {
		return array(
			'10 → on-hold'  => array( '10', 'on-hold' ),
			'15 → cancelled' => array( '15', 'cancelled' ),
			'20 → active'    => array( '20', 'active' ),
			'21 → not_set'   => array( '21', 'not_set' ),
			'33 → not_set'   => array( '33', 'not_set' ),
			'40 → active'    => array( '40', 'active' ),
			'50 → cancelled' => array( '50', 'cancelled' ),
			'60 → cancelled' => array( '60', 'cancelled' ),
		);
	}

	public function test_mb_webhook_unknown_order_type_does_nothing(): void {
		// Neither subscription branch nor shop_order/shop_subscription branch matches
		// for a custom post type — paygent_update_status_webhook is never called.
		$order = $this->make_order( 'custom_type' );
		$order->shouldNotReceive( 'update_status' );
		$order->shouldNotReceive( 'add_order_note' );

		$this->endpoint->paygent_mb_webhook( $order, array( 'payment_status' => '40' ) );
	}
}
