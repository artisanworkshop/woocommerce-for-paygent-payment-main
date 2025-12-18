<?php
/**
 * WooCommerce Paygent Payment Gateway
 *
 * @package WooCommerce\Paygent
 * @version 2.4.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'WC_Gateway_Paygent' ) ) :

	/**
	 * Main class for WooCommerce Paygent Payment Gateway.
	 *
	 * Handles the integration with Paygent payment services.
	 */
	class WC_Gateway_Paygent {

		/**
		 * Paygent Payment Gateways for WooCommerce version.
		 *
		 * @var string
		 */
		public $version = '2.4.3';

		/**
		 * Paygent Payment Gateways for WooCommerce Framework version.
		 *
		 * @var string
		 */
		public $framework_version = '2.0.13';

		/**
		 * The single instance of the class.
		 *
		 * @var object
		 */
		protected static $instance = null;

		/**
		 * Paygent Payment Gateways for WooCommerce Constructor.
		 *
		 * @access public
		 */
		public function __construct() {
			// handle compatibility.
			add_action( 'before_woocommerce_init', array( $this, 'jp4wc_paygent_handle_compatibility' ) );
			// Add filter to available payment gateways.
			add_filter( 'woocommerce_available_payment_gateways', array( $this, 'paygent_edit_available_gateways' ) );
			// Add the gateway to WooCommerce.
			add_filter( 'woocommerce_payment_gateways', array( $this, 'add_wc_paygent_gateways' ) );
		}

		/**
		 * Get class instance.
		 *
		 * @return object Instance.
		 */
		public static function instance() {
			if ( null === static::$instance ) {
				static::$instance = new static();
			}
			return static::$instance;
		}


		/**
		 * Init the feature plugin, only if we can detect WooCommerce.
		 *
		 * @since 2.0.0
		 * @version 2.0.0
		 */
		public function init() {
			$this->define_constants();
			register_activation_hook( WC_PAYGENT_PLUGIN_FILE, array( $this, 'on_activation' ) );
			register_deactivation_hook( WC_PAYGENT_PLUGIN_FILE, array( $this, 'on_deactivation' ) );
			add_action( 'init', array( $this, 'on_plugins_loaded' ), 0 );
		}

		/**
		 * Flush rewrite rules on deactivate.
		 *
		 * @return void
		 */
		public function on_deactivation() {
			flush_rewrite_rules();
		}

		/**
		 * Setup plugin once all other plugins are loaded.
		 *
		 * @return void
		 */
		public function on_plugins_loaded() {
			$this->load_plugin_textdomain();
			$this->includes();
		}

		/**
		 * Define Constants.
		 */
		protected function define_constants() {
			define( 'WC_PAYGENT_PLUGIN_FILE', __FILE__ );
			define( 'WC_PAYGENT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
			define( 'WC_PAYGENT_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
			define( 'WC_PAYGENT_ABSPATH', __DIR__ . '/' );
			define( 'CLIENT_TEST3_FILE_PATH', WC_PAYGENT_PLUGIN_PATH . '/assets/files/test3-20221218_client_cert.pem' );
			define( 'CLIENT_FILE_PATH', WP_CONTENT_DIR . '/uploads/wc-paygent/client_cert.pem' );
			define( 'CA_FILE_PATH', WP_CONTENT_DIR . '/uploads/wc-paygent/curl-ca-bundle.crt' );
			define( 'WC_PAYGENT_VERSION', $this->version );
			define( 'WC_PAYGENT_FRAMEWORK_VERSION', $this->framework_version );
		}

		/**
		 * Load Localisation files.
		 */
		public function load_plugin_textdomain() {
			$locale = apply_filters( 'paygent_for_wc_plugin_locale', get_locale(), 'woocommerce-for-paygent-payment-main' );
			// Load plugin text domain.
			unload_textdomain( 'woocommerce-for-paygent-payment-main', true );
			load_textdomain( 'woocommerce-for-paygent-payment-main', WP_LANG_DIR . '/woocommerce-for-paygent-payment-main/woocommerce-for-paygent-payment-main-' . $locale . '.mo' );
			load_plugin_textdomain( 'woocommerce-for-paygent-payment-main', false, basename( __DIR__ ) . '/i18n' );
		}

		/**
		 * Include WC_Gateway_Paygent classes.
		 */
		private function includes() {
			// load framework.
			$version_text = 'v' . str_replace( '.', '_', WC_PAYGENT_FRAMEWORK_VERSION );
			if ( ! class_exists( '\\ArtisanWorkshop\\PluginFramework\\' . $version_text . '\\JP4WC_Framework' ) ) {
				require_once WC_PAYGENT_ABSPATH . 'includes/jp4wc-framework/class-jp4wc-framework.php';
			}
			// Admin Setting Screen.
			require_once WC_PAYGENT_ABSPATH . 'includes/admin/class-wc-admin-screen-paygent.php';
			// load autoload.
			require_once WC_PAYGENT_ABSPATH . 'vendor-wc/autoload.php';
			include_once WC_PAYGENT_ABSPATH . 'includes/gateways/paygent/includes/class-wc-gateway-paygent-request.php';
			// Credit Card.
			require_once WC_PAYGENT_ABSPATH . 'includes/gateways/paygent/class-wc-gateway-paygent-cc.php';
			require_once WC_PAYGENT_ABSPATH . 'includes/gateways/paygent/class-wc-gateway-paygent-addon-cc.php';
			// Convenience store.
			require_once WC_PAYGENT_ABSPATH . 'includes/gateways/paygent/class-wc-gateway-paygent-cs.php';
			// Carrier Payment.
			if ( get_option( 'wc-paygent-mb' ) ) {
				require_once WC_PAYGENT_ABSPATH . 'includes/gateways/paygent/class-wc-gateway-paygent-mb.php';
				require_once WC_PAYGENT_ABSPATH . 'includes/gateways/paygent/class-wc-gateway-paygent-addon-mb.php';
			}
			// Paidy Payment.
			if ( get_option( 'wc-paygent-paidy' ) ) {
				require_once WC_PAYGENT_ABSPATH . 'includes/gateways/paygent/class-wc-gateway-paygent-paidy.php';
			}
			// PayPay.
			if ( get_option( 'wc-paygent-paypay' ) ) {
				require_once WC_PAYGENT_ABSPATH . 'includes/gateways/paygent/class-wc-gateway-paygent-paypay.php';
			}
			// Rakuten Pay.
			if ( get_option( 'wc-paygent-rakutenpay' ) ) {
				require_once WC_PAYGENT_ABSPATH . 'includes/gateways/paygent/class-wc-gateway-paygent-rakuten-pay.php';
			}
			// Bank Net.
			if ( get_option( 'wc-paygent-bn' ) ) {
				require_once WC_PAYGENT_ABSPATH . 'includes/gateways/paygent/class-wc-gateway-paygent-bn.php';
			}
			// ATM Payment.
			if ( get_option( 'wc-paygent-atm' ) ) {
				require_once WC_PAYGENT_ABSPATH . 'includes/gateways/paygent/class-wc-gateway-paygent-atm.php';
			}
			// Multi-currency Credit Card// Multi-currency Credit Card.
			if ( get_option( 'wc-paygent-mccc' ) ) {
				require_once WC_PAYGENT_ABSPATH . 'includes/gateways/paygent/class-wc-gateway-paygent-mccc.php';
			}
			// Webhook Endpoint.
			require_once WC_PAYGENT_ABSPATH . 'includes/gateways/paygent/class-wc-paygent-endpoint.php';
			new WC_Paygent_Endpoint();
		}

		/**
		 * Attached to activate_{ plugin_basename( __FILES__ ) } by register_activation_hook()
		 *
		 * @static
		 */
		public static function on_activation() {
			$wc_paygent_dir = WP_CONTENT_DIR . '/uploads/wc-paygent';
			if ( ! is_dir( $wc_paygent_dir ) ) {
				$context = array( 'source' => 'wc-paygent' );
				$logger  = wc_get_logger();
				global $wp_filesystem;
				WP_Filesystem();
				if ( $wp_filesystem->mkdir( $wc_paygent_dir, 0755 ) ) {
					$logger->info( __( 'wc-paygent folder created.', 'woocommerce-for-paygent-payment-main' ), $context );
				} else {
					$logger->notice( __( 'wc-paygent folder could not be created.', 'woocommerce-for-paygent-payment-main' ), $context );
				}
			}
		}

		/**
		 * Declares HPOS compatibility if the plugin is compatible with HPOS.
		 *
		 * @internal
		 *
		 * @since 2.6.0
		 */
		public function jp4wc_paygent_handle_compatibility() {
			// HPOS compatibility.
			if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
				$slug = dirname( plugin_basename( __FILE__ ) );
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', trailingslashit( $slug ) . $slug . '.php', true );
			}
		}

		/**
		 * Edit available payment gateways.
		 *
		 * @param array $methods Existing payment methods.
		 * @return array Updated payment methods.
		 */
		public function paygent_edit_available_gateways( $methods ) {
			$currency = get_woocommerce_currency();
			if ( 'JPY' !== $currency ) {
				if ( get_option( 'wc-paygent-cc' ) ) {
					unset( $methods['paygent_cc'] );
				}
				if ( get_option( 'wc-paygent-cs' ) ) {
					unset( $methods['paygent_cs'] );
				}
				if ( get_option( 'wc-paygent-mb' ) ) {
					unset( $methods['paygent_mb'] );
				}
				if ( get_option( 'wc-paygent-paidy' ) ) {
					unset( $methods['paygent_paidy'] );
				}
				if ( get_option( 'wc-paygent-paypay' ) ) {
					unset( $methods['paygent_paypay'] );
				}
				if ( get_option( 'wc-paygent-rakutenpay' ) ) {
					unset( $methods['paygent_rakutenpay'] );
				}
				if ( get_option( 'wc-paygent-bn' ) ) {
					unset( $methods['paygent_bn'] );
				}
				if ( get_option( 'wc-paygent-atm' ) ) {
					unset( $methods['paygent_atm'] );
				}
			} elseif ( get_option( 'wc-paygent-mccc' ) ) {
				$available_currencies = array(
					'USD',
					'EUR',
					'GBP',
					'KRW',
					'CNY',
					'TWD',
					'HKD',
					'SGD',
					'AUD',
					'CAD',
					'DKK',
					'INR',
					'MYR',
					'NOK',
					'PHP',
					'RUB',
					'VND',
					'SEK',
					'CHF',
					'THB',
					'BRL',
					'IDR',
					'AED',
				);
				if ( ! in_array( $currency, $available_currencies, true ) ) {
					unset( $methods['paygent_mccc'] );
				}
			}
			return $methods;
		}

		/**
		 * Add Paygent gateways to WooCommerce.
		 *
		 * @param array $methods Existing payment methods.
		 * @return array Updated payment methods.
		 */
		public function add_wc_paygent_gateways( $methods ) {
			$subscription_support_enabled = false;
			// Credit Card.
			if ( get_option( 'wc-paygent-cc', false ) ) {
				if ( class_exists( 'WC_Subscriptions_Order' ) && function_exists( 'wcs_create_renewal_order' ) ) {
					$subscription_support_enabled = true;
				}
				if ( $subscription_support_enabled ) {
					$methods[] = 'WC_Gateway_Paygent_Addon_CC';
				} else {
					$methods[] = 'WC_Gateway_Paygent_CC';
				}
			}
			// Convenience store.
			if ( get_option( 'wc-paygent-cs', false ) ) {
				$methods[] = 'WC_Gateway_Paygent_CS';
			}
			// Carrier Payment.
			if ( get_option( 'wc-paygent-mb', false ) ) {
				if ( $subscription_support_enabled ) {
					$methods[] = 'WC_Gateway_Paygent_Addon_MB';
				} else {
					$methods[] = 'WC_Gateway_Paygent_MB';
				}
			}
			// Paidy Payment.
			if ( get_option( 'wc-paygent-paidy', false ) ) {
				$methods[] = 'WC_Gateway_Paygent_Paidy';
			}
			// PayPay.
			if ( get_option( 'wc-paygent-paypay', false ) ) {
				$methods[] = 'WC_Gateway_Paygent_PayPay';
			}
			// Rakuten Pay.
			if ( get_option( 'wc-paygent-rakutenpay', false ) ) {
				$methods[] = 'WC_Gateway_Paygent_Rakuten_Pay';
			}
			// Bank Net.
			if ( get_option( 'wc-paygent-bn', false ) ) {
				$methods[] = 'WC_Gateway_Paygent_BN';
			}
			// ATM Payment.
			if ( get_option( 'wc-paygent-atm', false ) ) {
				$methods[] = 'WC_Gateway_Paygent_ATM';
			}
			// Multi-currency credit card payments.
			if ( get_option( 'wc-paygent-mccc', false ) ) {
				$methods[] = 'WC_Gateway_Paygent_MCCC';
			}
			return $methods;
		}
	}
endif;
