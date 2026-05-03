<?php

namespace Paygent\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

/**
 * Tests for paygent_update_status_webhook() guard logic.
 *
 * The method enforces forward-only status transitions for known statuses and
 * skips updates when:
 * - status is 'not_set'
 * - current order status is 'pre-ordered'
 * - the requested transition would go backwards
 * - subscription is 'active' and incoming status is 'processing' (no-op)
 */
class StatusTransitionTest extends TestCase {

	/** @var \WC_Paygent_Endpoint */
	private $endpoint;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'register_rest_route' )->justReturn( true );

		require_once dirname( __DIR__, 2 ) . '/includes/gateways/paygent/class-wc-paygent-endpoint.php';

		$this->endpoint = new \WC_Paygent_Endpoint();
	}

	private function make_order( string $type, string $current_status ): object {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_type' )->andReturn( $type )->byDefault();
		$order->shouldReceive( 'get_id' )->andReturn( 1 )->byDefault();
		$order->shouldReceive( 'get_status' )->andReturn( $current_status )->byDefault();
		$order->shouldReceive( 'add_order_note' )->andReturn( true )->byDefault();
		return $order;
	}

	public function test_not_set_status_skips_update(): void {
		$order = $this->make_order( 'shop_order', 'pending' );
		$order->shouldNotReceive( 'update_status' );
		$order->shouldNotReceive( 'add_order_note' );

		$this->endpoint->paygent_update_status_webhook( $order, 'not_set' );
	}

	public function test_pre_ordered_status_only_adds_note(): void {
		$order = $this->make_order( 'shop_order', 'pre-ordered' );
		$order->shouldNotReceive( 'update_status' );
		$order->shouldReceive( 'add_order_note' )->once();

		$this->endpoint->paygent_update_status_webhook( $order, 'processing' );
	}

	public function test_same_status_does_nothing(): void {
		$order = $this->make_order( 'shop_order', 'processing' );
		$order->shouldNotReceive( 'update_status' );
		$order->shouldNotReceive( 'add_order_note' );

		$this->endpoint->paygent_update_status_webhook( $order, 'processing' );
	}

	public function test_forward_transition_calls_update_status(): void {
		// pending (index 0) → processing (index 2): forward → allowed.
		$order = $this->make_order( 'shop_order', 'pending' );
		$order->shouldReceive( 'update_status' )->once()->with( 'processing', Mockery::any() );

		$this->endpoint->paygent_update_status_webhook( $order, 'processing' );
	}

	public function test_backward_transition_adds_abnormal_note(): void {
		// processing (index 2) → on-hold (index 1): backwards.
		// Adds two notes: one "abnormal transition" warning + one status-change message.
		$order = $this->make_order( 'shop_order', 'processing' );
		$order->shouldNotReceive( 'update_status' );
		$order->shouldReceive( 'add_order_note' )->twice();

		$this->endpoint->paygent_update_status_webhook( $order, 'on-hold' );
	}

	public function test_subscription_active_ignores_processing_update(): void {
		// Active subscription receiving 'processing' → no-op (already active).
		$order = $this->make_order( 'shop_subscription', 'active' );
		$order->shouldNotReceive( 'update_status' );
		$order->shouldReceive( 'add_order_note' )->once();

		$this->endpoint->paygent_update_status_webhook( $order, 'processing' );
	}

	public function test_subscription_forward_transition_allowed(): void {
		// pending (0) → active (3): forward on subscription → allowed.
		$order = $this->make_order( 'shop_subscription', 'pending' );
		$order->shouldReceive( 'update_status' )->once()->with( 'active', Mockery::any() );

		$this->endpoint->paygent_update_status_webhook( $order, 'active' );
	}

	public function test_payment_status_array_contains_all_known_codes(): void {
		$status_array = $this->endpoint->paygent_payment_status_array();

		$expected_codes = array( '10', '11', '12', '13', '15', '20', '30', '31', '32', '33', '36', '40', '41', '43', '44', '60', '61', '62' );
		foreach ( $expected_codes as $code ) {
			$this->assertArrayHasKey( $code, $status_array, "Missing payment status code: {$code}" );
		}
	}

	public function test_payment_status_array_values_are_non_empty_strings(): void {
		$status_array = $this->endpoint->paygent_payment_status_array();

		foreach ( $status_array as $code => $label ) {
			$this->assertIsString( $label, "Status {$code} label should be a string" );
			$this->assertNotEmpty( $label, "Status {$code} label should not be empty" );
		}
	}
}
