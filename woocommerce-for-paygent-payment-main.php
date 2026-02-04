<?php
/**
 * Plugin Name: PAYGENT for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/woocommerce-for-paygent-payment-main/
 * Description: Paygent Payments for WooCommerce in Japan
 * Version: 2.4.8
 * Requires Plugins: woocommerce
 * Author: Artisan Workshop
 * Author URI: https://wc.artws.info/
 * Requires at least: 5.0
 * Tested up to: 6.9.0
 * WC requires at least: 8.0.0
 * WC tested up to: 10.4.3
 *
 * Text Domain: woocommerce-for-paygent-payment-main
 * Domain Path: /i18n/
 *
 * @package woocommerce-for-paygent-payment-main
 * @category Core
 * @author Artisan Workshop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

require_once 'class-wc-gateway-paygent.php';

/**
 * Load plugin functions.
 */
add_action( 'plugins_loaded', 'wc_gateway_paygent_plugin' );

/**
 * Initialize the Paygent plugin.
 */
function wc_gateway_paygent_plugin() {
	if ( is_woocommerce_active() && class_exists( 'WooCommerce' ) ) {
		WC_Gateway_Paygent::instance()->init();
	} else {
		add_action( 'admin_notices', 'wc_gateway_paygent_fallback_notice' );
	}
}

/**
 * Display admin notice when WooCommerce is not active.
 */
function wc_gateway_paygent_fallback_notice() {
	?>
	<div class="error">
		<ul>
			<li><?php esc_html_e( 'Paygent Payment Gateways for WooCommerce is enabled but not effective. It requires WooCommerce in order to work.', 'woocommerce-for-japan' ); ?></li>
		</ul>
	</div>
	<?php
}

/**
 * WC Detection
 */
if ( ! function_exists( 'is_woocommerce_active' ) ) {
	/**
	 * Check if WooCommerce is active.
	 *
	 * @return bool True if WooCommerce is active, false otherwise.
	 */
	function is_woocommerce_active() {
		if ( ! isset( $active_plugins ) ) {
			$active_plugins = (array) get_option( 'active_plugins', array() );

			if ( is_multisite() ) {
				$active_plugins = array_merge( $active_plugins, get_site_option( 'active_sitewide_plugins', array() ) );
			}
		}
		return in_array(
			'woocommerce/woocommerce.php',
			$active_plugins,
			true
		)
			|| array_key_exists(
				'woocommerce/woocommerce.php',
				$active_plugins
			);
	}
}
