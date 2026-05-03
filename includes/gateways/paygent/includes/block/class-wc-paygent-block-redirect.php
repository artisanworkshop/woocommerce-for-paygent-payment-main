<?php
/**
 * Paygent Redirect-type Block Payment Method Integration
 *
 * @package WooCommerce_Paygent_Payment
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Block checkout integration for Paygent redirect-based payment gateways.
 *
 * ATM, BN, PayPay, and Rakuten Pay all redirect the customer to an external
 * page after order placement and require no custom payment form. This single
 * class handles all four by accepting the gateway name as a constructor argument.
 *
 * Usage:
 *   new WC_Paygent_Block_Redirect( 'paygent_atm',        array( 'products', 'refunds' ) );
 *   new WC_Paygent_Block_Redirect( 'paygent_bn',         array( 'products', 'refunds' ) );
 *   new WC_Paygent_Block_Redirect( 'paygent_paypay',     array( 'products', 'refunds' ) );
 *   new WC_Paygent_Block_Redirect( 'paygent_rakutenpay', array( 'products', 'refunds' ) );
 */
class WC_Paygent_Block_Redirect extends Abstract_WC_Paygent_Block_Payment {

	/**
	 * Features supported by this gateway in Block checkout.
	 *
	 * @var string[]
	 */
	private array $supported_features;

	/**
	 * Constructor.
	 *
	 * @param string   $name              Gateway ID (e.g. 'paygent_atm').
	 * @param string[] $supported_features Feature list for Block checkout supports config.
	 */
	public function __construct( string $name, array $supported_features = array( 'products' ) ) {
		$this->name               = $name;
		$this->supported_features = $supported_features;
	}

	/**
	 * Returns the shared script handle for all redirect gateways.
	 *
	 * All four gateways share one JS bundle. The script is registered once
	 * using the webpack-generated asset file for accurate dependency resolution.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles(): array {
		if ( ! wp_script_is( 'wc-paygent-block-redirect', 'registered' ) ) {
			$asset_file = WC_PAYGENT_ABSPATH . 'build/paygent-redirect.asset.php';
			$asset      = file_exists( $asset_file )
				? require $asset_file
				: array(
					'dependencies' => array(),
					'version'      => WC_PAYGENT_VERSION,
				);

			wp_register_script(
				'wc-paygent-block-redirect',
				WC_PAYGENT_PLUGIN_URL . 'build/paygent-redirect.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);
		}

		return array( 'wc-paygent-block-redirect' );
	}

	/**
	 * Returns the features supported by this gateway.
	 *
	 * @return string[]
	 */
	public function get_supported_features(): array {
		return $this->supported_features;
	}
}
