<?php

namespace Paygent\Tests\Integration;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Base test case for WordPress integration tests.
 *
 * Uses plain PHPUnit\Framework\TestCase (not WP_UnitTestCase) to avoid
 * compatibility issues with wp-phpunit and PHPUnit 10.x.
 *
 * DB cleanup: tests should clean up after themselves, or use wc_create_order()
 * which generates IDs that don't conflict across tests.
 */
abstract class TestCase extends PHPUnitTestCase {

	/**
	 * Create a WooCommerce order for testing.
	 *
	 * @param string $payment_method Gateway ID.
	 * @param int    $total          Order total in JPY.
	 * @return \WC_Order
	 */
	protected function create_test_order( string $payment_method = 'paygent_cc', int $total = 1000 ): \WC_Order {
		$order = wc_create_order(
			array(
				'status' => 'pending',
			)
		);

		// wc_create_order does not accept payment_method in args; set explicitly.
		$order->set_payment_method( $payment_method );
		$order->set_payment_method_title( 'Paygent' );
		$order->set_total( (string) $total );
		$order->save();

		return $order;
	}

	/**
	 * Delete a test order after use.
	 */
	protected function delete_test_order( \WC_Order $order ): void {
		$order->delete( true );
	}
}
