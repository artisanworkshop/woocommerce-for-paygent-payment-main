<?php
/**
 * WooCommerce Paygent ATM Gateway
 *
 * Provides a Paygent ATM Payment Gateway for WooCommerce.
 *
 * @version 2.4.0
 * @package WooCommerce/Gateways
 * @category Payment Gateways
 * @author Artisan Workshop
 */

use ArtisanWorkshop\PluginFramework\v2_0_13 as Framework;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class WC_Gateway_Paygent_ATM
 *
 * Handles ATM payments through Paygent payment gateway.
 *
 * @extends WC_Payment_Gateway
 */
class WC_Gateway_Paygent_ATM extends WC_Payment_Gateway {
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
		$this->id         = 'paygent_atm';
		$this->has_fields = false;
		// translators: %s is the name of the payment method.
		$this->order_button_text = sprintf( __( 'Proceed to %s', 'woocommerce-for-paygent-payment-main' ), __( 'ATM Payment', 'woocommerce-for-paygent-payment-main' ) );

		// Create plugin fields and settings.
		$this->init_form_fields();
		$this->init_settings();
		// translators: %s is the name of the payment method.
		$this->method_title = sprintf( __( 'Paygent %s Gateway', 'woocommerce-for-paygent-payment-main' ), __( 'ATM Payment', 'woocommerce-for-paygent-payment-main' ) );
		// translators: %s is the name of the payment method.
		$this->method_description = sprintf( __( 'Allows payments by Paygent %s in Japan.', 'woocommerce-for-paygent-payment-main' ), __( 'ATM Payment', 'woocommerce-for-paygent-payment-main' ) );
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
		$this->test_mode = get_option( 'wc-paygent-testmode' );

		// Actions.
		add_action( 'woocommerce_receipt_paygent_atm', array( $this, 'receipt_page' ) );
		add_action( 'woocommerce_update_options_payment_gateways', array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		// Customer UI and UX.
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_atm_instructions' ), 10, 3 );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'payment_atm_detail' ) );
		// Custom Thank you page.
		if ( 'yes' === $this->enabled ) {
			add_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'paygent_atm_order_received_text' ), 10, 2 );
		}
	}

	/**
	 * Initialize Gateway Settings Form Fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'             => array(
				'title'       => __( 'Enable/Disable', 'woocommerce-for-paygent-payment-main' ),
				// translators: %s is the name of the payment method.
				'label'       => sprintf( __( 'Enable paygent %s Payment', 'woocommerce-for-paygent-payment-main' ), __( 'ATM Payment', 'woocommerce-for-paygent-payment-main' ) ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
			'title'               => array(
				'title'       => __( 'Title', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-for-paygent-payment-main' ),
				'default'     => __( 'ATM Payment', 'woocommerce-for-paygent-payment-main' ),
			),
			'description'         => array(
				'title'       => __( 'Description', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce-for-paygent-payment-main' ),
				// translators: %s is the name of the payment method.
				'default'     => sprintf( __( 'Pay with your %s via Paygent.', 'woocommerce-for-paygent-payment-main' ), __( 'ATM Payment', 'woocommerce-for-paygent-payment-main' ) ),
			),
			'order_button_text'   => array(
				'title'       => __( 'Order Button Text', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce-for-paygent-payment-main' ),
				// translators: %s is the name of the payment method.
				'default'     => sprintf( __( 'Proceed to %s', 'woocommerce-for-paygent-payment-main' ), __( 'ATM Payment', 'woocommerce-for-paygent-payment-main' ) ),
			),
			'order_received_text' => array(
				'title'       => __( 'Thank you page description', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description displayed on the thank you page.', 'woocommerce-for-paygent-payment-main' ),
				'default'     => __( 'Thank you. Your order has been received. Please pay at the bank ATM by the specified method.', 'woocommerce-for-paygent-payment-main' ),
			),
			'payment_detail'      => array(
				'title'       => __( 'Invoice detail', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'text',
				'description' => __( 'This controls the text which the detail at ATM. Please enter in full-width characters.', 'woocommerce-for-paygent-payment-main' ),
				'default'     => __( 'Shop Title', 'woocommerce-for-paygent-payment-main' ),
			),
			'payment_detail_kana' => array(
				'title'       => __( 'Invoice detail (kana)', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'text',
				'description' => __( 'This controls the text which the detail at ATM.(Kana) Please enter in half-width characters.', 'woocommerce-for-paygent-payment-main' ),
				'default'     => __( 'Shop Title (kana)', 'woocommerce-for-paygent-payment-main' ),
			),
			'payment_limit_date'  => array(
				'title'       => __( 'Payment Limit date', 'woocommerce-for-paygent-payment-main' ),
				'id'          => 'wc-paygent-atm-limit',
				'type'        => 'text',
				'default'     => '30',
				'description' => __( 'Set Payment limit date.', 'woocommerce-for-paygent-payment-main' ),
			),
			'debug'               => array(
				'title'       => __( 'Debug Mode', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable Debug Mode', 'woocommerce-for-paygent-payment-main' ),
				'default'     => 'no',
				'description' => __( 'Save debug data using WooCommerce logging.', 'woocommerce-for-paygent-payment-main' ),
			),
		);
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id Order ID.
	 */
	public function process_payment( $order_id ) {
		$order = new WC_Order( $order_id );
		$user  = wp_get_current_user();
		if ( 0 !== $user->ID ) {
			$customer_id = $user->ID;
		} else {
			$customer_id = $order_id . '-user';
		}
		$send_data = array();

		// Common header.
		$telegram_kind = '010';
		$prefix_order  = get_option( 'wc-paygent-prefix_order' );
		if ( $prefix_order ) {
			$send_data['trading_id'] = $prefix_order . $order_id;
		} else {
			$send_data['trading_id'] = 'wc_' . $order_id;
		}
		$send_data['payment_id'] = '010';

		$send_data['payment_amount']      = $order->get_total();
		$send_data['payment_detail']      = mb_convert_encoding( $this->payment_detail, 'SJIS', 'UTF-8' );
		$send_data['payment_detail_kana'] = mb_convert_encoding( $this->payment_detail_kana, 'SJIS', 'UTF-8' );
		$send_data['payment_limit_date']  = $this->payment_limit_date;

		$response = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );

		// Check response.
		if ( '0' === $response['result'] && isset( $response['result_array'] ) ) {
			$order->add_meta_data( '_pay_center_number', wc_clean( $response['result_array'][0]['pay_center_number'] ), true );
			$order->add_meta_data( '_customer_number', wc_clean( $response['result_array'][0]['customer_number'] ), true );
			$order->add_meta_data( '_conf_number', wc_clean( $response['result_array'][0]['conf_number'] ), true );
			$order->add_meta_data( '_payment_limit_date', wc_clean( $response['result_array'][0]['payment_limit_date'] ), true );
			$order->add_meta_data( '_paygent_order_id', $send_data['trading_id'], true );
			// set transaction id for Paygent Order Number.
			$order->set_transaction_id( wc_clean( $response['result_array'][0]['payment_id'] ) );
			// Mark as on-hold (we're awaiting the payment).
			// translators: %s is the name of the payment method.
			$order->update_status( 'on-hold', sprintf( __( 'Awaiting %s', 'woocommerce-for-paygent-payment-main' ), __( 'ATM Payment', 'woocommerce-for-paygent-payment-main' ) ) );

			// Return thank you redirect.
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);

		} else {
			$this->paygent_request->error_response( $response, $order );
			return array( 'result' => 'failed' );
		}
	}

	/**
	 * Add content to the WC emails For ATM Information.
	 *
	 * @access public
	 * @param object $order Order object.
	 * @param bool   $sent_to_admin Sent to admin.
	 * @return void
	 */
	public function email_atm_instructions( $order, $sent_to_admin ) {
		$payment_method = $order->get_payment_method();
		if ( ! $sent_to_admin && $this->id === $payment_method && 'on-hold' === $order->status ) {
			$order_id = $order->get_id();
			if ( $this->instructions ) {
				echo esc_html( $this->instructions ) . PHP_EOL;
			}
			$this->payment_atm_detail( $order_id );
		}
	}

	/**
	 * Pay Form for thank you page and e-mail
	 *
	 * @param int $order_id Order ID.
	 */
	public function payment_atm_detail( $order_id ) {
		$status = get_post_status( $order_id );
		if ( 'wc-on-hold' === $status ) {
			$pay_center_number  = get_post_meta( $order_id, '_pay_center_number', true );
			$customer_number    = get_post_meta( $order_id, '_customer_number', true );
			$conf_number        = get_post_meta( $order_id, '_conf_number', true );
			$payment_limit_date = get_post_meta( $order_id, '_payment_limit_date', true );
			echo '<ul class="woocommerce-thankyou-order-details order_details">' . PHP_EOL;
			echo '<li class="pay_center_number">' . PHP_EOL;
			echo esc_html__( 'Pay Center Number :', 'woocommerce-for-paygent-payment-main' ) . '<strong>' . esc_html( $pay_center_number ) . '</strong>' . PHP_EOL;
			echo '</li>
			<li class="customer_number">' . PHP_EOL;
			echo esc_html__( 'Customer Number :', 'woocommerce-for-paygent-payment-main' ) . '<strong>' . esc_html( $customer_number ) . '</strong>' . PHP_EOL;
			echo '</li>
			<li class="conf_number">' . PHP_EOL;
			echo esc_html__( 'Conf Number :', 'woocommerce-for-paygent-payment-main' ) . '<strong>' . esc_html( $conf_number ) . '</strong>' . PHP_EOL;
			echo '</li>
			<li class="payment_limit_date">' . PHP_EOL;
			echo esc_html__( 'Payment Limit Date :', 'woocommerce-for-paygent-payment-main' ) . '<strong>' . esc_html( $payment_limit_date ) . '</strong>' . PHP_EOL;
			echo '</li>
			</ul>' . PHP_EOL;
			echo esc_html__( 'This order was not complete. Please pay at ATM.', 'woocommerce-for-paygent-payment-main' ) . PHP_EOL;
		}
	}

	/**
	 * Display receipt page.
	 *
	 * @param WC_Order $order Order object.
	 */
	public function receipt_page( $order ) {
		echo '<p>' . esc_html__( 'Thank you for your order.', 'woocommerce-for-paygent-payment-main' ) . '</p>';
	}

	/**
	 * Get post data if set
	 *
	 * @param string $name The name of the POST field.
	 * @return string|null The sanitized POST field value or null if not set.
	 */
	public function get_post( $name ) {
		// Get the WC_Checkout object.
		$checkout = WC()->checkout();
		return $checkout->get_value( $name );
	}

	/**
	 * Custom Paygent Convenience store payment order received text.
	 *
	 * @since 2.1.0
	 * @param string   $text Default text.
	 * @param WC_Order $order Order data.
	 * @return string
	 */
	public function paygent_atm_order_received_text( $text, $order ) {
		if ( $order && $this->id === $order->get_payment_method() ) {
			if ( isset( $this->order_received_text ) ) {
				return wc_clean( $this->order_received_text );
			} else {
				return esc_html__( 'Thank you. Your order has been received. Please pay at the bank ATM by the specified method.', 'woocommerce-for-paygent-payment-main' );
			}
		}

		return $text;
	}
}
