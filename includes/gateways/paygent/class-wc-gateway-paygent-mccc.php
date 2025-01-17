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
		$this->order_button_text = sprintf( __( 'Proceed to %s', 'woocommerce-for-paygent-payment-main' ), __( 'Multi-currency Credit Card', 'woocommerce-for-paygent-payment-main' ) );
		$this->method_title      = __( 'Paygent Multi-currency Credit Card', 'woocommerce-for-paygent-payment-main' );

		// Create plugin fields and settings
		$this->init_form_fields();
		$this->init_settings();
		$this->method_title       = __( 'Paygent Multi-currency Credit Card Payment Gateway', 'woocommerce-for-paygent-payment-main' );
		$this->method_description = __( 'Allows payments by Paygent Multi-currency Credit Card in Japan.', 'woocommerce-for-paygent-payment-main' );
		$this->supports           = array(
			'products',
			'refunds',
			'default_credit_card_form',
		);

		// Get setting values
		foreach ( $this->settings as $key => $val ) {
			$this->$key = $val;
		}

		// Set JP4WC framework
		$this->jp4wc_framework = new Framework\JP4WC_Framework();

		include_once 'includes/class-wc-gateway-paygent-request.php';
		$this->paygent_request = new WC_Gateway_Paygent_Request();

		// Set Test mode.
		$this->test_mode = get_option( 'wc-paygent-testmode' );

		// Load plugin checkout icon
		$this->icon = plugins_url( 'images/paygent-cards.png', __FILE__ );
		// When no save setting error at chackout page
		if ( is_null( $this->title ) ) {
			$this->title = __( 'Please set this payment at Control Panel! ', 'woocommerce-for-paygent-payment-main' ) . $this->method_title;
		}

		// Actions
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
	function init_form_fields() {

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
				'default'     => sprintf( __( 'Proceed to %s', 'woocommerce-for-paygent-payment-main' ), __( 'Multi-currency Credit Card', 'woocommerce-for-paygent-payment-main' ) ),
			),
			'store_card_info'   => array(
				'title'       => __( 'Store Card Infomation', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable Store Card Infomation', 'woocommerce-for-paygent-payment-main' ),
				'default'     => 'no',
				'description' => sprintf( __( 'Store user Credit Card information in Paygent Server.(Option)', 'woocommerce-for-paygent-payment-main' ) ),
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
	function payment_fields() {
		// Description of payment method from settings
		if ( $this->description ) { ?>
		<p><?php echo $this->description; ?></p>
			<?php
		}

		// Check the tokens.
		$user_id = get_current_user_id();
		$tokens  = WC_Payment_Tokens::get_customer_tokens( $user_id, $this->id );
		if ( $this->store_card_info == 'yes' ) {
			?>
		<fieldset  style="padding-left: 40px;">
			<?php
			if ( $this->store_card_info == 'yes' ) {
				$paygent_cc = new WC_Gateway_Paygent_CC();
				$paygent_cc->display_stored_user_data( $tokens );
			}
			?>
		</fieldset>
			<?php
		}

		if ( $tokens ) {
			?>
			<div id="paygent-new-info" style="display:none">
			<?php
		} else {
			?>
			<!-- Show input boxes for new data -->
			<div id="paygent-new-info">
		<?php } ?>
		<?php
		if ( version_compare( WOOCOMMERCE_VERSION, '2.6.0', '<' ) ) {
			$this->credit_card_form( array( 'fields_have_names' => true ) );
		} else {
			$payment_gateway_cc     = new WC_Payment_Gateway_CC();
			$payment_gateway_cc->id = $this->id;
			$payment_gateway_cc->form();
		}
		echo '</div>';
		if ( $this->test_mode == '1' ) {
			$merchant_id = get_option( 'wc-paygent-test-mid' );
			$token_key   = get_option( 'wc-paygent-test-tokenkey' );
		} else {
			$merchant_id = get_option( 'wc-paygent-mid' );
			$token_key   = get_option( 'wc-paygent-tokenkey' );
		}
		?>
<script type="text/javascript">
		<?php if ( is_user_logged_in() && $this->store_card_info == 'yes' && ! empty( $tokens ) ) { ?>
document.getElementById('paygent_mccc-stored-card-cvc').addEventListener('input', sendPaygentToken);
		<?php } ?>
document.getElementById('paygent_mccc-card-cvc').addEventListener('input', sendPaygentToken);
document.getElementById('paygent_mccc-card-number').addEventListener('input', sendPaygentToken);
document.getElementById('paygent_mccc-card-expiry').addEventListener('input', sendPaygentToken);
// Definition of the send function. Processing when pressing send button of card information entry form.
function sendPaygentToken() {
	var paygent_card_number = document.getElementById('paygent_mccc-card-number').value;
	paygent_card_number = paygent_card_number.replace(/ /g, '');
	var paygent_cvc = document.getElementById('paygent_mccc-card-cvc').value;
		<?php if ( is_user_logged_in() && $this->store_card_info == 'yes' && ! empty( $tokens ) ) { ?>
	var paygent_stored_cvc = document.getElementById('paygent_mccc-stored-card-cvc').value;
	if(paygent_cvc.length < 3 && paygent_stored_cvc.length < 3){
		return false;
	}else if(paygent_stored_cvc.length == 0){
		paygent_stored_cvc = paygent_cvc;
	}
		<?php } else { ?>
	var paygent_stored_cvc = paygent_cvc;
		<?php } ?>
	var exp_my = document.getElementById('paygent_mccc-card-expiry').value ;
	exp_my = exp_my.replace(/ /g, '');
	exp_my = exp_my.replace('/', '');
	var paygent_exp_m = exp_my.substr(0,2);
	var paygent_exp_y = exp_my.substr(2,2);
	var paygentToken = new PaygentToken(); //Generate PaygentToken objects
// Execute the token generation method.
	paygentToken.createCvcToken(
		'<?php echo $merchant_id; ?>', //First argument: Merchant ID
		'<?php echo $token_key; ?>', //Second argument: Token generation key
		{ //Third argument: Credit card information
			cvc:paygent_stored_cvc, //cvc
		},execCVCToken //Fourth argument: Callback function (executed after token acquisition)
	);
// Execute the token generation method.
	paygentToken.createToken(
		'<?php echo $merchant_id; ?>', //First argument: Merchant ID
		'<?php echo $token_key; ?>', //Second argument: Token generation key
		{ //Third argument: Credit card information
			card_number:paygent_card_number, //Credit card number
			expire_year:paygent_exp_y, //Expiration date -YY
			expire_month:paygent_exp_m, //Expiration date -MM
			cvc:paygent_cvc, //cvc
		},execPurchase //Fourth argument: Callback function (executed after token acquisition)
	);
}
// Definition of callback function. Processing after token acquisition
function execPurchase(response) {
//	var form = document.forms.checkout;
	if (response.result == '0000') { //When the result of the token processing is normal.
// Delete input information from card information entry form. (Do not send input card information.)
		var paygent_card_number = document.getElementById('paygent_mccc-card-number').value;
		paygent_card_number = paygent_card_number.replace(/ /g, '');
		var token = document.getElementById('paygent_mccc-token').value;
		var place_order = jQuery('#place_order');
		document.getElementById('paygent_mccc-card-number').removeAttribute('name');
		document.getElementById('paygent_mccc-card-expiry').removeAttribute('name');
		document.getElementById('paygent_mccc-card-cvc').removeAttribute('name');
		//form.name.removeAttribute(\'name\');
		// Set token that was responded from createToken () to the hidden item token which we made in advance.
		document.getElementById('paygent_mccc-token').value = response.tokenizedCardObject.token;
		document.getElementById('paygent_mccc-masked_card_number').value = response.tokenizedCardObject.masked_card_number;
		document.getElementById('paygent_mccc-valid_until').value = response.tokenizedCardObject.valid_until;
		if(token != ''){
			jQuery('#place_order').prop('disabled', false);
		}
		var card_check1 = paygent_card_number.slice(0,1);
		var card_check2 = paygent_card_number.slice(0,2);
		if(card_check1 == 4){
			document.getElementById("card_type").value = "visa";
		}else if(card_check2 == 51 || card_check2 == 52 || card_check2 == 53 || card_check2 == 54 || card_check2 == 55 || card_check2 == 22 || card_check2 == 23 || card_check2 == 24 || card_check2 == 25 || card_check2 == 26 || card_check2 == 27 || card_check2 == 21 || card_check2 == 59){//add 21 and 59 for test card
			document.getElementById("card_type").value = "mastercard";
		}else if(card_check2 == 30 || card_check2 == 36 || card_check2 == 38 || card_check2 == 39){
			document.getElementById("card_type").value = "diners";
		}else if(card_check2 == 60 || card_check2 == 64 || card_check2 == 65 || card_check2 == 62){
			document.getElementById("card_type").value = "discover";
		}else if(card_check2 == 34 || card_check2 == 37){
			document.getElementById("card_type").value = "american express";
		}else if(card_check2 == 35 || card_check2 == 31 ){//add 31 for test card
			document.getElementById("card_type").value = "jcb";
		}
	}
}
// Definition of callback function. Processing after token acquisition
function execCVCToken(response) {
	if (response.result == '0000') { //When the result of the token processing is normal.
		var token = document.getElementById('paygent_mccc-cvc_token').value;
// Set token that was responded from createToken () to the hidden item token which we made in advance.
		document.getElementById('paygent_mccc-cvc_token').value = response.tokenizedCardObject.token;
		if(token != ''){
			jQuery('#place_order').prop('disabled', false);
		}
	}
}

jQuery(function(){
	jQuery('#place_order').focus(function (){
		jQuery('#paygent_mccc-card-number').prop('disabled', true);
		jQuery('#paygent_mccc-card-expiry').prop('disabled', true);
		jQuery('#paygent_mccc-card-cvc').prop('disabled', true);
	});
	jQuery('#place_order').blur(function (){
		jQuery('#paygent_mccc-card-number').prop('disabled', false);
		jQuery('#paygent_mccc-card-expiry').prop('disabled', false);
		jQuery('#paygent_mccc-card-cvc').prop('disabled', false);
	});
	jQuery("input[name='paygent-use-stored-payment-info']:radio").change( function(){
		jQuery('#paygent_mccc-card-number').val('');
		jQuery('#paygent_mccc-card-expiry').val('');
		jQuery('#paygent_mccc-card-cvc').val('');
		jQuery('#paygent_mccc-stored-card-cvc').val('');
		jQuery('#paygent_mccc-token').val('');
	});
});
</script>
		<?php
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id
	 * @throws
	 * @return mixed
	 */
	function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		$user  = wp_get_current_user();
		if ( 0 != $user->ID ) {
			$customer_id = $user->ID;
		} else {
			$customer_id = $order_id . '-user';
		}
		$send_data = array();

		// Common header
		$telegram_kind = '180';// Multi-currency Card Payment Auth
		$prefix_order  = get_option( 'wc-paygent-prefix_order' );
		if ( $prefix_order ) {
			$send_data['trading_id'] = $prefix_order . $order_id;
		} else {
			$send_data['trading_id'] = 'wc_' . $order_id;
		}
		$send_data['payment_id']        = null;
		$send_data['security_code_use'] = 1;

		$send_data['payment_amount'] = $order->get_total();

		// get Token Data
		$card_token     = $this->get_post( 'paygent_cc-token' );
		$card_cvc_token = $this->get_post( 'paygent_cc-cvc_token' );

		// Get Currency infomation
		$currency                   = get_woocommerce_currency();
		$send_data['currency_code'] = $currency;

		// Create server request using stored or new payment details
		if ( 0 != $user->ID ) {
			$card_user_id = 'wc' . $user->ID;
		} else {
			$card_user_id = 'wc' . $customer_id;
		}

		// Card information deposit function
		if ( is_user_logged_in() && ( $this->store_card_info == 'yes' ) ) {
			$send_data['security_code_token'] = 1;
			$send_data['card_token']          = $card_cvc_token;
			$send_data['stock_card_mode']     = 1;
			$send_data['customer_id']         = $card_user_id;
			if ( $this->get_post( 'paygent-use-stored-payment-info' ) == 'yes' ) {
				$send_data['customer_card_id'] = $this->get_post( 'stored-info' );
			} else {
				$stored_user_card_data         = $this->add_stored_user_data( $card_user_id, $card_token, $order );
				$send_data['customer_card_id'] = $stored_user_card_data['result_array'][0]['customer_card_id'];
			}
		} else {
			// Credit Card Token Information
			$send_data['security_code_token'] = 1;
			$send_data['card_token']          = $card_cvc_token;
			$send_data['card_token']          = $card_token;
		}

		// Payment times
		$send_data['payment_class'] = 10;// One time payment

		// 3D Secure Setting
		$send_data['term_url']        = $this->get_return_url( $order );
		$send_data['http_accept']     = $_SERVER['HTTP_ACCEPT'];
		$send_data['http_user_agent'] = $_SERVER['HTTP_USER_AGENT'];

		$response = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );

		// Check response
		if ( isset( $response['result'] ) and $response['result'] == 0 and $response['result_array'] ) {
			// Success
			$order->add_order_note( __( 'paygent Payment completed. Transaction ID: ', 'woocommerce-for-paygent-payment-main' ) . $response['result_array'][0]['payment_id'] );
			$order->add_meta_data( '_paygent_order_id', $send_data['trading_id'], true );
			// set transaction id for Paygent Order Number
			$order->payment_complete( wc_clean( $response['result_array'][0]['payment_id'] ) );

			if ( isset( $this->paymentaction ) and $this->paymentaction == 'sale' ) {
				$telegram_kind = '182';
				$response_sale = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );
				if ( $response_sale['result'] != 0 ) {
					$this->paygent_request->error_response( $response_sale, $order );
				}
			}
			// Return thank you redirect
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);

		} elseif ( $response['result'] == 7 ) {// 3D Secure
			$order->add_order_note( __( 'Success accept to 3D Secure.', 'woocommerce-for-paygent-payment-main' ) . $response['result_array'][0]['attempt_kbn'] );
			$order->add_meta_data( '_paygent_order_id', $send_data['trading_id'], true );
			// Mark as on-hold (we're awaiting the payment)
			$order->update_status( 'on-hold', __( '3DSecure Payment Processing.', 'woocommerce-for-paygent-payment-main' ) );

			$htmls   = explode( "\n", $response['result_array'][0]['out_acs_html'] );
			$action  = substr( $htmls[11], 34, -17 );
			$pareq   = substr( $htmls[13], 56, -13 );
			$termurl = substr( $htmls[14], 58, -13 );
			$md      = substr( $htmls[15], 53, -13 );

			$tds_url = $this->get_return_url( $order ) . '&action=' . urlencode( $action ) . '&pareq=' . $pareq . '&termurl=' . urlencode( $termurl ) . '&md=' . $md;

			// Return thankyou redirect
			return array(
				'result'   => 'success',
				'redirect' => $tds_url,
			);
		} else { // System Error
			$this->paygent_request->error_response( $response, $order );
		}
	}

	/**
	 * Redirect Checkout page.
	 *
	 * @param int $order_id
	 */
	function redirect_code( $order_id ) {
		$order          = new WC_Order( $order_id );
		$payment_method = version_compare( WC_VERSION, '2.7', '<' ) ? get_post_meta( $order_id, '_payment_method', true ) : $order->get_payment_method();
		if ( isset( $_GET['action'] ) and isset( $_GET['pareq'] ) and isset( $_GET['termurl'] ) and $payment_method == $this->id ) {
			$url     = $_GET['action'];
			$pareq   = $_GET['pareq'];
			$termurl = $_GET['termurl'];
			$md      = $_GET['md'];
			echo '   <form name="TdsStart" action="' . $url . '" method="POST">
      <br>
      <br>
      <div style="text-align: center;">
        <h2>
          We will continue to make payments with 3D Secure.<br>
          Click the button.
        </h2>
        <input type="submit" value="OK">
      </div>
      <input type="hidden" name="PaReq" value="' . $pareq . '">
      <input type="hidden" name="TermUrl" value="' . $termurl . '">
      <input type="hidden" name="MD" value="' . $md . '">
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

		if ( isset( $_GET['trading_id'] ) and $payment_method == $this->id and $order_id == $base_order_id ) {
			// set transaction id for Paygent Order Number
			$order->set_transaction_id( wc_clean( $_GET['payment_id'] ) );
			// Mark as processing (payment complete)
			$order->update_status( 'processing', __( '3D Secure payment was complete.', 'woocommerce-for-paygent-payment-main' ) );
			// Reduce stock levels
			wc_reduce_stock_levels( $order_id );

			// Sale payment action
			if ( isset( $this->paymentaction ) and $this->paymentaction == 'sale' ) {
				$telegram_kind           = '182';
				$send_data['trading_id'] = $_GET['trading_id'];
				if ( $this->paygent_request->site_id != 1 ) {
					$send_data['site_id'] = $this->paygent_request->site_id;
				} else {
					$send_data['site_id'] = 1;
				}
				$response_sale = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );
				if ( $response_sale['result'] != 0 ) {
					$this->paygent_request->error_response( $response_sale, $order );
				}
			}
			return;
		} elseif ( isset( $_GET['result'] ) and $order->get_payment_method() == $this->id and $_GET['result'] == 1 ) {
			// set transaction id for Paygent Order Number
			$order->set_transaction_id( wc_clean( $_GET['payment_id'] ) );
			// Mark as failed (payment failed)
			$order->update_status( 'failed', __( 'Error at 3D Secure.', 'woocommerce-for-paygent-payment-main' ) . $_GET['response_code'] . ':' . urldecode( $_GET['response_detail'] ) );
		}
	}

	/**
	 * Process a payment for an ongoing subscription.
	 */
	function process_scheduled_subscription_payment( $amount_to_charge, $order, $product_id ) {
	}

	/**
	 * Check if the user has any billing records in the Customer Vault
	 */
	function user_has_stored_data( $user_id ) {
		include_once 'includes/class-wc-gateway-paygent-request.php';
		$telegram_kind = '027';
		$send_data     = array(
			'trading_id'  => '',
			'customer_id' => $user_id,
		);
		$site_id       = get_option( 'wc-paygent-sid' );
		if ( $site_id != 1 ) {
			$send_data['site_id'] = $site_id;
		}
		$order = wc_get_order();

		// Check test mode
		$test_mode = get_option( 'wc-paygent-testmode' );

		$paygent_request = new WC_Gateway_Paygent_Request( $this );

		$result = $paygent_request->send_paygent_request( $test_mode, $order, $telegram_kind, $send_data );
		return $result;
	}

	/**
	 * Display payment method in Payment page when user have stored card data
	 *
	 * @param  array $tokens
	 */
	function display_stored_user_data( $tokens ) {
		foreach ( $tokens as $key => $value ) {
			foreach ( $value->get_meta_data() as $data_key => $data_value ) {
				$main_key = 'key';
				foreach ( $data_value->get_data() as $meta_key => $meta_value ) {
					if ( $meta_key == 'key' ) {
						$main_key = $meta_value;
					} elseif ( $meta_key == 'value' ) {
						$paygent_tokens[ $key ][ $main_key ] = $meta_value;
					}
				}
			}
		}
		if ( $tokens ) {
			?>
		<fieldset>
		<input type="radio" name="paygent-use-stored-payment-info" id="paygent-use-stored-payment-info-yes" value="yes" checked="checked" onclick="document.getElementById('paygent-new-info').style.display='none'; document.getElementById('paygent-stored-info').style.display='block'"; />
		<label for="paygent-use-stored-payment-info-yes" style="display: inline;"><?php _e( 'Use stored credit card information.', 'woocommerce-for-paygent-payment-main' ); ?></label>
		<div id="paygent-stored-info" style="padding: 10px 0 0 40px; clear: both;">
		<select name="stored-info" id="stored-info">
			<?php foreach ( $paygent_tokens as $key => $value ) { ?>
				<option class="<?php echo $value['card_type']; ?>" value="<?php echo $value['customer_card_id']; ?>"><?php _e( 'credit card last some numbers: ', 'woocommerce-for-paygent-payment-main' ); ?><?php echo $value['last4']; ?> (<?php echo $value['expiry_month'] . '/' . substr( $value['expiry_year'], -2 ); ?>)</option>
			<?php } ?>
		</select>
		<p class="form-row form-row-first woocommerce-validated">
			<label for="paygent_mccc-stored-card-cvc"><?php echo esc_html__( 'Card code', 'woocommerce' ); ?><span class="required">*</span></label>
			<input id="paygent_mccc-stored-card-cvc" class="input-text wc-credit-card-form-card-cvc" inputmode="numeric" autocomplete="off" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" maxlength="4" placeholder="CVC" name="paygent_cc-stored-card-cvc" style="width:100px">
		</p>
		</fieldset>
		<fieldset>
		<input type="radio" name="paygent-use-stored-payment-info" id="paygent-use-stored-payment-info-no" value="no" onclick="document.getElementById('paygent-stored-info').style.display='none'; document.getElementById('paygent-new-info').style.display='block'"; />
		<label for="paygent-use-stored-payment-info-no"  style="display: inline;"><?php _e( 'Use a new payment method', 'woocommerce-for-paygent-payment-main' ); ?></label>
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
	 * @param string          $user_id
	 * @param string          $card_token
	 * @param object WP_Order $order
	 * @return mixed
	 */
	function add_stored_user_data( $user_id, $card_token, $order ) {
		$telegram_kind = '025';
		$send_data     = array(
			'trading_id'      => '',
			'customer_id'     => $user_id,
			'valid_check_flg' => '1',
		);

		// Check and Set site id.
		$site_id = get_option( 'wc-paygent-sid' );
		if ( $site_id != 1 ) {
			$send_data['site_id'] = $site_id;
		}

		if ( isset( $card_token ) ) {
			$send_data['card_token'] = $card_token;
		} else {
			wc_add_notice( __( 'Input information of the credit card is not enough.', 'woocommerce-for-paygent-payment-main' ), $notice_type = 'error' );
			return false;
		}
		$result = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );
		if ( $result['result'] == 1 ) {
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
			// Set and save token to WooCommerce
			$token = new WC_Payment_Token_CC();
			$token->set_token( $card_token );
			$token->set_gateway_id( $this->id );
			$token->set_last4( $card_last4 );
			$token->set_card_type( $this->get_post( 'card_type' ) );
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
	function validate_fields() {
		// Check for saving payment info without having or creating an account
		if ( $this->get_post( 'saveinfo' ) && ! is_user_logged_in() && ! $this->get_post( 'createaccount' ) ) {
			wc_add_notice( __( 'Sorry, you need to create an account in order for us to save your payment information.', 'woocommerce-for-paygent-payment-main' ), $notice_type = 'error' );
			return false;
		}
		// Edit Expire Data
		$card_token     = $this->get_post( 'paygent_mccc-token' );
		$card_cvc_token = $this->get_post( 'paygent_mccc-cvc_token' );

		if ( $this->get_post( 'paygent-use-stored-payment-info' ) == 'no' || $this->get_post( 'paygent-use-stored-payment-info' ) == null ) :
			if ( strpos( $card_token, 'tok_' ) === false ) {
				wc_add_notice( __( 'Input information of the credit card is not enough. Please check Credit card expiration date, etc.', 'woocommerce-for-paygent-payment-main' ), $notice_type = 'error' );
				return false;
			} elseif ( $this->get_post( 'paygent-use-stored-payment-info' ) == 'yes' ) {
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
	 * @param  int    $order_id
	 * @param  float  $amount
	 * @param  string $reason
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
		/*
				$order = wc_get_order( $order_id );
		$telegram_kind_check = '094';
		$transaction_id = $order->get_transaction_id();
		$send_data_check = array('payment_id' => $transaction_id,);

		$order_result = $this->paygent_request->send_paygent_request($this->test_mode, $order, $telegram_kind_check, $send_data_check, $this->debug);
		$order_total = $order->get_total();
		if($amount == $order_total ){
			if($order_result['result_array'][0]['payment_status']==20){
				$telegram_kind_del = '181';//Authority Cancel
			}elseif($order_result['result_array'][0]['payment_status']==30){
				$telegram_kind_del = '183';//Sales Cancel
			}
			$del_result = $this->paygent_request->send_paygent_request($this->test_mode, $order, $telegram_kind_del, $send_data_check, $this->debug);
			if($del_result['result']== 1){
				$order->add_order_note( __( 'Failed Refund. ', 'woocommerce-for-paygent-payment-main' ).__( 'Error Code :', 'woocommerce-for-paygent-payment-main' ).$del_result['responseCode'].__( ' Error message :', 'woocommerce-for-paygent-payment-main' ).$del_result['responseDetail']);
				return false;
			}elseif($del_result['result'] == 0){
				$order->update_status('cancelled');
				$order->update_status('refunded');
				return true;
			}else{
				$order->add_order_note( __( 'Failed Refund.', 'woocommerce-for-paygent-payment-main' ));
				return false;
			}
		}elseif($amount < $order_total){
			if($order_result['result_array'][0]['payment_status']==20){
				$telegram_kind_refund = '184';//Authory Change
			}elseif($order_result['result_array'][0]['payment_status']==30){
				$telegram_kind_refund = '185';//Sales Change
			}
			$send_data_refund = array(
				'payment_id' => $transaction_id,
				'payment_amount' => $transaction_id,
				'reduction_flag' => 1,
			);
			$refund_result = $this->paygent_request->send_paygent_request($this->test_mode, $order, $telegram_kind_refund, $send_data_refund, $this->debug);
			if($refund_result['result']== 1){
				$order->add_order_note( __( 'Failed Refund. ', 'woocommerce-for-paygent-payment-main' ).__( 'Error Code :', 'woocommerce-for-paygent-payment-main' ).$del_result['responseCode'].__( ' Error message :', 'woocommerce-for-paygent-payment-main' ).$del_result['responseDetail']);
				return false;
			}elseif($refund_result['result'] == 0){
				$order->add_order_note( __( 'Partial Refunded.', 'woocommerce-for-paygent-payment-main' ));
				return true;
			}else{
				$order->add_order_note( __( 'Failed Refund.', 'woocommerce-for-paygent-payment-main' ));
				return false;
			}
		}
		return false;*/
	}

	function receipt_page( $order ) {
		echo '<p>' . __( 'Thank you for your order.', 'woocommerce-for-paygent-payment-main' ) . '</p>';
	}

	/**
	 * Include jQuery and our scripts
	 */
	function add_paygent_mccc_token_scripts() {
		if ( $this->test_mode == '1' ) {
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
	 * Get post data if set
	 */
	private function get_post( $name ) {
		if ( isset( $_POST[ $name ] ) ) {
			return wc_clean( $_POST[ $name ] );
		}
		return null;
	}

	/**
	 * Read Paygent Token javascript
	 *
	 * @param  string $html
	 */
	public function paygent_mccc_token_order_button_html( $html ) {
		$currency = get_woocommerce_currency();
		if ( $currency != 'JPY' ) {
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
	 * @param array $delete_card_data
	 */
	public function delete_mccc_card( $delete_card_data ) {
		$telegram_kind = '026';
		$order         = null;

		// Check and Set site id.
		$site_id = get_option( 'wc-paygent-sid' );
		if ( $site_id != 1 ) {
			$delete_card_data['site_id'] = $site_id;
		}
		$delete_card_data['trading_id'] = '';

		$delete_card_res = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $delete_card_data, $this->debug );
		return $delete_card_res;
	}

	/**
	 * Update Sale from Auth to Paygent System
	 *
	 * @param $order_id
	 */
	public function order_paygent_mccc_status_completed( $order_id ) {
		// Sales
		$telegram_kind = '182';
		$this->paygent_request->order_paygent_status_completed( $order_id, $telegram_kind, $this );
	}
}

/**
 * Add the gateway to woocommerce
 */
function add_wc_paygent_mccc_gateway( $methods ) {
	$methods[] = 'WC_Gateway_Paygent_MCCC';
	return $methods;
}
add_filter( 'woocommerce_payment_gateways', 'add_wc_paygent_mccc_gateway' );

/**
 * Edit the available gateway to woocommerce
 */
function edit_available_gateways_mccc( $methods ) {

	$currency = get_woocommerce_currency();

	if ( $currency == 'JPY' ) {
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
 * @param int    $token_id
 * @param object $token
 * @return mixed
 */
function paygent_mccc_delete_token( $token_id, $token ) {
	$paygent = new WC_Gateway_Paygent_MCCC();
	if ( $token->get_gateway_id() == $paygent->id ) {
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
