<?php
/**
 * Plugin Name: PAYGENT for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/woocommerce-for-paygent-payment-main/
 * Description: Paygent Payments for WooCommerce in Japan
 * Version: 3.0.1
 * Requires Plugins: woocommerce
 * Author: Artisan Workshop
 * Author URI: https://wc.artws.info/
 * Requires at least: 5.0
 * Tested up to: 7.1
 * WC requires at least: 8.0.0
 * WC tested up to: 11.2.1
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

// PHP security notice shared with other Artisan Workshop plugins. Shown without WooCommerce too.
// Optional: a request that runs while this plugin is updated goes on without it.
if ( is_readable( __DIR__ . '/includes/artws-php-security-notice/register.php' ) ) {
	include_once __DIR__ . '/includes/artws-php-security-notice/register.php';
}
if ( function_exists( 'artws_php_security_notice_register' ) ) {
	artws_php_security_notice_register( '1.0.0', __DIR__ . '/includes/artws-php-security-notice/class-notice.php', 'wc_gateway_paygent_php_security_notice_strings' );
}

/**
 * Strings of the PHP security notice, translated with this plugin's text domain.
 *
 * The shared notice calls this when it displays, after init. A WordPress.org
 * language pack wins over the bundled translation but has no translation for
 * new strings until they are translated there, so the strings it lacks are
 * taken from the bundled file instead of being shown in English.
 *
 * @since 3.0.1
 * @return array
 */
function wc_gateway_paygent_php_security_notice_strings() {
	// gettext, not gettext_{$domain}: that filter is WordPress 5.5+.
	add_filter( 'gettext', 'wc_gateway_paygent_php_security_notice_bundled_translation', 10, 3 );
	try {
		return array(
			'title'          => __( 'Update PHP now: critical security vulnerabilities', 'woocommerce-for-paygent-payment-main' ),
			/* translators: 1: PHP version of this site, 2: first fixed PHP version, 3: vulnerability IDs */
			'vulnerable'     => __( 'The PHP version of this site (%1$s) has critical vulnerabilities (%3$s) that let an attacker run code on the server with a single request. Update PHP to %2$s or later right away.', 'woocommerce-for-paygent-payment-main' ),
			'support_ending' => __( 'Security support for PHP 8.2 ends at the end of 2026, so we recommend updating to PHP 8.3 or later.', 'woocommerce-for-paygent-payment-main' ),
			'vendor_patch'   => __( 'Your hosting company may have applied the fix without changing the PHP version number. Please check with your hosting company.', 'woocommerce-for-paygent-payment-main' ),
			/* translators: 1: PHP version of this site, 2: vulnerability IDs */
			'unsupported'    => __( 'The PHP version of this site (%1$s) has critical vulnerabilities (%2$s). Official support for this PHP version has ended, and no fixed version has been released for it. Some hosting companies apply their own security patches, but update to PHP 8.3 or later, which is supported longer, as soon as possible.', 'woocommerce-for-paygent-payment-main' ),
			/* translators: 1: PHP version of this site, 2: first fixed PHP version, 3: vulnerability IDs */
			'prerelease'     => __( 'This site runs a pre-release of PHP (%1$s) that has critical vulnerabilities (%3$s). Update it to %2$s or later.', 'woocommerce-for-paygent-payment-main' ),
			'learn_more'     => __( 'Learn more', 'woocommerce-for-paygent-payment-main' ),
			'dismiss'        => __( 'Don\'t show again', 'woocommerce-for-paygent-payment-main' ),
			'close'          => __( 'Close', 'woocommerce-for-paygent-payment-main' ),
		);
	} finally {
		remove_filter( 'gettext', 'wc_gateway_paygent_php_security_notice_bundled_translation', 10 );
	}
}

/**
 * Returns the bundled translation of a string the loaded translations lack.
 *
 * WP_Translation_File is WordPress 6.5+, so older versions read the file with MO.
 *
 * @since 3.0.1
 * @param string $translation Translation from the loaded files.
 * @param string $text        Text to translate.
 * @param string $domain      Text domain.
 * @return string
 */
function wc_gateway_paygent_php_security_notice_bundled_translation( $translation, $text, $domain ) {
	static $files = array();
	if ( 'woocommerce-for-paygent-payment-main' !== $domain || $translation !== $text ) {
		return $translation;
	}
	$locale = determine_locale();
	if ( ! array_key_exists( $locale, $files ) ) {
		$mofile           = __DIR__ . '/i18n/woocommerce-for-paygent-payment-main-' . $locale . '.mo';
		$files[ $locale ] = false;
		if ( is_readable( $mofile ) ) {
			if ( class_exists( 'WP_Translation_File' ) ) {
				$files[ $locale ] = WP_Translation_File::create( $mofile );
			} elseif ( class_exists( 'MO' ) ) {
				$mo               = new MO();
				$files[ $locale ] = $mo->import_from_file( $mofile ) ? $mo : false;
			}
		}
	}
	if ( ! $files[ $locale ] ) {
		return $translation;
	}
	$bundled = $files[ $locale ]->translate( $text );
	return is_string( $bundled ) && '' !== $bundled ? $bundled : $translation;
}

/**
 * Load plugin functions.
 */
add_action( 'plugins_loaded', 'wc_gateway_paygent_plugin' );

/**
 * Initialize the Paygent plugin.
 */
function wc_gateway_paygent_plugin() {
	// Use class_exists() rather than is_woocommerce_active() so the check works
	// regardless of the plugin directory name (e.g. wp-env uses
	// 'woocommerce.latest-stable/' which does not match the hardcoded slug check).
	if ( class_exists( 'WooCommerce' ) ) {
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
			<li><?php esc_html_e( 'Paygent Payment Gateways for WooCommerce is enabled but not effective. It requires WooCommerce in order to work.', 'woocommerce-for-paygent-payment-main' ); ?></li>
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
