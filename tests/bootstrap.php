<?php
/**
 * PHPUnit bootstrap for unit tests.
 *
 * Uses Brain\Monkey to mock WordPress and WooCommerce functions
 * without requiring a full WordPress installation.
 */

// Define constants FIRST — jp4wc-framework exits if ABSPATH is missing.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}
if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
}
if ( ! defined( 'JP4WC_PLUGIN_FILE' ) ) {
	define( 'JP4WC_PLUGIN_FILE', dirname( __DIR__ ) . '/woocommerce-for-paygent-payment-main.php' );
}

// Minimal WordPress class stubs needed before loading plugin classes.
if ( ! class_exists( 'WP_Error' ) ) {
	// phpcs:ignore Generic.Classes.OpeningBraceSameLine
	class WP_Error {
		public function __construct( string $code = '', string $message = '', mixed $data = '' ) {}
	}
}

if ( ! class_exists( 'WC_Order' ) ) {
	// phpcs:ignore Generic.Classes.OpeningBraceSameLine
	class WC_Order {
		public function get_type(): string { return 'shop_order'; }
		public function get_status(): string { return 'pending'; }
		public function get_id(): int { return 0; }
		public function update_status( string $status, string $note = '' ): bool { return true; }
		public function add_order_note( string $note ): int { return 0; }
		public function get_meta( string $key ): mixed { return ''; }
		public function update_meta_data( string $key, mixed $value ): void {}
		public function save(): int { return 0; }
	}
}

// WordPress / WooCommerce function stubs used during class loading.
// These are defined as plain functions (not Brain\Monkey) because they are
// called during object construction before tests can set up per-test stubs.
if ( ! function_exists( 'wp_kses' ) ) {
	function wp_kses( $data, $allowed_html, $allowed_protocols = array() ) { // phpcs:ignore
		return $data;
	}
}
if ( ! function_exists( 'wp_enqueue_block_style' ) ) {
	function wp_enqueue_block_style( $block_name, $args = array() ) {} // phpcs:ignore
}
if ( ! function_exists( 'wc_get_order_statuses' ) ) {
	function wc_get_order_statuses() { // phpcs:ignore
		return array(
			'wc-pending'        => 'Pending payment',
			'wc-on-hold'        => 'On hold',
			'wc-processing'     => 'Processing',
			'wc-completed'      => 'Completed',
			'wc-cancelled'      => 'Cancelled',
			'wc-refunded'       => 'Refunded',
			'wc-failed'         => 'Failed',
			'wc-checkout-draft' => 'Draft',
			'wc-all_refunded'   => 'All Refunded',
			// WooCommerce Subscriptions statuses.
			'wc-active'         => 'Active',
			'wc-expired'        => 'Expired',
			'wc-pending-cancel' => 'Pending Cancellation',
		);
	}
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Load JP4WC framework required by request and endpoint classes.
require_once dirname( __DIR__ ) . '/includes/jp4wc-framework/class-jp4wc-framework.php';

use Brain\Monkey;
