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
	 * Maps gateway ID to the icon filename in assets/images/.
	 *
	 * @var array<string,string>
	 */
	private static array $icon_map = array(
		'paygent_atm'        => 'atm_logo.svg',
		'paygent_bn'         => 'bank_net_logo.svg',
		'paygent_paypay'     => 'paypay_logo.svg',
		'paygent_rakutenpay' => 'rakuten_pay_logo.svg',
	);

	/**
	 * Features supported by this gateway in Block checkout.
	 *
	 * @var string[]
	 */
	private array $supported_features;

	/**
	 * Constructor.
	 *
	 * @param string   $name               Gateway ID (e.g. 'paygent_atm').
	 * @param string[] $supported_features  Feature list for Block checkout supports config.
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
	 * Returns data passed to the JS component, including the payment icon URL.
	 *
	 * @return array<string,mixed>
	 */
	public function get_payment_method_data(): array {
		$data      = parent::get_payment_method_data();
		$icon_file = self::$icon_map[ $this->name ] ?? null;

		if ( $icon_file ) {
			$data['icon_url'] = WC_PAYGENT_PLUGIN_URL . 'assets/images/' . $icon_file;
		}

		return $data;
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
