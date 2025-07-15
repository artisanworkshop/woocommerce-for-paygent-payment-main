<?php
/**
 * WooCommerce Paygent Multi-currency Credit Card Gateway
 *
 * Provides a Paygent Multi-currency Credit Card Payment Gateway integration for WooCommerce.
 *
 * @version 2.4.0
 * @package WooCommerce/Gateways
 * @category Payment Gateways
 * @author Artisan Workshop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ArtisanWorkshop\PluginFramework\v2_0_13 as Framework;

/**
 * Class WC_Gateway_Paygent_MCCC
 *
 * Handles Multi-currency Credit Card payments through Paygent payment gateway.
 *
 * @extends WC_Payment_Gateway
 */
class WC_Gateway_Paygent_MCCC extends WC_Payment_Gateway {
	/**
	 * Framework.
	 *
	 * @var object
	 */
	public $jp4wc_framework;

	/**
	 * Debug mode
	 *
	 * @var string
	 */
	public $debug;

	/**
	 * Test mode
	 *
	 * @var string
	 */
	public $test_mode;

	/**
	 * Set gmopg request class
	 *
	 * @var stdClass
	 */
	public $paygent_request;

	/**
	 * Constructor for the gateway.
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() {

		$this->id                = 'paygent_mccc';
		$this->has_fields        = false;
		$this->order_button_text = sprintf(
			// Translators: %s is the payment method name.
			__( 'Proceed to %s', 'woocommerce-for-paygent-payment-main' ),
			__( 'Multi-currency Credit Card', 'woocommerce-for-paygent-payment-main' )
		);
		$this->method_title = __( 'Paygent Multi-currency Credit Card', 'woocommerce-for-paygent-payment-main' );

		// Create plugin fields and settings.
		$this->init_form_fields();
		$this->init_settings();
		$this->method_title       = __( 'Paygent Multi-currency Credit Card Payment Gateway', 'woocommerce-for-paygent-payment-main' );
		$this->method_description = __( 'Allows payments by Paygent Multi-currency Credit Card in Japan.', 'woocommerce-for-paygent-payment-main' );
		$this->supports           = array(
			'products',
			'refunds',
			'tokenization',
			'default_credit_card_form',
		);

		// Get setting values.
		foreach ( $this->settings as $key => $val ) {
			$this->$key = $val;
		}

		// Set JP4WC framework.
		$this->jp4wc_framework = new Framework\JP4WC_Framework();

		include_once 'includes/class-wc-gateway-paygent-request.php';
		$this->paygent_request = new WC_Gateway_Paygent_Request();

		// Set Test mode.
		$this->test_mode = get_option( 'wc-paygent-testmode' );

		// Load plugin checkout icon.
		$this->icon = plugins_url( 'images/paygent-cards.png', __FILE__ );
		// When no save setting error at chackout page.
		if ( is_null( $this->title ) ) {
			$this->title = __( 'Please set this payment at Control Panel! ', 'woocommerce-for-paygent-payment-main' ) . $this->method_title;
		}

		// Actions.
		add_action( 'woocommerce_receipt_paygent_mccc', array( $this, 'receipt_page' ) );
		add_action( 'woocommerce_update_options_payment_gateways', array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'add_paygent_mccc_token_scripts' ) );
		add_filter( 'woocommerce_order_button_html', array( $this, 'paygent_mccc_token_order_button_html' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'tds_status_change' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'redirect_code' ) );
	}

	/**
	 * Initialize Gateway Settings Form Fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'           => array(
				'title'       => __( 'Enable/Disable', 'woocommerce-for-paygent-payment-main' ),
				'label'       => __( 'Enable paygent Multi-currency Credit Card Payment', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
			'title'             => array(
				'title'       => __( 'Title', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-for-paygent-payment-main' ),
				'default'     => __( 'Multi-currency Credit Card', 'woocommerce-for-paygent-payment-main' ),
			),
			'description'       => array(
				'title'       => __( 'Description', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce-for-paygent-payment-main' ),
				'default'     => __( 'Pay with your credit card via Paygent.', 'woocommerce-for-paygent-payment-main' ),
			),
			'order_button_text' => array(
				'title'       => __( 'Order Button', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the order button which the user sees during checkout.', 'woocommerce-for-paygent-payment-main' ),
				// translators: %s is the payment method name.
				'default'     => sprintf( __( 'Proceed to %s', 'woocommerce-for-paygent-payment-main' ), __( 'Multi-currency Credit Card', 'woocommerce-for-paygent-payment-main' ) ),
			),
			'store_card_info'   => array(
				'title'       => __( 'Store Card Infomation', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable Store Card Infomation', 'woocommerce-for-paygent-payment-main' ),
				'default'     => 'no',
				'description' => sprintf( __( 'Store user Credit Card information in Paygent Server.(Option)', 'woocommerce-for-paygent-payment-main' ) ),
			),
			'tds2_check'        => array(
				'title'       => __( '3D Secure 2.0', 'woocommerce-for-paygent-payment-main' ),
				'label'       => __( 'Enable 3D Secure 2.0', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'checkbox',
				'description' => __( '* Application is required. Please make sure your application is complete.', 'woocommerce-for-paygent-payment-main' ),
				'default'     => 'no',
			),
			'tds2_hashkey'      => array(
				'title'       => __( '3D Secure result acceptance hash key', 'woocommerce-for-paygent-payment-main' ),
				'label'       => __( '3D Secure result acceptance hash key', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'text',
				'description' => __( 'Please input 3D Secure result acceptance hash key, if you use 3D Secure 2.0.', 'woocommerce-for-paygent-payment-main' ),
			),
			'merchant_name'     => array(
				'title'       => __( 'Store name', 'woocommerce-for-paygent-payment-main' ),
				'label'       => __( 'Store name', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'text',
				'description' => __( 'Input Store name.', 'woocommerce-for-paygent-payment-main' ),
			),
			'paymentaction'     => array(
				'title'       => __( 'Payment Action', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'Choose whether you wish to capture funds immediately or authorize payment only.', 'woocommerce' ),
				'default'     => 'sale',
				'desc_tip'    => true,
				'options'     => array(
					'sale'          => __( 'Capture', 'woocommerce-for-paygent-payment-main' ),
					'authorization' => __( 'Authorize', 'woocommerce-for-paygent-payment-main' ),
				),
			),
			'debug'             => array(
				'title'       => __( 'Debug Mode', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable Debug Mode', 'woocommerce-for-paygent-payment-main' ),
				'default'     => 'no',
				'description' => __( 'Save debug data using WooCommerce logging.', 'woocommerce-for-paygent-payment-main' ),
			),
		);
	}

	/**
	 * UI - Payment page fields for paygent Payment.
	 */
	public function payment_fields() {
		// Description of payment method from settings.
		if ( $this->description ) { ?>
		<p><?php echo esc_html( $this->description ); ?></p>
			<?php
		}

		// Check the tokens.
		$user_id    = get_current_user_id();
		$tokens     = false;
		$paygent_cc = new WC_Gateway_Paygent_CC();

		if ( 'yes' === $this->store_card_info && is_checkout() ) {
			?>
			<?php
			$tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, $this->id );
			if ( 'yes' === $this->store_card_info ) {
				$paygent_cc->display_stored_user_data( $tokens );
			}
			?>
			<?php
		}

		if ( $tokens ) {
			echo '<div id="paygent-new-info" style="display:none">';
		} else {
			echo '<div id="paygent-new-info">';
		}
		$payment_gateway_cc     = new WC_Payment_Gateway_CC();
		$payment_gateway_cc->id = $this->id;
		$payment_gateway_cc->form();
		echo '</div>';
		if ( '1' === $this->test_mode ) {
			$merchant_id = get_option( 'wc-paygent-test-mid' );
			$token_key   = get_option( 'wc-paygent-test-tokenkey' );
		} else {
			$merchant_id = get_option( 'wc-paygent-mid' );
			$token_key   = get_option( 'wc-paygent-tokenkey' );
		}
		if ( 'yes' === $this->tds2_check ) {
			self::input_cardholder_name();
		}
		$paygent_cc->paygent_token_js( $merchant_id, $token_key, $tokens );

		echo '</div>';
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id Order ID.
	 * @return mixed
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		$user  = wp_get_current_user();
		if ( 0 !== $user->ID ) {
			$customer_id = $user->ID;
		} else {
			$customer_id = $order_id . '-user';
		}
		$send_data = array();

		// Common header.
		$telegram_kind = '180';// Multi-currency Card Payment Auth.
		$prefix_order  = get_option( 'wc-paygent-prefix_order' );
		if ( $prefix_order ) {
			$send_data['trading_id'] = $prefix_order . $order_id;
		} else {
			$send_data['trading_id'] = 'wc_' . $order_id;
		}
		$send_data['payment_id']        = null;
		$send_data['security_code_use'] = 1;

		$send_data['payment_amount'] = $order->get_total();

		// get Token Data.
		$card_token     = $this->jp4wc_framework->get_post( 'paygent_cc-token' );
		$card_cvc_token = $this->jp4wc_framework->get_post( 'paygent_cc-cvc_token' );

		// Get Currency infomation.
		$currency                   = get_woocommerce_currency();
		$send_data['currency_code'] = $currency;

		// Create server request using stored or new payment details.
		if ( 0 !== $user->ID ) {
			$card_user_id = 'wc' . $user->ID;
		} else {
			$card_user_id = 'wc' . $customer_id;
		}

		// Card information deposit function.
		if ( is_user_logged_in() && ( 'yes' === $this->store_card_info ) ) {
			$send_data['security_code_token'] = 1;
			$send_data['card_token']          = $card_cvc_token;
			$send_data['stock_card_mode']     = 1;
			$send_data['customer_id']         = $card_user_id;
			if ( 'yes' === $this->jp4wc_framework->get_post( 'paygent-use-stored-payment-info' ) ) {
				$send_data['customer_card_id'] = $this->jp4wc_framework->get_post( 'stored-info' );
			} else {
				$stored_user_card_data         = $this->add_stored_user_data( $card_user_id, $card_token, $order );
				$send_data['customer_card_id'] = $stored_user_card_data['result_array'][0]['customer_card_id'];
			}
		} else {
			// Credit Card Token Information.
			$send_data['security_code_token'] = 1;
			$send_data['card_token']          = $card_cvc_token;
			$send_data['card_token']          = $card_token;
		}

		// Payment times.
		$send_data['payment_class'] = 10;// One time payment.

		// 3D Secure Setting
		$send_data['term_url']        = $this->get_return_url( $order );
		$send_data['http_accept']     = $_SERVER['HTTP_ACCEPT'];
		$send_data['http_user_agent'] = $_SERVER['HTTP_USER_AGENT'];

		$response = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );

		// Check response.
		if ( isset( $response['result'] ) && '0' === $response['result'] && $response['result_array'] ) {
			// Success.
			$order->add_order_note( __( 'paygent Payment completed. Transaction ID: ', 'woocommerce-for-paygent-payment-main' ) . $response['result_array'][0]['payment_id'] );
			$order->add_meta_data( '_paygent_order_id', $send_data['trading_id'], true );
			// set transaction id for Paygent Order Number.
			$order->payment_complete( wc_clean( $response['result_array'][0]['payment_id'] ) );

			if ( isset( $this->paymentaction ) && 'sale' === $this->paymentaction ) {
				$telegram_kind = '182';
				$response_sale = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );
				if ( $response_sale['result'] != 0 ) {
					$this->paygent_request->error_response( $response_sale, $order );
				}
			}
			// Return thank you redirect.
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);

		} elseif ( '7' === $response['result'] ) {// 3D Secure.
			$order->add_order_note( __( 'Success accept to 3D Secure.', 'woocommerce-for-paygent-payment-main' ) . $response['result_array'][0]['attempt_kbn'] );
			$order->add_meta_data( '_paygent_order_id', $send_data['trading_id'], true );
			// Mark as on-hold (we're awaiting the payment).
			$order->update_status( 'on-hold', __( '3DSecure Payment Processing.', 'woocommerce-for-paygent-payment-main' ) );

			$htmls   = explode( "\n", $response['result_array'][0]['out_acs_html'] );
			$action  = substr( $htmls[11], 34, -17 );
			$pareq   = substr( $htmls[13], 56, -13 );
			$termurl = substr( $htmls[14], 58, -13 );
			$md      = substr( $htmls[15], 53, -13 );

			$tds_url = $this->get_return_url( $order ) . '&action=' . urlencode( $action ) . '&pareq=' . $pareq . '&termurl=' . urlencode( $termurl ) . '&md=' . $md;

			// Return thankyou redirect.
			return array(
				'result'   => 'success',
				'redirect' => $tds_url,
			);
		} else { // System Error.
			$this->paygent_request->error_response( $response, $order );
		}
	}

	/**
	 * Redirect Checkout page.
	 *
	 * @param int $order_id Order ID.
	 */
	public function redirect_code( $order_id ) {
		$order          = new WC_Order( $order_id );
		$payment_method = version_compare( WC_VERSION, '2.7', '<' ) ? get_post_meta( $order_id, '_payment_method', true ) : $order->get_payment_method();
		if ( isset( $_GET['action'] )
		&& isset( $_GET['pareq'] )
		&& isset( $_GET['termurl'] )
		&& $payment_method === $this->id ) {
			$url     = $_GET['action'];// phpcs: URL to redirect to.
			$pareq   = $_GET['pareq'];
			$termurl = $_GET['termurl'];
			$md      = $_GET['md'];
			echo '   <form name="TdsStart" action="' . esc_url( $url ) . '" method="POST">
	  <br>
	  <br>
	  <div style="text-align: center;">
		<h2>
		  ' . esc_html__( 'We will continue to make payments with 3D Secure.', 'woocommerce-for-paygent-payment-main' ) . '<br>
		  ' . esc_html__( 'Click the button.', 'woocommerce-for-paygent-payment-main' ) . '
		</h2>
		<input type="submit" value="' . esc_attr__( 'OK', 'woocommerce-for-paygent-payment-main' ) . '">
	  </div>
	  <input type="hidden" name="PaReq" value="' . esc_attr( $pareq ) . '">
	  <input type="hidden" name="TermUrl" value="' . esc_attr( $termurl ) . '">
	  <input type="hidden" name="MD" value="' . esc_attr( $md ) . '">
	</form>
    <script>
    <!--
     window.onload =  function OnLoadEvent() {
        document.TdsStart.submit();
      }
    //-->
    </script>
';
		}
	}

	/**
	 * Get 3D Scure Payment Status and update Woo Order Status
	 */
	function tds_status_change( $order_id ) {
		$order            = wc_get_order( $order_id );
		$payment_method   = $order->get_payment_method();
		$paygent_order_id = $order->get_meta( '_paygent_order_id' );
		$prefix_order     = get_option( 'wc-paygent-prefix_order' );
		if ( isset( $_GET['trading_id'] ) ) {
			if ( $paygent_order_id ) {
				$base_order_id = substr( $_GET['trading_id'], strlen( $prefix_order ) );
			} else {
				$base_order_id = substr( $_GET['trading_id'], 3 );
			}
		}

		if ( isset( $_GET['trading_id'] ) && $payment_method === $this->id && $order_id === $base_order_id ) {
			// set transaction id for Paygent Order Number.
			$order->set_transaction_id( wc_clean( $_GET['payment_id'] ) );
			// Mark as processing (payment complete).
			$order->update_status( 'processing', __( '3D Secure payment was complete.', 'woocommerce-for-paygent-payment-main' ) );
			// Reduce stock levels.
			wc_reduce_stock_levels( $order_id );

			// Sale payment action.
			if ( isset( $this->paymentaction ) && 'sale' === $this->paymentaction ) {
				$telegram_kind           = '182';
				$send_data['trading_id'] = $_GET['trading_id'];
				if ( '1' !== $this->paygent_request->site_id ) {
					$send_data['site_id'] = $this->paygent_request->site_id;
				} else {
					$send_data['site_id'] = 1;
				}
				$response_sale = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );
				if ( 0 !== $response_sale['result'] ) {
					$this->paygent_request->error_response( $response_sale, $order );
				}
			}
			return;
		} elseif ( isset( $_GET['result'] ) && $order->get_payment_method() === $this->id && 1 === $_GET['result'] ) {
			// set transaction id for Paygent Order Number.
			$order->set_transaction_id( wc_clean( $_GET['payment_id'] ) );
			// Mark as failed (payment failed).
			$order->update_status( 'failed', __( 'Error at 3D Secure.', 'woocommerce-for-paygent-payment-main' ) . $_GET['response_code'] . ':' . urldecode( $_GET['response_detail'] ) );
		}
	}

	/**
	 * Process a payment for an ongoing subscription.
	 */
	public function process_scheduled_subscription_payment( $amount_to_charge, $order, $product_id ) {
	}

	/**
	 * Check if the user has any billing records in the Customer Vault.
	 *
	 * @param int $user_id User ID.
	 */
	public function user_has_stored_data( $user_id ) {
		include_once 'includes/class-wc-gateway-paygent-request.php';
		$telegram_kind = '027';
		$send_data     = array(
			'trading_id'  => '',
			'customer_id' => $user_id,
		);
		$site_id       = get_option( 'wc-paygent-sid' );
		if ( '1' !== $site_id ) {
			$send_data['site_id'] = $site_id;
		}
		$order = wc_get_order();

		// Check test mode.
		$test_mode = get_option( 'wc-paygent-testmode' );

		$paygent_request = new WC_Gateway_Paygent_Request( $this );

		$result = $paygent_request->send_paygent_request( $test_mode, $order, $telegram_kind, $send_data );
		return $result;
	}

	/**
	 * Display payment method in Payment page when user have stored card data
	 *
	 * @param  array $tokens Stored tokens.
	 * @return void
	 */
	public function display_stored_user_data( $tokens ) {
		foreach ( $tokens as $key => $value ) {
			foreach ( $value->get_meta_data() as $data_key => $data_value ) {
				$main_key = 'key';
				foreach ( $data_value->get_data() as $meta_key => $meta_value ) {
					if ( 'key' === $meta_key ) {
						$main_key = $meta_value;
					} elseif ( 'value' === $meta_key ) {
						$paygent_tokens[ $key ][ $main_key ] = $meta_value;
					}
				}
			}
		}
		if ( $tokens ) {
			?>
		<fieldset>
		<input type="radio" name="paygent-use-stored-payment-info" id="paygent-use-stored-payment-info-yes" value="yes" checked="checked" onclick="document.getElementById('paygent-new-info').style.display='none'; document.getElementById('paygent-stored-info').style.display='block'"; />
		<label for="paygent-use-stored-payment-info-yes" style="display: inline;"><?php esc_html_e( 'Use stored credit card information.', 'woocommerce-for-paygent-payment-main' ); ?></label>
		<div id="paygent-stored-info" style="padding: 10px 0 0 40px; clear: both;">
		<select name="stored-info" id="stored-info">
			<?php foreach ( $paygent_tokens as $key => $value ) { ?>
				<option class="<?php echo esc_attr( $value['card_type'] ); ?>" value="<?php echo esc_attr( $value['customer_card_id'] ); ?>"><?php esc_html_e( 'credit card last some numbers: ', 'woocommerce-for-paygent-payment-main' ); ?><?php echo esc_html( $value['last4'] ); ?> (<?php echo esc_html( $value['expiry_month'] . '/' . substr( $value['expiry_year'], -2 ) ); ?>)</option>
			<?php } ?>
		</select>
		<p class="form-row form-row-first woocommerce-validated">
			<label for="paygent_mccc-stored-card-cvc"><?php echo esc_html__( 'Card code', 'woocommerce' ); ?><span class="required">*</span></label>
			<input id="paygent_mccc-stored-card-cvc" class="input-text wc-credit-card-form-card-cvc" inputmode="numeric" autocomplete="off" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" maxlength="4" placeholder="CVC" name="paygent_cc-stored-card-cvc" style="width:100px">
		</p>
		</fieldset>
		<fieldset>
		<input type="radio" name="paygent-use-stored-payment-info" id="paygent-use-stored-payment-info-no" value="no" onclick="document.getElementById('paygent-stored-info').style.display='none'; document.getElementById('paygent-new-info').style.display='block'"; />
		<label for="paygent-use-stored-payment-info-no"  style="display: inline;"><?php esc_html_e( 'Use a new payment method', 'woocommerce-for-paygent-payment-main' ); ?></label>
		</fieldset>
		<?php } else { ?>
		<fieldset>
		<div id="error"></div>
		<!-- Show input boxes for new data -->
		</fieldset>
			<?php
		}
	}

	/**
	 * Add User card info to Paygent server and Token system in WooCommerce
	 *
	 * @param string          $user_id User ID.
	 * @param string          $card_token Card token.
	 * @param object WP_Order $order Order object.
	 * @return mixed
	 */
	public function add_stored_user_data( $user_id, $card_token, $order ) {
		$telegram_kind = '025';
		$send_data     = array(
			'trading_id'      => '',
			'customer_id'     => $user_id,
			'valid_check_flg' => '1',
		);

		// Check and Set site id.
		$site_id = get_option( 'wc-paygent-sid' );
		if ( '1' !== $site_id ) {
			$send_data['site_id'] = $site_id;
		}

		if ( isset( $card_token ) ) {
			$send_data['card_token'] = $card_token;
		} else {
			wc_add_notice( __( 'Input information of the credit card is not enough.', 'woocommerce-for-paygent-payment-main' ), $notice_type = 'error' );
			return false;
		}
		$result = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );
		if ( '1' === $result['result'] ) {
			$order->add_order_note( __( 'Card information input error. Fault to stored your card info.', 'woocommerce-for-paygent-payment-main' ) . $result['responseCode'] . ':' . mb_convert_encoding( $result['responseDetail'], 'UTF-8', 'SJIS' ) );
			$error_message = $this->make_error_message( $result );
			wc_add_notice( $error_message . __( 'Card information input error. Fault to stored your card info.', 'woocommerce-for-paygent-payment-main' ), $notice_type = 'error' );
			return false;
		} else {
			$send_data['customer_card_id'] = $result['result_array'][0]['customer_card_id'];
			$order->add_order_note( __( 'Stored card info.', 'woocommerce-for-paygent-payment-main' ) . ' Customer Card Id : ' . $result['result_array'][0]['customer_card_id'] );
			$customer_card_id = $result['result_array'][0]['customer_card_id'];
			$card_last4       = substr( $result['result_array'][0]['masked_card_number'], -4 );
			$expiry_month     = substr( $result['result_array'][0]['card_valid_term'], 0, 2 );
			$expiry_year      = substr( $result['result_array'][0]['card_valid_term'], -2 );
			// Set and save token to WooCommerce.
			$token = new WC_Payment_Token_CC();
			$token->set_token( $card_token );
			$token->set_gateway_id( $this->id );
			$token->set_last4( $card_last4 );
			$token->set_card_type( $this->jp4wc_framework->get_post( 'card_type' ) );
			$token->set_expiry_month( $expiry_month );
			$token->set_expiry_year( '20' . $expiry_year );
			$token->set_user_id( get_current_user_id() );
			$token->add_meta_data( 'customer_card_id', $customer_card_id );
			$token->save();
		}
		return $result;
	}

	/**
	 * Check payment details for valid format
	 */
	public function validate_fields() {
		// Check for saving payment info without having or creating an account.
		if ( $this->jp4wc_framework->get_post( 'saveinfo' ) && ! is_user_logged_in() && ! $this->jp4wc_framework->get_post( 'createaccount' ) ) {
			wc_add_notice( __( 'Sorry, you need to create an account in order for us to save your payment information.', 'woocommerce-for-paygent-payment-main' ), $notice_type = 'error' );
			return false;
		}
		// Edit Expire Data.
		$card_token     = $this->jp4wc_framework->get_post( 'paygent_mccc-token' );
		$card_cvc_token = $this->jp4wc_framework->get_post( 'paygent_mccc-cvc_token' );

		if ( $this->jp4wc_framework->get_post( 'paygent-use-stored-payment-info' ) == 'no' || $this->jp4wc_framework->get_post( 'paygent-use-stored-payment-info' ) == null ) :
			if ( strpos( $card_token, 'tok_' ) === false ) {
				wc_add_notice( __( 'Input information of the credit card is not enough. Please check Credit card expiration date, etc.', 'woocommerce-for-paygent-payment-main' ), $notice_type = 'error' );
				return false;
			}
			if ( strpos( $card_cvc_token, 'tok_' ) === false ) {
				wc_add_notice( __( 'Input information of the credit card is not enough. Please check CVC.', 'woocommerce-for-paygent-payment-main' ), $notice_type = 'error' );
				return false;
			}
		endif;

		return true;
	}

	/**
	 * Process a refund if supported
	 *
	 * @param  int    $order_id Order ID.
	 * @param  float  $amount Amount to refund.
	 * @param  string $reason Reason for refund.
	 * @return  boolean True or false based on success, or a WP_Error object
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$telegram_array   = array(
			'auth_cancel' => '181',
			'sale_cancel' => '183',
			'auth_change' => '184',
			'sale_change' => '185',
		);
		$permit_statuses  = array(
			0 => array(
				'auth_cancel' => array( 20 ),
				'sale_cancel' => array( 40 ),
				'auth_change' => array( 20 ),
				'sale_change' => array( 40 ),
			),
		);
		$send_data_refund = array(
			'payment_amount' => $amount,
			'reduction_flag' => 1,
		);
		return $this->paygent_request->paygent_process_refund( $order_id, $amount, $telegram_array, $permit_statuses, $send_data_refund, $this );
	}

	/**
	 * Output for the order received page.
	 *
	 * @param int $order Order ID.
	 */
	public function receipt_page( $order ) {
		echo '<p>' . esc_html__( 'Thank you for your order.', 'woocommerce-for-paygent-payment-main' ) . '</p>';
	}

	/**
	 * Include jQuery and our scripts
	 */
	public function add_paygent_mccc_token_scripts() {
		if ( '1' === $this->test_mode ) {
			$paygent_token_js_link = '//sandbox.paygent.co.jp/js/PaygentToken.js';
		} else {
			$paygent_token_js_link = '//token.paygent.co.jp/js/PaygentToken.js';
		}
		if ( is_checkout() ) {
			wp_enqueue_script(
				'paygent-token',
				$paygent_token_js_link,
				array(),
				WC_PAYGENT_VERSION,
				false
			);
		}
	}

	/**
	 * Read Paygent Token javascript
	 *
	 * @param  string $html HTML to append the token input fields.
	 */
	public function paygent_mccc_token_order_button_html( $html ) {
		$currency = get_woocommerce_currency();
		if ( 'JPY' !== $currency ) {
			$html .= '
            <input type="hidden" name="paygent_mccc-token" id="paygent_mccc-token" value="" />
            <input type="hidden" name="paygent_mccc-valid_until" id="paygent_mccc-valid_until" value="" />
            <input type="hidden" name="paygent_mccc-masked_card_number" id="paygent_mccc-masked_card_number" value="" />
            <input type="hidden" name="paygent_mccc-cvc_token" id="paygent_mccc-cvc_token" value="" />
            <input type="hidden" name="card_type" id="card_type" value="" />';
		}
		return $html;
	}

	/**
	 * Read Paygent Token javascript
	 *
	 * @param array $delete_card_data Delete card data.
	 * @return array
	 */
	public function delete_mccc_card( $delete_card_data ) {
		$telegram_kind = '026';
		$order         = null;

		// Check and Set site id.
		$site_id = get_option( 'wc-paygent-sid' );
		if ( '1' !== $site_id ) {
			$delete_card_data['site_id'] = $site_id;
		}
		$delete_card_data['trading_id'] = '';

		$delete_card_res = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $delete_card_data, $this->debug );
		return $delete_card_res;
	}

	/**
	 * Update Sale from Auth to Paygent System.
	 *
	 * @param $order_id Order ID.
	 */
	public function order_paygent_mccc_status_completed( $order_id ) {
		// Sales.
		$telegram_kind = '182';
		$this->paygent_request->order_paygent_status_completed( $order_id, $telegram_kind, $this );
	}
}

/**
 * Add the gateway to woocommerce
 *
 * @param array $methods Existing payment methods.
 * @return array
 */
function add_wc_paygent_mccc_gateway( $methods ) {
	$methods[] = 'WC_Gateway_Paygent_MCCC';
	return $methods;
}
add_filter( 'woocommerce_payment_gateways', 'add_wc_paygent_mccc_gateway' );

/**
 * Edit the available gateway to woocommerce
 *
 * @param array $methods Existing payment methods.
 * @return array
 */
function edit_available_gateways_mccc( $methods ) {

	$currency = get_woocommerce_currency();

	if ( 'JPY' === $currency ) {
		unset( $methods['paygent_mccc'] );
	}
	return $methods;
}
add_filter( 'woocommerce_available_payment_gateways', 'edit_available_gateways_mccc', 9 );

/**
 * Delete token from my account page to Paygent admin.
 */
add_action( 'woocommerce_payment_token_deleted', 'paygent_mccc_delete_token', 20, 2 );
/**
 * Delete token data at my account page link to Paygent data.
 *
 * @param int    $token_id Token ID.
 * @param object $token Token object.
 * @return mixed
 */
function paygent_mccc_delete_token( $token_id, $token ) {
	$paygent = new WC_Gateway_Paygent_MCCC();
	if ( $token->get_gateway_id() === $paygent->id ) {
		$delete_card_data                     = array();
		$delete_card_data['customer_id']      = 'wc' . $token->get_user_id();
		$tokens                               = new WC_Payment_Token_Data_Store();
		$token_meta                           = $tokens->get_metadata( $token_id );
		$delete_card_data['customer_card_id'] = $token_meta['customer_card_id'][0];
		$delete_card_res                      = $paygent->delete_mccc_card( $delete_card_data );
		if ( isset( $delete_card_res['ErrCode'] ) ) {
			wc_add_notice( __( 'Failed to delete the token payment method at paygent.', 'woocommerce-for-paygent-payment-main' ) . "\n" . $delete_card_res['ErrInfo'][0] . ' : ' . $delete_card_res['ErrMessage'][0], 'error' );
			return false;
		}
	}
}
