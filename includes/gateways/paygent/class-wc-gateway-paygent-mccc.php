<?php
/**
 * WooCommerce Paygent Multi-currency Credit Card Gateway
 *
 * Provides a Paygent Multi-currency Credit Card Payment Gateway integration for WooCommerce.
 *
 * @version 2.4.6
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
	 * 3D Secure 2.0 check setting
	 *
	 * @var string
	 */
	public $tds2_check;

	/**
	 * Paygent CC instance
	 *
	 * @var WC_Gateway_Paygent_CC
	 */
	public $paygent_cc;

	/**
	 * Attempt notice email address
	 *
	 * @var string
	 */
	public $attempt_notice_email;

	/**
	 * Attempt classification setting
	 *
	 * @var string
	 */
	public $attempt;

	/**
	 * Merchant name for payment
	 *
	 * @var string
	 */
	public $merchant_name;

	/**
	 * Payment action setting
	 *
	 * @var string
	 */
	public $paymentaction;

	/**
	 * 3D Secure 2.0 hash key
	 *
	 * @var string
	 */
	public $tds2_hashkey;

	/**
	 * Store card information setting
	 *
	 * @var string
	 */
	public $store_card_info;

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

		$this->paygent_cc = new WC_Gateway_Paygent_CC();
		// Set Test mode.
		$this->test_mode = get_option( 'wc-paygent-testmode' );

		// Load plugin checkout icon.
		$this->icon = plugins_url( 'images/paygent-cards.png', __FILE__ );
		// When no save setting error at chackout page.
		if ( is_null( $this->title ) ) {
			$this->title = __( 'Please set this payment at Control Panel! ', 'woocommerce-for-paygent-payment-main' ) . $this->method_title;
		}

		// Actions.
		add_action( 'woocommerce_update_options_payment_gateways', array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		if ( 'yes' === $this->enabled ) {
			add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'tds_status_change' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'add_paygent_mccc_token_scripts' ) );
			add_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'woocommerce_thankyou_order_received_td' ), 10, 2 );
		}
		// 3D secure 2.0.
		if ( 'yes' === $this->tds2_check ) {
			add_action( 'password_reset', array( $this, 'jp4wc_password_update' ), 10 );
			add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'paygent_3ds2_redirect_order' ) );// Payment page.
			add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'tds2_status_change' ) );
		}

		add_action( 'woocommerce_payment_token_deleted', array( $this, 'paygent_mccc_delete_card' ), 10, 2 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'order_paygent_mccc_status_completed' ) );
	}

	/**
	 * Initialize Gateway Settings Form Fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'              => array(
				'title'       => __( 'Enable/Disable', 'woocommerce-for-paygent-payment-main' ),
				'label'       => __( 'Enable paygent Multi-currency Credit Card Payment', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
			'title'                => array(
				'title'       => __( 'Title', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-for-paygent-payment-main' ),
				'default'     => __( 'Multi-currency Credit Card', 'woocommerce-for-paygent-payment-main' ),
			),
			'description'          => array(
				'title'       => __( 'Description', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce-for-paygent-payment-main' ),
				'default'     => __( 'Pay with your credit card via Paygent.', 'woocommerce-for-paygent-payment-main' ),
			),
			'order_button_text'    => array(
				'title'       => __( 'Order Button', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the order button which the user sees during checkout.', 'woocommerce-for-paygent-payment-main' ),
				// translators: %s is the payment method name.
				'default'     => sprintf( __( 'Proceed to %s', 'woocommerce-for-paygent-payment-main' ), __( 'Multi-currency Credit Card', 'woocommerce-for-paygent-payment-main' ) ),
			),
			'store_card_info'      => array(
				'title'       => __( 'Store Card Infomation', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable Store Card Infomation', 'woocommerce-for-paygent-payment-main' ),
				'default'     => 'no',
				'description' => sprintf( __( 'Store user Credit Card information in Paygent Server.(Option)', 'woocommerce-for-paygent-payment-main' ) ),
			),
			'tds2_check'           => array(
				'title'       => __( '3D Secure 2.0', 'woocommerce-for-paygent-payment-main' ),
				'label'       => __( 'Enable 3D Secure 2.0', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'checkbox',
				'description' => __( '* Application is required. Please make sure your application is complete.', 'woocommerce-for-paygent-payment-main' ),
				'default'     => 'no',
			),
			'tds2_hashkey'         => array(
				'title'       => __( '3D Secure result acceptance hash key', 'woocommerce-for-paygent-payment-main' ),
				'label'       => __( '3D Secure result acceptance hash key', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'text',
				'description' => __( 'Please input 3D Secure result acceptance hash key, if you use 3D Secure 2.0.', 'woocommerce-for-paygent-payment-main' ),
			),
			'merchant_name'        => array(
				'title'       => __( 'Store name', 'woocommerce-for-paygent-payment-main' ),
				'label'       => __( 'Store name', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'text',
				'description' => __( 'Input Store name.', 'woocommerce-for-paygent-payment-main' ),
			),
			'paymentaction'        => array(
				'title'       => __( 'Payment Action', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'Choose whether you wish to capture funds immediately or authorize payment only.', 'woocommerce-for-paygent-payment-main' ),
				'default'     => 'sale',
				'desc_tip'    => true,
				'options'     => array(
					'sale'          => __( 'Capture', 'woocommerce-for-paygent-payment-main' ),
					'authorization' => __( 'Authorize', 'woocommerce-for-paygent-payment-main' ),
				),
			),
			'attempt'              => array(
				'title'       => __( 'Attempt classification compatible', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'checkbox',
				'label'       => __( 'If the Attempt category is "Attention", please check the checkbox if you wish to proceed with payment.', 'woocommerce-for-paygent-payment-main' ),
				'default'     => 'no',
				'description' => __( 'If you check this, the payment will go through, but the chargeback will be borne by the store.', 'woocommerce-for-paygent-payment-main' ),
			),
			'attempt_notice_email' => array(
				'title'       => __( 'Attempt notice email', 'woocommerce-for-paygent-payment-main' ),
				'label'       => __( 'Attempt notice email', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'text',
				'description' => __( 'Please input Attempt notice email, if you permit the attempt.', 'woocommerce-for-paygent-payment-main' ),
			),
			'debug'                => array(
				'title'       => __( 'Debug Mode', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable Debug Mode', 'woocommerce-for-paygent-payment-main' ),
				'default'     => 'no',
				'description' => __( 'Save debug data using WooCommerce logging.', 'woocommerce-for-paygent-payment-main' ),
			),
		);
	}

	/**
	 * UI - Payment page fields for Paygent Payment.
	 */
	public function payment_fields() {
		// Description of payment method from settings.
		if ( $this->description ) { ?>
		<p><?php echo wp_kses_post( $this->description ); ?></p>
				<?php
		}

		// Check the tokens.
		$user_id = get_current_user_id();
		$tokens  = false;
		if ( 'yes' === $this->store_card_info && is_checkout() ) {
			?>
			<?php
			$tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, $this->id );
			if ( 'yes' === $this->store_card_info ) {
				$this->paygent_cc->display_stored_user_data( $tokens, $this->id );
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
		if ( '1' === $this->test_mode ) {
			$merchant_id = get_option( 'wc-paygent-test-mid' );
			$token_key   = get_option( 'wc-paygent-test-tokenkey' );
		} else {
			$merchant_id = get_option( 'wc-paygent-mid' );
			$token_key   = get_option( 'wc-paygent-tokenkey' );
		}
		if ( 'yes' === $this->tds2_check ) {
			$this->paygent_cc->input_cardholder_name();
		}
		$this->paygent_cc->paygent_token_js( $merchant_id, $token_key, $tokens, $this->id );

		echo '</div>';
		if ( $this->payment_method ) {
			$payment_method = $this->payment_method;
		} else {
			$payment_method = null;
		}
		if ( null !== $payment_method && array( 0 => 10 ) !== $payment_method && is_checkout() ) {
			echo '<fieldset style="padding-left: 40px;">' . esc_html__( 'Payment method : ', 'woocommerce-for-paygent-payment-main' ) . '<select name="number_of_payments">';
			$installment_payment = false;
			$number_of_payments  = $this->number_of_payments;
			$payment_method_name = $this->payment_methods;
			foreach ( $this->payment_method as $key => $value ) {
				if ( '61' === $value ) {
					$installment_payment = true;
				} else {
					echo '<option value="' . esc_attr( $value . '9' ) . '">' . esc_html( $payment_method_name[ $value ] ) . '</option>';
				}
			}
			if ( $installment_payment ) {
				foreach ( $number_of_payments as $key => $value ) {
					echo '<option value="' . esc_html( $value ) . '">' . esc_html( $value ) . esc_html__( 'times', 'woocommerce-for-paygent-payment-main' ) . '</option>';
				}
			}
			echo '</select></fieldset>';
		}
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id Order ID.
	 * @return mixed
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		// Free of charge.
		if ( 0 === $order->get_total() ) {
			// Payment complete.
			$order->payment_complete();

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		$user = wp_get_current_user();

		$send_data = array();

		// Common header.
		$telegram_kind = '180';// Multi-currency Card Payment Auth.

		$send_data['payment_id']        = '';
		$send_data['security_code_use'] = 1;
		$send_data['3dsecure_ryaku']    = 1;

		$send_data['payment_amount'] = $order->get_total();

		// get Token Data.
		$card_token     = $this->jp4wc_framework->get_post( 'paygent_mccc-token' );
		$card_cvc_token = $this->jp4wc_framework->get_post( 'paygent_mccc-cvc_token' );
		$card_type      = $this->jp4wc_framework->get_post( 'card_type' );
		$order->add_meta_data( '_paygent_card_token', $card_token );
		$order->add_meta_data( '_paygent_card_cvc_token', $card_cvc_token );
		$order->add_meta_data( '_paygent_card_type', $card_type );
		$order->save_meta_data();

		// Create server request using stored or new payment details.
		if ( 0 !== $user->ID ) {
			$card_user_id = 'wc' . $user->ID;
		} else {
			$card_user_id = 'wc' . $order_id . '-user';
		}

		// Card information deposit function without EMV-3DS.
		$set_login = false;
		if ( is_user_logged_in() && 'yes' === $this->store_card_info ) {
			$set_login = true;
			if ( $this->jp4wc_framework->get_post( 'paygent-use-stored-payment-info' ) === 'yes' ) {
				$send_data['customer_card_id'] = $this->jp4wc_framework->get_post( 'stored-info' );
			} else {
				$stored_user_card_data         = $this->paygent_cc->add_stored_user_data( $card_user_id, $card_token, $this->test_mode, $this->debug, $order );
				$send_data['customer_card_id'] = $stored_user_card_data['result_array'][0]['customer_card_id'];
			}
			$order->add_meta_data( '_paygent_customer_card_id', $send_data['customer_card_id'] );
			$order->save_meta_data();
		}

		$prefix_order = get_option( 'wc-paygent-prefix_order' );
		if ( $prefix_order ) {
			$send_data['trading_id'] = $prefix_order . $order_id;
		} else {
			$send_data['trading_id'] = 'wc_' . $order_id;
		}

		$send_data = $this->paygent_cc->set_stored_card( $card_user_id, $card_token, $card_cvc_token, $send_data, $set_login );

		// Get Currency infomation.
		$currency                   = get_woocommerce_currency();
		$send_data['currency_code'] = $currency;
		$order->add_meta_data( '_paygent_currency_code', $currency );

		$send_data['term_url'] = $this->get_return_url( $order );

		// 3D Secure 2.0 Setting.
		if ( 'yes' === $this->tds2_check ) {
			$telegram_kind = '450';// 3D Secure 2.0 Challenge Flow.
			$send_data     = $this->paygent_cc->set_send_data_for_tds2( $send_data, $this->merchant_name, $order );
		}
		$response = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );

		// Payment times.
		$send_data['payment_class'] = 10;// One time payment.
		$order->add_meta_data( '_payment_class', $send_data['payment_class'] );

		// Check response.
		if ( '0' === $response['result'] && isset( $response['result'] ) && $response['result_array'] ) {
			$order->add_meta_data( '_paygent_order_id', $send_data['trading_id'], true );
			// Success.
			if ( isset( $response['result_array'][0]['3ds_auth_id'] ) ) {// 3D Secure 2.0.
				$order->add_meta_data( '_paygent_3ds_response', $response['result_array'][0] );
				$order->add_meta_data( '_3ds_auth_id', $response['result_array'][0]['3ds_auth_id'] );
				$order->add_meta_data( '_out_acs_html', $response['result_array'][0]['out_acs_html'] );
				$order->save_meta_data();
				$order->add_order_note( __( '3D Secure 2.0 Payment Processing.', 'woocommerce-for-paygent-payment-main' ) );
				// Mark as pending (we're awaiting the payment).
				$order->update_status( 'pending', __( '3D Secure 2.0 Payment Processing.', 'woocommerce-for-paygent-payment-main' ) );
				// Reduce stock levels.
				wc_reduce_stock_levels( $order_id );
				// Return 3D Secure 2.0 redirect.
				return array(
					'result'   => 'success',
					'redirect' => $order->get_checkout_payment_url( true ), // to checkout page with paygent_3ds2_redirect_order.
				);
			}
			$order->add_order_note( __( 'paygent Payment completed. Transaction ID: ', 'woocommerce-for-paygent-payment-main' ) . $response['result_array'][0]['payment_id'] );

			// set transaction id for Paygent Order Number.
			$order->payment_complete( wc_clean( $response['result_array'][0]['payment_id'] ) );

			if ( 'sale' === $this->paymentaction && isset( $this->paymentaction ) ) {
				$telegram_kind = '182';
				$response_sale = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );
				if ( '0' !== $response_sale['result'] ) {
					$this->paygent_request->error_response( $response_sale, $order );
				}
			}
			// Return thank you redirect.
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		} else { // Error.
			if ( isset( $response['result_array'][0]['3ds_auth_id'] ) ) {// 3D Secure 2.0.
				$order->add_meta_data( '_paygent_3ds_response', $response['result_array'][0] );
			}
			$this->paygent_request->error_response( $response, $order );
			$message = __( 'Your credit card was not authorized.', 'woocommerce-for-paygent-payment-main' );
			wc_add_notice( $message, 'error' );
			return array(
				'result'   => 'failure',
				'redirect' => wc_get_checkout_url(),
			);
		}
	}

	/**
	 * Get 3D Secure Payment Status && update Woo Order Status
	 *
	 * @param  int $order_id Order ID.
	 */
	public function tds_status_change( $order_id ) {
		$order            = wc_get_order( $order_id );
		$payment_method   = $order->get_payment_method();
		$paygent_order_id = $order->get_meta( '_paygent_order_id' );
		$prefix_order     = get_option( 'wc-paygent-prefix_order' );
		$tradind_id       = '';
		if ( isset( $_GET['trading_id'] ) ) {// phpcs:ignore
			$tradind_id = wc_clean( wp_unslash( $_GET['trading_id'] ) );// phpcs:ignore
			if ( $paygent_order_id ) {
				$base_order_id = substr( $tradind_id, strlen( $prefix_order ) );
			} else {
				$base_order_id = substr( $tradind_id, 3 );
			}
		} else {
			$base_order_id = '';
		}

		if ( isset( $tradind_id  ) && $payment_method === $this->id && $order_id == $base_order_id && isset( $_GET['result'] ) && '0' === $_GET['result'] ) {// phpcs:ignore
			// set transaction id for Paygent Order Number.
			$order->set_transaction_id( wc_clean( wp_unslash( $_GET['payment_id'] ) ) );// phpcs:ignore
			// Mark as processing (payment complete).
			$order->update_status( 'processing', __( '3D Secure payment was complete.', 'woocommerce-for-paygent-payment-main' ) );
			// Reduce stock levels.
			wc_reduce_stock_levels( $order_id );

			// Sale payment action.
			if ( isset( $this->paymentaction ) && 'sale' === $this->paymentaction ) {
				$telegram_kind           = '182';
				$send_data['trading_id'] = $tradind_id;
				if ( '1' !== $this->paygent_request->site_id ) {
					$send_data['site_id'] = $this->paygent_request->site_id;
				}
				$response_sale = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );
				if ( '0' !== $response_sale['result'] ) {
					$this->paygent_request->error_response( $response_sale, $order );
				}
			}
			return;
			} elseif ( isset( $_GET['result'] ) && '1' === $_GET['result'] && $order->get_payment_method() === $this->id ) {// phpcs:ignore
			// set transaction id for Paygent Order Number.
			if ( isset( $_GET['payment_id'] ) ) {// phpcs:ignore
				$order->set_transaction_id( wc_clean( wp_unslash( $_GET['payment_id'] ) ) );// phpcs:ignore
			}
			// Mark as failed (payment failed).
			if ( isset( $_GET['response_code'] ) ) {// phpcs:ignore
				$response_code   = wc_clean( wp_unslash( $_GET['response_code'] ) );// phpcs:ignore
				$response_detail = wc_clean( wp_unslash( urldecode( $_GET['response_detail'] ?? '' ) ) );// phpcs:ignore
				$order->update_status( 'failed', __( 'Error at 3D Secure.', 'woocommerce-for-paygent-payment-main' ) . $response_code . ':' . $response_detail );
			}
		}
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
		if ( is_checkout() || is_add_payment_method_page() ) {
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
	 * Html to display the screen when authorizing 3DS 2.0
	 *
	 * @param int $order_id Order ID.
	 */
	public function paygent_3ds2_redirect_order( $order_id ) {
		$order = wc_get_order( $order_id );
		$html  = $order->get_meta( '_out_acs_html' );

		// Countermeasures for double display.
		global $jp4wc_cc_num;
		if ( $jp4wc_cc_num >= 1 ) {
			$jp4wc_cc_num = 0;
			return;
		}
		++$jp4wc_cc_num;

		if ( isset( $_GET['result'] ) && $order->get_payment_method() === $this->id ) {// phpcs:ignore
			if ( ! empty( $_GET['3dsecure_requestor_error_code'] ) ) {// phpcs:ignore
				$requestor_error_code = wc_clean( wp_unslash( $_GET['3dsecure_requestor_error_code'] ) );// phpcs:ignore
				$message              = $this->tdsecure_requestor_error_codes( $requestor_error_code );
				$order->add_order_note( __( '3D Secure 2.0 Requestor Error Code:', 'woocommerce-for-paygent-payment-main' ) . $requestor_error_code . ', ' . $message );
			}
			if ( ! empty( $_GET['3dsecure_server_error_code'] ) ) {// phpcs:ignore
				$server_error_code = wc_clean( wp_unslash( $_GET['3dsecure_server_error_code'] ) );// phpcs:ignore
				$message           = $this->tdsecure_server_error_codes( $server_error_code );
				$order->add_order_note( __( '3D Secure 2.0 Server Error Code:', 'woocommerce-for-paygent-payment-main' ) . $server_error_code . ', ' . $message );
			}
			if ( '0' === $_GET['result'] ) {// phpcs:ignore
				// Response Result is success at Challenge flow.
				$attempt_kbn = wc_clean( wp_unslash( $_GET['attempt_kbn'] ?? '' ) );// phpcs:ignore
				if ( '1' === $attempt_kbn ) {// Attempt kbn is attention.
					$order->add_order_note( __( 'Attempt kbn is attention.', 'woocommerce-for-paygent-payment-main' ) );
					if ( ! empty( $this->attempt_notice_email ) && 'yes' === $this->attempt ) {
						$to      = $this->attempt_notice_email;
						$subject = __( 'Notion: Attempt order#', 'woocommerce-for-paygent-payment-main' ) . $order_id;
						$message = __( 'This order is a caution && is not eligible for cashback. Please be careful about shipping etc.', 'woocommerce-for-paygent-payment-main' );
						wc_mail( $to, $subject, $message );
					}
					if ( 'yes' !== $this->attempt ) {
						wc_increase_stock_levels( $order_id );
						$order->update_status( 'cancelled', __( 'Failed 3D Secure 2.0.', 'woocommerce-for-paygent-payment-main' ) );
						wc_add_notice( __( 'Authentication was not obtained for credit card payment.', 'woocommerce-for-paygent-payment-main' ), 'error' );
						wp_safe_redirect( wc_get_checkout_url() );
						exit;
					}
				} elseif ( '0' === $attempt_kbn ) {// Attempt kbn is normal.
					$order->add_order_note( __( 'Using a card that is not 3D Secure.', 'woocommerce-for-paygent-payment-main' ) );
					$this->paygent_no_tds_card_response( $order );
				} elseif ( '' === $attempt_kbn ) { // Null is Authentication successful.
					$order->add_order_note( __( 'Attempt kbn is normal.', 'woocommerce-for-paygent-payment-main' ) );
				} else {
					$order->add_order_note( __( 'There was no response from Attempt kbn.', 'woocommerce-for-paygent-payment-main' ) );
					wc_add_notice( __( 'Attempt kbn was not answered. Something seems to be wrong. Please try a different card or contact the site administrator.', 'woocommerce-for-paygent-payment-main' ), 'error' );
					wp_safe_redirect( wc_get_checkout_url() );
					exit;
				}
				// If necessary, register customer's card information.
				if ( 'yes' === $this->store_card_info ) {
					$card_token = $order->get_meta( '_paygent_card_token' );
					$user_id    = $order->get_user_id();
					if ( false === $order->get_meta( '_paygent_customer_card_id' ) ) {
						$add_card_result = $this->paygent_tds_add_stored_card( $user_id, $card_token, $order );
						if ( false === $add_card_result ) {
							$order->add_order_note( __( 'Failed to store card information.', 'woocommerce-for-paygent-payment-main' ) );
						}
					}
				}
				$currency = $order->get_meta( '_paygent_currency_code' );
				$result   = $this->paygent_cc->paygent_tds_proceed_payment( $order, $this->paymentaction, $this->store_card_info, '180', $this->test_mode, $this->debug, $currency );
				if ( '0' === $result ) {
					wp_safe_redirect( $this->get_return_url( $order ) );
					exit;
				} else {
					wc_increase_stock_levels( $order_id );
					$order->update_status( 'cancelled', __( 'Failed 3D Secure 2.0.', 'woocommerce-for-paygent-payment-main' ) );
					wc_add_notice( __( 'Authentication was not obtained for credit card payment.', 'woocommerce-for-paygent-payment-main' ), 'error' );
					wp_safe_redirect( wc_get_checkout_url() );
					exit;
				}
			} elseif ( '1' === $_GET['result'] ) {// phpcs:ignore
				wc_increase_stock_levels( $order_id );
				$response_code   = wc_clean( wp_unslash( $_GET['response_code'] ?? '' ) );// phpcs:ignore
				$response_detail = wc_clean( wp_unslash( urldecode( $_GET['response_detail'] ?? '' ) ) );// phpcs:ignore
				$order->update_status( 'cancelled', __( 'Failed 3D Secure 2.0.', 'woocommerce-for-paygent-payment-main' ) . '[' . $response_code . ':' . $response_detail . ']' );
				wc_add_notice( __( 'Authentication was not obtained for credit card payment.', 'woocommerce-for-paygent-payment-main' ), 'error' );
				wp_safe_redirect( wc_get_checkout_url() );
				exit;
			}
		} elseif ( $html && $order->get_payment_method() === $this->id ) {// First redirect to 3D Secure 2.0 Challenge flow.
			$before = array( '<html>', '<body onload="OnLoadEvent();">', '</body>', '</html>' );
			$after  = array( '<!--<html>', '<body onload="OnLoadEvent();">-->', '', '' );
			$html   = str_replace( $before, $after, $html );
			echo $html;// phpcs:ignore
			echo '<script type="text/javascript">'
			. 'if (!window.paygent_3ds2_executed) {'
			. '  window.paygent_3ds2_executed = true;'
			. '  function send_form_submit() {'
			. '    var form = document.submitForm || document.getElementById("submitForm");'
			. '    if (form && typeof form.submit === "function") {'
			. '      form.submit();'
			. '    }'
			. '  }'
			. '  window.addEventListener("load", send_form_submit);'
			. '}'
			. '</script>';
		}
	}

	/**
	 * Error code for 3D Secure 2.0 Requestor
	 *
	 * @param object $user WP_User.
	 * @return void
	 */
	public function jp4wc_password_update( $user ) {
		update_user_meta( $user->ID, 'jp4wc_password_update', time() );
	}

	/**
	 * Get 3D Secure 2.0 Payment Status && update Woo Order Status
	 *
	 * @param  int $order_id Order ID.
	 */
	public function tds2_status_change( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order->get_payment_method() === $this->id && isset( $_GET['3ds_auth_id'] ) ) {// phpcs:ignore
			$tds_auth_id = wc_clean( wp_unslash( $_GET['3ds_auth_id'] ) );// phpcs:ignore
			if ( $tds_auth_id === $order->get_meta( '_3ds_auth_id' ) && ! empty( $_GET['result'] ) && '0' === $_GET['result'] ) {// phpcs:ignore
				try {
					$order->set_transaction_id( $tds_auth_id );
				} catch ( WC_Data_Exception $e ) {
					$order->add_order_note( 'fail to set transaction id.' );
				}
				$order->save();
				$order->update_status( 'processing', 'Complete 3D Secure 2.0' );
			} else {
				if ( isset( $_GET['3dsecure_requestor_error_code'] ) && isset( $_GET['3dsecure_server_error_code'] ) ) {// phpcs:ignore
					$order->add_order_note( 'requestor_error_code:' . wc_clean( wp_unslash( $_GET['3dsecure_requestor_error_code'] ) ) . '|Server_error_code:' . wc_clean( wp_unslash( $_GET['3dsecure_server_error_code'] ) ) ); // phpcs:ignore
				}
				wc_increase_stock_levels( $order_id );
				$order->update_status( 'cancelled', 'Failed 3D Secure 2.0' );
			}
		}
	}

	/**
	 * Three D secure Error at Thank you page.
	 *
	 * @param string $text Default text.
	 * @param object $order WP_Order.
	 * @return  string
	 */
	public function woocommerce_thankyou_order_received_td( $text, $order ) {
		if ( $order && $this->id === $order->get_payment_method() ) {
			if ( isset( $_GET['result'] ) && '1' === $_GET['result'] ) {// phpcs:ignore
				if ( isset( $_GET['response_code'] ) && '2001' === $_GET['response_code'] ) {// phpcs:ignore
					return '<strong style="color:red;">' . __( 'The payment has been interrupted.', 'woocommerce-for-paygent-payment-main' ) . '<br />
' . __( 'The following order has failed. Sorry to trouble you, but if you want to purchase, please order again from the beginning.', 'woocommerce-for-paygent-payment-main' ) . '</strong>';
				} else {
					return '<strong style="color:red;">' . __( 'Error at 3D Secure.', 'woocommerce-for-paygent-payment-main' ) . '</strong>';
				}
			} elseif ( isset( $this->order_received_text ) ) {
				return wc_clean( $this->order_received_text );
			}
		}

		return $text;
	}

	/**
	 * Check payment details for valid format
	 */
	public function validate_fields() {
		// Check for saving payment info without having or creating an account.
		if ( $this->jp4wc_framework->get_post( 'saveinfo' )
		&& ! is_user_logged_in()
		&& ! $this->jp4wc_framework->get_post( 'createaccount' ) ) {
			wc_add_notice( __( 'Sorry, you need to create an account in order for us to save your payment information.', 'woocommerce-for-paygent-payment-main' ), $notice_type = 'error' );
			return false;
		}
		// Edit Expire Data.
		$card_token          = $this->jp4wc_framework->get_post( 'paygent_mccc-token' );
		$card_cvc_token      = $this->jp4wc_framework->get_post( 'paygent_mccc-cvc_token' );
		$stored_payment_info = $this->jp4wc_framework->get_post( 'paygent-use-stored-payment-info' );

		if ( 'no' === $stored_payment_info || null === $stored_payment_info ) :
			if ( strpos( $card_token, 'tok_' ) === false ) {
				wc_add_notice( __( 'Input information of the credit card is not enough. Please check Credit card expiration date, etc.', 'woocommerce-for-paygent-payment-main' ), $notice_type = 'error' );
				return false;
			}
			if ( strpos( $card_cvc_token, 'tok_' ) === false ) {
				wc_add_notice( __( 'Input information of the credit card is not enough. Please check CVC.', 'woocommerce-for-paygent-payment-main' ), $notice_type = 'error' );
				return false;
			}
			endif;
		// 3D Secure 2.0 cardholder name
		if ( 'yes' === $this->tds2_check
		&& '' === $this->jp4wc_framework->get_post( 'paygent_cardholder_name' )
		&& ( 'no' === $stored_payment_info || null === $stored_payment_info ) ) {
			wc_add_notice( __( 'Please enter the cardholder name.', 'woocommerce-for-paygent-payment-main' ) . $test, 'error' );
			return false;
		} elseif ( 'yes' === $this->tds2_check
		&& 'no' === $stored_payment_info
		&& '' !== $this->jp4wc_framework->get_post( 'paygent_cardholder_name' ) ) {
			$cardholder_name = $this->jp4wc_framework->get_post( 'paygent_cardholder_name' );
			if ( ! preg_match( '/^[a-zA-Z\s]+$/', $cardholder_name ) ) {
				wc_add_notice( __( 'Please enter the cardholder name in alphabet.', 'woocommerce-for-paygent-payment-main' ), 'error' );
				return false;
			}
		}

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
				'auth_cancel' => array( '20' ),
				'sale_cancel' => array( '35', '40' ),
				'auth_change' => array( '20' ),
				'sale_change' => array( '35', '40' ),
			),
		);
		$send_data_refund = array(
			'payment_amount' => $amount,
			'reduction_flag' => 1,
		);
		return $this->paygent_request->paygent_process_refund( $order_id, $amount, $telegram_array, $permit_statuses, $send_data_refund, $this );
	}

	/**
	 * Update Sale from Auth to Paygent System
	 *
	 * @param int $order_id Order ID.
	 */
	public function order_paygent_mccc_status_completed( $order_id ) {
		$order                        = wc_get_order( $order_id );
		$check_paygent_payment_status = $this->paygent_request->paygent_get_payment_status( $order, $this );
		if ( ! $check_paygent_payment_status ) {
			return;
		}
		if ( '20' !== $check_paygent_payment_status['payment_status'] ) {
			return;
		}
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}
		$telegram_kind = '182';
		$prefix_order  = get_option( 'wc-paygent-prefix_order' );
		if ( $prefix_order ) {
			$send_data['trading_id'] = $prefix_order . $order_id;
		} else {
			$send_data['trading_id'] = 'wc_' . $order_id;
		}
		if ( 1 !== $this->paygent_request->site_id ) {
			$send_data['site_id'] = $this->paygent_request->site_id;
		} else {
			$send_data['site_id'] = 1;
		}
		$response_sale = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );
		if ( '0' !== $response_sale['result'] ) {
			$this->paygent_request->error_response( $response_sale, $order );
		}
	}

	/**
	 * Deletes a saved payment card from Paygent when it's removed from WooCommerce
	 *
	 * @param int              $token_id The ID of the token being deleted.
	 * @param WC_Payment_Token $token    The payment token object being deleted.
	 * @return void
	 */
	public function paygent_mccc_delete_card( $token_id, $token ) {
		$customer_card_id = $token->get_meta( 'customer_card_id' );
		$delete_card_data = array(
			'customer_id'      => 'wc' . get_current_user_id(),
			'customer_card_id' => $customer_card_id,
		);
		$delete_result    = $this->delete_card( $delete_card_data );
	}
}
