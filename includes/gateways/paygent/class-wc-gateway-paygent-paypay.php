<?php
/**
 * WooCommerce Paygent PayPay Gateway
 *
 * Provides a Paygent PayPay Payment Gateway integration for WooCommerce.
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
 * Class WC_Gateway_Paygent_PayPay
 *
 * Handles PayPay payments through Paygent payment gateway.
 *
 * @extends WC_Payment_Gateway
 */
class WC_Gateway_Paygent_PayPay extends WC_Payment_Gateway {
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
	 * Set Paygent request class
	 *
	 * @var stdClass
	 */
	public $paygent_request;

	/**
	 * Environment
	 *
	 * @var string
	 */
	public $environment;

	/**
	 * Constructor for the gateway.
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() {
		// Setup general properties.
		$this->setup_properties();
		// translators: %s: PayPay.
		$this->order_button_text = sprintf( __( 'Proceed to %s', 'woocommerce-for-paygent-payment-main' ), __( 'PayPay', 'woocommerce-for-paygent-payment-main' ) );

		// Create plugin fields and settings.
		$this->init_form_fields();
		$this->init_settings();
		$this->supports = array(
			'products',
			'refunds',
		);
		// When no save setting error at chackout page.
		if ( is_null( $this->title ) ) {
			$this->title = __( 'Please set this payment at Control Panel! ', 'woocommerce-for-paygent-payment-main' ) . $this->method_title;
		}
		// Get setting values.
		foreach ( $this->settings as $key => $val ) {
			$this->$key = $val;
		}

		// Set JP4WC framework.
		$this->jp4wc_framework = new Framework\JP4WC_Framework();

		include_once 'includes/class-wc-gateway-paygent-request.php';
		$this->paygent_request = new WC_Gateway_Paygent_Request();

		// Set Test mode.
		$this->test_mode   = get_option( 'wc-paygent-testmode' );
		$this->environment = ( '1' !== get_option( 'wc-paygent-testmode' ) ) ? 'live' : 'sandbox';

		// Actions.
		add_action( 'woocommerce_update_options_payment_gateways', array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		// Redirect on payment page.
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'paypay_redirect_order' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'paygent_paypay_thankyou' ), 10, 1 );

		add_action( 'woocommerce_order_status_completed', array( $this, 'order_paypay_status_completed' ) );
	}

	/**
	 * Setup general properties for the gateway.
	 */
	protected function setup_properties() {
		$this->id                 = 'paygent_paypay';
		$this->icon               = apply_filters( 'woocommerce_paypay_icon', '' );
		$this->method_title       = __( 'Paygent PayPay Payment Gateway', 'woocommerce-for-paygent-payment-main' );
		$this->method_description = __( 'Allows payments by Paygent PayPay in Japan.', 'woocommerce-for-paygent-payment-main' );
		$this->has_fields         = false;
	}

	/**
	 * Initialize Gateway Settings Form Fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'           => array(
				'title'       => __( 'Enable/Disable', 'woocommerce-for-paygent-payment-main' ),
				'label'       => __( 'Enable paygent PayPay Payment', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
			'title'             => array(
				'title'       => __( 'Title', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-for-paygent-payment-main' ),
				'default'     => __( 'PayPay', 'woocommerce-for-paygent-payment-main' ),
			),
			'description'       => array(
				'title'       => __( 'Description', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce-for-paygent-payment-main' ),
				// translators: %s: PayPay.
				'default'     => sprintf( __( 'Pay with your %s via Paygent.', 'woocommerce-for-paygent-payment-main' ), __( 'PayPay', 'woocommerce-for-paygent-payment-main' ) ),
			),
			'order_button_text' => array(
				'title'       => __( 'Order Button Text', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce-for-paygent-payment-main' ),
				// translators: %s: PayPay.
				'default'     => sprintf( __( 'Proceed to %s', 'woocommerce-for-paygent-payment-main' ), __( 'PayPay', 'woocommerce-for-paygent-payment-main' ) ),
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
	 * Process the payment and return the result
	 *
	 * @param int $order_id Order ID.
	 * @return array | mixed
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		// Common header.
		$telegram_kind = '420';// Apply.
		$prefix_order  = get_option( 'wc-paygent-prefix_order' );
		if ( $prefix_order ) {
			$send_data['trading_id'] = $prefix_order . $order_id;
		} else {
			$send_data['trading_id'] = 'wc_' . $order_id;
		}
		$send_data['payment_id']     = '';
		$send_data['payment_amount'] = $order->get_total();
		$send_data['cancel_url']     = $order->get_cancel_order_url_raw();
		$send_data['return_url']     = $this->get_return_url( $order );

		$response = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );

		if ( isset( $response['result'] ) && '0' === $response['result'] && isset( $response['result_array'] ) ) {
			// Success.
			$order->set_transaction_id( $response['result_array'][0]['payment_id'] );
			$order->add_meta_data( '_paygent_order_id', $response['result_array'][0]['trading_id'], true );
			$redirect_html       = str_replace( array( "\r\n", "\r" ), "\n", $response['result_array'][0]['redirect_html'] );
			$redirect_html_array = explode( "\n", $redirect_html );
			unset( $redirect_html_array[31] );
			$order->add_meta_data( '_paygent_paypay_html', $redirect_html_array );
			$order->save();
		} else {
			$this->paygent_request->error_response( $response, $order );
			return array(
				'result'   => 'failure',
				'redirect' => wc_get_checkout_url(),
			);
		}
		// Return Payment page redirect.
		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	/**
	 * Redirect HTML output on payment page.
	 *
	 * @param int $order_id Order ID.
	 */
	public function paypay_redirect_order( $order_id ) {
		$order = wc_get_order( $order_id );
		$htmls = $order->get_meta( '_paygent_paypay_html', true );

		$allow_redirect_html       = array(
			'form'  => array(
				'action'         => array(),
				'method'         => array(),
				'name'           => array(),
				'accept-charset' => array(),
			),
			'input' => array(
				'type'  => array(),
				'name'  => array(),
				'value' => array(),
			),
		);
		$javascript_auto_send_code = '
		<script type="text/javascript">
		function send_form_submit() {
			document.form.submit();
		}
		window.onload = send_form_submit;
		</script>';
		if ( $htmls ) {
			foreach ( $htmls as $html ) {
				echo wp_kses( $html, $allow_redirect_html );
			}
			echo '<input type="submit" value="PayPay認証"></form>';
			echo wp_kses( $javascript_auto_send_code, array( 'script' => array( 'type' => array() ) ) );
			wc_reduce_stock_levels( $order_id );
			$order->update_status( 'on-hold' );
			$order->save();
		}
	}

	/**
	 * PayPay Thank you page.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function paygent_paypay_thankyou( $order_id ) {
		$order          = wc_get_order( $order_id );
		$payment_method = $order->get_payment_method();
		if ( $payment_method === $this->id && isset( $_GET['trading_id'] ) && $order->get_status() === 'on-hold' ) {// phpcs:ignore
			try {
				$order->set_transaction_id( $_GET['payment_id'] );// phpcs:ignore
				if( isset( $_GET['paypay_payment_id'] ) ) {//phpcs:ignore
					$order->add_meta_data( '_paypay_payment_id', $_GET['paypay_payment_id'] );//phpcs:ignore
				}
				$order->save();
			} catch ( WC_Data_Exception $e ) {
				$order->add_order_note( $e->getErrorCode() . ':' . $e->getMessage() );
			}
			$order->update_status( 'processing', __( 'PayPay payment was successful.', 'woocommerce-for-paygent-payment-main' ) );
		}
	}

	/**
	 * Process a refund if supported
	 *
	 * @param  int    $order_id Order ID.
	 * @param  float  $amount Refund amount.
	 * @param  string $reason Refund reason.
	 * @return  boolean True or false based on success, or a WP_Error object
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( $order->get_total() >= $amount && $order->get_payment_method() === $this->id ) {
			// Set Order ID for Paygent.
			$send_data                     = array();
			$send_data['trading_id']       = 'wc_' . $order_id;
			$send_data['repayment_amount'] = $amount;
			$send_data['payment_id']       = $order->get_transaction_id();
			$telegram_kind                 = '421';
			$response                      = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );
			if ( '0' === $response['result'] ) {
				$order->add_order_note( __( 'Success the Refund for paygent.', 'woocommerce-for-paygent-payment-main' ) );
				$order->save();
				return true;
			} else {
				$order->add_order_note( __( 'Failed the refund for paygent.', 'woocommerce-for-paygent-payment-main' ) . $response['responseCode'] . ':' . $response['responseDetail'] . ':' . $response['result'] );
				$order->save();
				return false;
			}
		} else {
			$order->add_order_note( __( 'Failed this order to refund for paygent.', 'woocommerce-for-paygent-payment-main' ) );
			$order->save();
			return false;
		}
	}

	/**
	 * Remove redirect html text file
	 *
	 * @param int $order_id Order ID.
	 */
	public function order_paypay_status_completed( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order->get_payment_method() === $this->id ) {
			// Set Order ID for Paygent.
			$send_data                   = array();
			$send_data['trading_id']     = 'wc_' . $order_id;
			$send_data['payment_amount'] = $order->get_total();
			$send_data['payment_id']     = $order->get_transaction_id();
			$telegram_kind               = '422';
			$response                    = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );
			if ( '0' === $response['result'] ) {
				$order->add_order_note( __( 'Success the Complete for paygent.', 'woocommerce-for-paygent-payment-main' ) );
				return true;
			} else {
				$order->add_order_note( __( 'Failed the complete for paygent.', 'woocommerce-for-paygent-payment-main' ) . $response['responseCode'] . ':' . $response['responseDetail'] . ':' . $response['result'] );
				return false;
			}
		}
	}
}
