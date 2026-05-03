<?php
/**
 * Abstract Paygent Block Payment Method Integration
 *
 * @package WooCommerce_Paygent_Payment
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for all Paygent Block checkout payment method integrations.
 *
 * Reads gateway settings directly from the database without instantiating
 * the gateway class, keeping initialization lightweight.
 *
 * Subclasses must set $name (the gateway ID) and implement
 * get_payment_method_script_handles().
 */
abstract class Abstract_WC_Paygent_Block_Payment extends AbstractPaymentMethodType {
	// $settings is declared in AbstractPaymentMethodType; do not redeclare.

	/**
	 * Loads gateway settings from the database.
	 *
	 * @return void
	 */
	public function initialize(): void {
		$this->settings = get_option( 'woocommerce_' . $this->name . '_settings', array() );
	}

	/**
	 * Returns true when the gateway is enabled.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return filter_var( $this->settings['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Returns data passed to the payment method's JavaScript component via getSetting().
	 *
	 * @return array<string,mixed>
	 */
	public function get_payment_method_data(): array {
		return array(
			'title'       => $this->settings['title'] ?? '',
			'description' => $this->settings['description'] ?? '',
			'supports'    => $this->get_supported_features(),
		);
	}

	/**
	 * Returns the features supported by this gateway in Block checkout context.
	 *
	 * @return string[]
	 */
	public function get_supported_features(): array {
		return array( 'products' );
	}
}
