<?php
/**
 * Bootstrap for WordPress integration tests.
 *
 * Loads WordPress + WooCommerce directly without WP_UnitTestCase to avoid
 * compatibility issues between wp-phpunit and PHPUnit 10.x.
 *
 * Run via: composer test:integration:wp-env
 * (inside wp-env tests-cli container only)
 */

// Verify we're inside the container.
// Use PAYGENT_WP_ROOT to avoid conflict with WP_TESTS_DIR set by wp-phpunit autoloader.
$wp_root = getenv( 'PAYGENT_WP_ROOT' ) ?: '/var/www/html';
if ( ! file_exists( $wp_root . '/wp-load.php' ) ) {
	echo "ERROR: WordPress not found at {$wp_root}. Run tests inside wp-env (composer test:integration:wp-env).\n";
	exit( 1 );
}

// Composer autoloader.
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Define constants before loading WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $wp_root . '/' );
}
if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
}

// Load WordPress.
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require_once $wp_root . '/wp-load.php';

// Verify WooCommerce is loaded.
if ( ! function_exists( 'wc_get_order' ) ) {
	echo "ERROR: WooCommerce not loaded. Check .wp-env.json plugins configuration.\n";
	exit( 1 );
}

// Load our plugin (if not already loaded by WordPress).
$plugin_file = dirname( __DIR__, 2 ) . '/woocommerce-for-paygent-payment-main.php';
if ( file_exists( $plugin_file ) && ! defined( 'WC_PAYGENT_PLUGIN_FILE' ) ) {
	require_once $plugin_file;
}
// In the tests-cli container the plugin is not active in the DB, so
// plugins_loaded has already fired before we require the plugin file.
// init() defines constants but hooks on_plugins_loaded() to the 'init'
// action, which may also have fired already. Call both directly so that
// gateway class files (and the JP4WC framework they depend on) are loaded.
if ( ! defined( 'WC_PAYGENT_PLUGIN_PATH' ) && class_exists( 'WC_Gateway_Paygent' ) ) {
	WC_Gateway_Paygent::instance()->init();
	WC_Gateway_Paygent::instance()->on_plugins_loaded();
}

echo "Bootstrap OK — WordPress " . get_bloginfo( 'version' ) . " + WooCommerce " . WC_VERSION . " loaded.\n";
