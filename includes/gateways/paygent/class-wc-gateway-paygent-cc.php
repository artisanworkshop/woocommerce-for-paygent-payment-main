<?php
/**
 * WooCommerce Paygent Credit Card Payment Gateway
 *
 * Provides integration between WooCommerce and Paygent's Credit Card payment processing service.
 * This class handles all credit card payment operations including authorization, capture, and refund.
 *
 * @version 2.4.6
 * @package WooCommerce/Gateways
 * @category Payment Gateways
 * @author ArtisanWorkshop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ArtisanWorkshop\PluginFramework\v2_0_13 as Framework;

/**
 * Class WC_Gateway_Paygent_CC
 *
 * Handles Credit Card payments through Paygent payment gateway.
 *
 * @extends WC_Payment_Gateway
 */
class WC_Gateway_Paygent_CC extends WC_Payment_Gateway {
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
	 * 3D secure Check (for backward compatibility)
	 *
	 * @var string
	 */
	public $tds_check;

	/**
	 * 3D secure 2.0 Check
	 *
	 * @var string
	 */
	public $tds2_check;

	/**
	 * Set gmopg request class
	 *
	 * @var stdClass
	 */
	public $paygent_request;

	/**
	 * Store card information.
	 *
	 * @var string
	 */
	public $store_card_info;

	/**
	 * Payment methods.
	 *
	 * @var array
	 */
	public $payment_methods;

	/**
	 * Setting for VISA & MASTER cards.
	 *
	 * @var string
	 */
	public $setting_card_vm;

	/**
	 * Setting for DINNERS cards.
	 *
	 * @var string
	 */
	public $setting_card_d;

	/**
	 * Setting for AMEX & JCB cards.
	 *
	 * @var string
	 */
	public $setting_card_aj;

	/**
	 * Payment method.
	 *
	 * @var array
	 */
	public $payment_method;

	/**
	 * Number of payments.
	 *
	 * @var array
	 */
	public $number_of_payments;

	/**
	 * Payment action.
	 *
	 * @var string
	 */
	public $paymentaction;

	/**
	 * Merchant name.
	 *
	 * @var string
	 */
	public $merchant_name;

	/**
	 * Order received text.
	 *
	 * @var string
	 */
	public $order_received_text;

	/**
	 * 3D Secure 2.0 hash key.
	 *
	 * @var string
	 */
	public $tds2_hashkey;

	/**
	 * Attempt classification compatible.
	 *
	 * @var string
	 */
	public $attempt;

	/**
	 * Attempt notice email.
	 *
	 * @var string
	 */
	public $attempt_notice_email;

	/**
	 * Accept No 3D Secure Card.
	 *
	 * @var string
	 */
	public $no_tds_card;

	/**
	 * Delete expired cards.
	 *
	 * @var string
	 */
	public $delete_expired_cards;

	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {
		$this->id                = 'paygent_cc';
		$this->has_fields        = true;
		$this->order_button_text = sprintf(
		// translators: %s is the payment method name.
			__( 'Proceed to %s', 'woocommerce-for-paygent-payment-main' ),
			__( 'Credit Card', 'woocommerce-for-paygent-payment-main' )
		);

		// Create plugin fields && settings.
		$this->init_form_fields();
		$this->init_settings();
		$this->method_title       = __( 'Paygent Credit Card Payment Gateway', 'woocommerce-for-paygent-payment-main' );
		$this->method_description = __( 'Allows payments by Paygent Credit Card in Japan.', 'woocommerce-for-paygent-payment-main' );
		$this->supports           = array(
			'subscriptions',
			'products',
			'subscription_cancellation',
			'subscription_reactivation',
			'subscription_suspension',
			'subscription_amount_changes',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
			'subscription_date_changes',
			'multiple_subscriptions',
			'tokenization',
			'refunds',
			'default_credit_card_form',
		);
		$this->payment_methods    = array(
			'10' => __( '1 time payment', 'woocommerce-for-paygent-payment-main' ),
			'61' => __( 'Installment payment', 'woocommerce-for-paygent-payment-main' ),
			'23' => __( 'Bonus One time', 'woocommerce-for-paygent-payment-main' ),
			'80' => __( 'Revolving payment', 'woocommerce-for-paygent-payment-main' ),
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
		$this->test_mode = get_option( 'wc-paygent-testmode' );

		// Load plugin checkout credit Card icon.
		if ( isset( $this->setting_card_vm ) ) {
			if ( 'yes' === $this->setting_card_vm && 'yes' === $this->setting_card_d && 'yes' === $this->setting_card_aj ) {
				$this->icon = plugins_url( 'images/paygent-cards.png', __FILE__ );
			} elseif ( 'yes' === $this->setting_card_vm && 'no' === $this->setting_card_d && 'no' === $this->setting_card_aj ) {
				$this->icon = plugins_url( 'images/paygent-cards-v-m.png', __FILE__ );
			} elseif ( 'yes' === $this->setting_card_vm && 'yes' === $this->setting_card_d && 'no' === $this->setting_card_aj ) {
				$this->icon = plugins_url( 'images/paygent-cards-v-m-d.png', __FILE__ );
			} elseif ( 'yes' === $this->setting_card_vm && 'no' === $this->setting_card_d && 'yes' === $this->setting_card_aj ) {
				$this->icon = plugins_url( 'images/paygent-cards-v-m-a-j.png', __FILE__ );
			} elseif ( 'no' === $this->setting_card_vm && 'no' === $this->setting_card_d && 'yes' === $this->setting_card_aj ) {
				$this->icon = plugins_url( 'images/paygent-cards-a-j.png', __FILE__ );
			} elseif ( 'no' === $this->setting_card_vm && 'yes' === $this->setting_card_d && 'no' === $this->setting_card_aj ) {
				$this->icon = plugins_url( 'images/paygent-cards-d.png', __FILE__ );
			} elseif ( 'no' === $this->setting_card_vm && 'yes' === $this->setting_card_d && 'yes' === $this->setting_card_aj ) {
				$this->icon = plugins_url( 'images/paygent-cards-d-a-j.png', __FILE__ );
			}
		}

		// Actions.
		add_action( 'woocommerce_update_options_payment_gateways', array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		if ( 'yes' === $this->enabled ) {
			add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'tds_status_change' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'paygent_token_scripts_method' ) );
			add_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'woocommerce_thankyou_order_received_td' ), 10, 2 );
		}
		// 3D secure 2.0.
		if ( 'yes' === $this->tds2_check ) {
			// Remove any existing hooks before adding to prevent duplicates.
			remove_action( 'password_reset', array( $this, 'jp4wc_password_update' ), 10 );
			add_action( 'password_reset', array( $this, 'jp4wc_password_update' ), 10 );

			remove_action( 'woocommerce_receipt_' . $this->id, array( $this, 'paygent_3ds2_redirect_order' ) );
			add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'paygent_3ds2_redirect_order' ) );// Payment page.

			remove_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'tds2_status_change' ) );
			add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'tds2_status_change' ) );
		}

		add_action( 'woocommerce_payment_token_deleted', array( $this, 'paygent_delete_card' ), 10, 2 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'order_paygent_cc_status_completed' ) );

		// Amount increase meta box (028/029).
		add_action( 'add_meta_boxes', array( $this, 'paygent_cc_add_increase_meta_box' ), 24, 2 );
		add_action( 'add_meta_boxes_woocommerce_page_wc-orders', array( $this, 'paygent_cc_add_increase_meta_box' ), 24, 2 );
		add_action( 'wp_ajax_paygent_cc_increase_amount', array( $this, 'paygent_cc_process_increase_amount' ) );
	}

	/**
	 * Initialize Gateway Settings Form Fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'              => array(
				'title'       => __( 'Enable/Disable', 'woocommerce-for-paygent-payment-main' ),
				'label'       => __( 'Enable paygent Credit Card Payment', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
			'title'                => array(
				'title'       => __( 'Title', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-for-paygent-payment-main' ),
				'default'     => __( 'Credit Card', 'woocommerce-for-paygent-payment-main' ),
			),
			'description'          => array(
				'title'       => __( 'Description', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce-for-paygent-payment-main' ),
				// translators: %s is the payment method name.
				'default'     => sprintf( __( 'Pay with your %s via Paygent.', 'woocommerce-for-paygent-payment-main' ), __( 'Credit Card', 'woocommerce-for-paygent-payment-main' ) ),
			),
			'order_button_text'    => array(
				'title'       => __( 'Order Button Text', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'text',
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce-for-paygent-payment-main' ),
				// translators: %s is the payment method name.
				'default'     => sprintf( __( 'Proceed to %s', 'woocommerce-for-paygent-payment-main' ), __( 'Credit Card', 'woocommerce-for-paygent-payment-main' ) ),
			),
			'order_received_text'  => array(
				'title'       => __( 'Thank you page description', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description displayed on the thank you page.', 'woocommerce-for-paygent-payment-main' ),
				'default'     => __( 'Thank you. Your order has been received.', 'woocommerce-for-paygent-payment-main' ),
			),
			'store_card_info'      => array(
				'title'       => __( 'Store Card Infomation', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable Store Card Infomation', 'woocommerce-for-paygent-payment-main' ),
				'default'     => 'no',
				'description' => __( 'Store user Credit Card information in Paygent Server.(Option)', 'woocommerce-for-paygent-payment-main' ),
			),
			'delete_expired_cards' => array(
				'title'       => __( 'Delete expired cards', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable Delete expired cards', 'woocommerce-for-paygent-payment-main' ),
				'default'     => 'no',
				'description' => __( 'Expired cards will be automatically deleted when a payment fails.', 'woocommerce-for-paygent-payment-main' ),
			),
			'setting_card_vm'      => array(
				'title'   => __( 'Set Credit Card', 'woocommerce-for-paygent-payment-main' ),
				'id'      => 'wc-paygent-cc-vm',
				'type'    => 'checkbox',
				'label'   => __( 'VISA & MASTER', 'woocommerce-for-paygent-payment-main' ),
				'default' => 'yes',
			),
			'setting_card_d'       => array(
				'id'      => 'wc-paygent-cc-d',
				'type'    => 'checkbox',
				'label'   => __( 'DINNERS', 'woocommerce-for-paygent-payment-main' ),
				'default' => 'yes',
			),
			'setting_card_aj'      => array(
				'id'          => 'wc-paygent-cc-aj',
				'type'        => 'checkbox',
				'label'       => __( 'AMEX & JCB', 'woocommerce-for-paygent-payment-main' ),
				'default'     => 'yes',
				'description' => __( 'Please check them you are able to use Credit Card', 'woocommerce-for-paygent-payment-main' ),
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
			'payment_method'       => array(
				'title'       => __( 'Payment Method', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'multiselect',
				'class'       => 'wc-multi-select',
				'description' => __( 'Choose whether you wish to capture funds immediately or authorize payment only.', 'woocommerce-for-paygent-payment-main' ),
				'options'     => array(
					'10' => __( '1 time payment', 'woocommerce-for-paygent-payment-main' ),
					'61' => __( 'Installment payment', 'woocommerce-for-paygent-payment-main' ),
					'23' => __( 'Bonus One time', 'woocommerce-for-paygent-payment-main' ),
					'80' => __( 'Revolving payment', 'woocommerce-for-paygent-payment-main' ),
				),
			),
			'number_of_payments'   => array(
				'title'       => __( 'Number of payments', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'multiselect',
				'class'       => 'wc-multi-select',
				'description' => __( 'Please select from here if you choose installment payment. (Multiple selection possible).', 'woocommerce-for-paygent-payment-main' ),
				'desc_tip'    => true,
				'options'     => array(
					'2'  => '2' . __( 'times', 'woocommerce-for-paygent-payment-main' ),
					'3'  => '3' . __( 'times', 'woocommerce-for-paygent-payment-main' ),
					'4'  => '4' . __( 'times', 'woocommerce-for-paygent-payment-main' ),
					'5'  => '5' . __( 'times', 'woocommerce-for-paygent-payment-main' ),
					'6'  => '6' . __( 'times', 'woocommerce-for-paygent-payment-main' ),
					'10' => '10' . __( 'times', 'woocommerce-for-paygent-payment-main' ),
					'12' => '12' . __( 'times', 'woocommerce-for-paygent-payment-main' ),
					'15' => '15' . __( 'times', 'woocommerce-for-paygent-payment-main' ),
					'18' => '18' . __( 'times', 'woocommerce-for-paygent-payment-main' ),
					'20' => '20' . __( 'times', 'woocommerce-for-paygent-payment-main' ),
					'24' => '24' . __( 'times', 'woocommerce-for-paygent-payment-main' ),
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
			'no_tds_card'          => array(
				'title'       => __( 'Accept No 3D Secure Card', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable No 3D Secure Card', 'woocommerce-for-paygent-payment-main' ),
				'default'     => 'no',
				'description' => __( 'If you check this, the card will be processed without 3D Secure.', 'woocommerce-for-paygent-payment-main' )
				. '<br />' . __( 'If you check this, the payment will go through, but the chargeback will be borne by the store.', 'woocommerce-for-paygent-payment-main' ),
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
		$this->attention_to_ie_user();

		// Check the tokens.
		$user_id = get_current_user_id();
		$tokens  = false;
		if ( 'yes' === $this->store_card_info && is_checkout() ) {
			?>
			<?php
			$tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, $this->id );
			if ( 'yes' === $this->store_card_info ) {
				$this->display_stored_user_data( $tokens, $this->id );
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
			self::input_cardholder_name();
		}
		$this->paygent_token_js( $merchant_id, $token_key, $tokens, $this->id );

		// Show "save card" checkbox for logged-in users (not for subscriptions).
		$is_subscription = function_exists( 'wcs_cart_contains_subscription' ) && wcs_cart_contains_subscription();
		if ( 'yes' === $this->store_card_info && is_user_logged_in() && ! $is_subscription ) {
			echo '<p class="form-row form-row-wide">';
			echo '<label for="paygent_save_card_info">';
			echo '<input type="checkbox" id="paygent_save_card_info" name="paygent_save_card_info" value="yes" style="width:auto;margin-right:6px;">';
			echo esc_html__( 'Save payment information to my account for future purchases.', 'woocommerce-for-paygent-payment-main' );
			echo '</label>';
			echo '</p>';
		}

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
	 * UI - Payment Cardholder name fields for Paygent Payment.
	 */
	public static function input_cardholder_name() {
		$cardholder_name_label = __( 'Cardholder Name (Alphabetic characters only)', 'woocommerce-for-paygent-payment-main' );
		echo '<p class="form-row form-row-wide woocommerce-validated">
				<label for="paygent_cardholder_name">' . esc_html( $cardholder_name_label ) . '<span class="required">*</span></label>
				<input id="paygent_cardholder_name" class="input-text" pattern="[a-zA-Z\s]+" placeholder="Taro Yamada" name="paygent_cardholder_name">
			</p>';
	}

	/**
	 * Undocumented function
	 *
	 * @param string $merchant_id Merchant ID.
	 * @param string $token_key Token Key.
	 * @param object $tokens Tokens.
	 * @param string $id Payment Gateway ID.
	 * @return void
	 */
	public function paygent_token_js( $merchant_id, $token_key, $tokens, $id ) {
		echo '
            <input type="hidden" name="' . esc_attr( $id ) . '-token" id="' . esc_attr( $id ) . '-token" value="" />
            <input type="hidden" name="' . esc_attr( $id ) . '-valid_until" id="' . esc_attr( $id ) . '-valid_until" value="" />
            <input type="hidden" name="' . esc_attr( $id ) . '-masked_card_number" id="' . esc_attr( $id ) . '-masked_card_number" value="" />
            <input type="hidden" name="' . esc_attr( $id ) . '-cvc_token" id="' . esc_attr( $id ) . '-cvc_token" value="" />
            <input type="hidden" name="card_type" id="card_type" value="" />';
		echo '
            <script type="text/javascript">';
		if ( is_user_logged_in() && 'yes' === $this->store_card_info && ! empty( $tokens ) ) {// Set stored card.
			echo "
			document.getElementById('" . esc_attr( $id ) . "-stored-card-cvc').addEventListener('input', sendPaygentToken);";
		}
		echo '
            document.getElementById("' . esc_attr( $id ) . '-card-cvc").addEventListener("input", sendPaygentToken);
            document.getElementById("' . esc_attr( $id ) . '-card-number").addEventListener("input", sendPaygentToken);
            document.getElementById("' . esc_attr( $id ) . '-card-expiry").addEventListener("input", sendPaygentToken);
            // Definition of the send function. Processing when pressing send button of card information entry form.
            function sendPaygentToken() {
                var paygent_card_number = document.getElementById("' . esc_attr( $id ) . '-card-number").value;
                paygent_card_number = paygent_card_number.replace(/ /g, "");
                var paygent_cvc = document.getElementById("' . esc_attr( $id ) . '-card-cvc").value;
		';
		if ( is_user_logged_in() && 'yes' === $this->store_card_info && ! empty( $tokens ) ) {// Set CVC.
			echo "
			var paygent_stored_cvc = document.getElementById('" . esc_attr( $id ) . "-stored-card-cvc').value;
			if(paygent_cvc.length < 3 && paygent_stored_cvc.length < 3){
				return false;
			}else if(paygent_stored_cvc.length == 0){
				paygent_stored_cvc = paygent_cvc;
			}";
		} else {
			echo '
			var paygent_stored_cvc = paygent_cvc;';
		}
		echo "
			var exp_my = document.getElementById('" . esc_attr( $id ) . "-card-expiry').value ;
			exp_my = exp_my.replace(/ /g, '');
			exp_my = exp_my.replace('/', '');
			var paygent_exp_m = exp_my.substr(0,2);
			var paygent_exp_y = exp_my.substr(2,2);
			var paygentToken = new PaygentToken(); //Generate PaygentToken objects
			// Execute the token generation method.
			paygentToken.createCvcToken(
				" . esc_js( $merchant_id ) . ", //First argument: Merchant ID
				'" . esc_js( $token_key ) . "', //Second argument: Token generation key
				{ //Third argument: Credit card information
					cvc:paygent_stored_cvc, //cvc
				},execCVCToken //Fourth argument: Callback function (executed after token acquisition)
			);
			// Execute the token generation method.
			paygentToken.createToken(
				" . esc_js( $merchant_id ) . ", //First argument: Merchant ID
				'" . esc_js( $token_key ) . "', //Second argument: Token generation key
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
        let paygent_card_number = document.getElementById('" . esc_attr( $id ) . "-card-number').value;
        paygent_card_number = paygent_card_number.replace(/ /g, '');
        let token = document.getElementById('" . esc_attr( $id ) . "-token').value;
        const place_order = jQuery('#place_order');
        document.getElementById('" . esc_attr( $id ) . "-card-number').removeAttribute('name');
        document.getElementById('" . esc_attr( $id ) . "-card-expiry').removeAttribute('name');
        document.getElementById('" . esc_attr( $id ) . "-card-cvc').removeAttribute('name');
        //form.name.removeAttribute(\'name\');
        // Set token that was responded from createToken () to the hidden item token which we made in advance.
        document.getElementById('" . esc_attr( $id ) . "-token').value = response.tokenizedCardObject.token;
        document.getElementById('" . esc_attr( $id ) . "-masked_card_number').value = response.tokenizedCardObject.masked_card_number;
        document.getElementById('" . esc_attr( $id ) . "-valid_until').value = response.tokenizedCardObject.valid_until;
        if(token != ''){
            place_order.prop('disabled', false);
        }
        let card_check1 = paygent_card_number.slice(0,1);
        let card_check2 = paygent_card_number.slice(0,2);
        if(card_check1 == 4){
            document.getElementById('card_type').value = 'visa';
        }else if(card_check2 == 51 || card_check2 == 52 || card_check2 == 53 || card_check2 == 54 || card_check2 == 55 || card_check2 == 22 || card_check2 == 23 || card_check2 == 24 || card_check2 == 25 || card_check2 == 26 || card_check2 == 27 || card_check2 == 21 || card_check2 == 59){//add 21 && 59 for test card
            document.getElementById('card_type').value = 'mastercard';
        }else if(card_check2 == 30 || card_check2 == 36 || card_check2 == 38 || card_check2 == 39){
            document.getElementById('card_type').value = 'diners';
        }else if(card_check2 == 60 || card_check2 == 64 || card_check2 == 65 || card_check2 == 62){
            document.getElementById('card_type').value = 'discover';
        }else if(card_check2 == 34 || card_check2 == 37){
            document.getElementById('card_type').value = 'american express';
        }else if(card_check2 == 35 || card_check2 == 31 ){//add 31 for test card
            document.getElementById('card_type').value = 'jcb';
        }
	}
}
// Definition of callback function. Processing after token acquisition
function execCVCToken(response) {
	if (response.result == '0000') { //When the result of the token processing is normal.
		var token = document.getElementById('" . esc_attr( $id ) . "-cvc_token').value;
		// Set token that was responded from createToken () to the hidden item token which we made in advance.
		document.getElementById('" . esc_attr( $id ) . "-cvc_token').value = response.tokenizedCardObject.token;
		if(token != ''){
			jQuery('#place_order').prop('disabled', false);
		}
	}
}

jQuery(function(){
	jQuery('#place_order').focus(function (){
		jQuery('#" . esc_attr( $id ) . "-card-number').prop('disabled', true);
		jQuery('#" . esc_attr( $id ) . "-card-expiry').prop('disabled', true);
		jQuery('#" . esc_attr( $id ) . "-card-cvc').prop('disabled', true);
	});
	jQuery('#place_order').blur(function (){
		jQuery('#" . esc_attr( $id ) . "-card-number').prop('disabled', false);
		jQuery('#" . esc_attr( $id ) . "-card-expiry').prop('disabled', false);
		jQuery('#" . esc_attr( $id ) . "-card-cvc').prop('disabled', false);
	});
	jQuery(\"input[name='paygent-use-stored-payment-info']:radio\").change( function(){
		jQuery('#" . esc_attr( $id ) . "-card-number').val('');
		jQuery('#" . esc_attr( $id ) . "-card-expiry').val('');
		jQuery('#" . esc_attr( $id ) . "-card-cvc').val('');
		jQuery('#" . esc_attr( $id ) . "-stored-card-cvc').val('');
		jQuery('#" . esc_attr( $id ) . "-token').val('');
	});
});
</script>
";
	}

	/**
	 * Process the payment && return the result.
	 *
	 * @param  int  $order_id Order ID.
	 * @param  bool $subscription Subscription.
	 * @return  mixed
	 */
	public function process_payment( $order_id, $subscription = false ) {
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
		$telegram_kind = '020';// Auth.

		$send_data['payment_id']        = '';
		$send_data['security_code_use'] = 1;
		$send_data['3dsecure_ryaku']    = 1;

		$send_data['payment_amount'] = $order->get_total();

		// get Token Data.
		$card_token     = $this->jp4wc_framework->get_post( 'paygent_cc-token' );
		$card_cvc_token = $this->jp4wc_framework->get_post( 'paygent_cc-cvc_token' );
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

		// Save user's card-save preference to meta (needed for 3DS2 callback which has no POST data).
		$user_wants_save_card = ( true === $subscription ) || ( 'yes' === sanitize_text_field( wp_unslash( $_POST['paygent_save_card_info'] ?? '' ) ) );// phpcs:ignore
		$order->update_meta_data( '_paygent_save_card_preference', $user_wants_save_card ? '1' : '0' );
		$order->save_meta_data();

		// Card information deposit function without EMV-3DS.
		$using_stored_card = ( $this->jp4wc_framework->get_post( 'paygent-use-stored-payment-info' ) === 'yes' );
		$set_login         = false;
		if ( is_user_logged_in() && ( $user_wants_save_card || $using_stored_card || true === $subscription ) ) {
			$set_login = true;
			if ( $using_stored_card ) {
				$send_data['customer_card_id'] = $this->jp4wc_framework->get_post( 'stored-info' );
			} else {
				$stored_user_card_data         = $this->add_stored_user_data( $card_user_id, $card_token, $this->test_mode, $this->debug, $order );
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

		$send_data = $this->set_stored_card( $card_user_id, $card_token, $card_cvc_token, $send_data, $set_login );

		// Set Number of Payments && Payment times.
		$send_data = $this->set_payment_time_number( $order, $send_data );

		$send_data['term_url'] = $this->get_return_url( $order );
		// 3D Secure 2.0 Setting.
		if ( 'yes' === $this->tds2_check ) {
			$telegram_kind = '450';// 3D Secure 2.0 Challenge Flow.
			$send_data     = $this->set_send_data_for_tds2( $send_data, $this->merchant_name, $order );
		}
		$response = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );

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
				$telegram_kind = '022';
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
	 * Set Number of Payments && Payment times
	 *
	 * @param object $order WC_Order.
	 * @param array  $send_data Send Data.
	 * @return array
	 */
	public function set_payment_time_number( $order, $send_data ) {
		// Set Number of Payments.
		if ( $this->jp4wc_framework->get_post( 'number_of_payments' ) ) {
			$number_of_payments = $this->jp4wc_framework->get_post( 'number_of_payments' );
		} else {
			$number_of_payments = '109';
		}
		// Payment times.
		if ( '109' === $number_of_payments ) {
			$send_data['payment_class'] = 10;// One time payment.
			// translators: %s is the payment method name.
			$order->add_order_note( sprintf( __( 'Payment Number is %s', 'woocommerce-for-paygent-payment-main' ), __( '1 time payment', 'woocommerce-for-paygent-payment-main' ) ) );
		} elseif ( '239' === $number_of_payments ) {
			$send_data['payment_class'] = 23;// Bonus One time payment.
			// translators: %s is the payment method name.
			$order->add_order_note( sprintf( __( 'Payment Number is %s', 'woocommerce-for-paygent-payment-main' ), __( 'Bonus One time', 'woocommerce-for-paygent-payment-main' ) ) );
		} elseif ( '809' === $number_of_payments ) {
			$send_data['payment_class'] = 80;// Revolving payment payment.
			// translators: %s is the payment method name.
			$order->add_order_note( sprintf( __( 'Payment Number is %s', 'woocommerce-for-paygent-payment-main' ), __( 'Revolving payment', 'woocommerce-for-paygent-payment-main' ) ) );
		} else {
			$send_data['payment_class'] = 61;// Installment payment.
			$send_data['split_count']   = $number_of_payments;
			// translators: %1$d is the number of payments, %2$s is the payment method name.
			$order->add_order_note( sprintf( __( 'Payment Number is %1$d times of %2$s', 'woocommerce-for-paygent-payment-main' ), $number_of_payments, __( 'Installment payment', 'woocommerce-for-paygent-payment-main' ) ) );
		}
		if ( isset( $send_data['payment_class'] ) ) {
			$order->add_meta_data( '_payment_class', $send_data['payment_class'] );
		}
		if ( isset( $send_data['split_count'] ) ) {
			$order->add_meta_data( '_split_count', $send_data['split_count'] );
		}
		$order->save_meta_data();

		return $send_data;
	}

	/**
	 * Set send_data for stored card
	 *
	 * @param string $card_user_id User ID.
	 * @param string $card_token Card Token.
	 * @param string $card_cvc_token Card CVC Token.
	 * @param array  $send_data Send Data.
	 * @param bool   $set_login Set Login.
	 *
	 * @return array $send_data Send Data.
	 */
	public function set_stored_card( $card_user_id, $card_token, $card_cvc_token, $send_data, $set_login ) {
		if ( $set_login ) {
			$send_data['security_code_token'] = 1;
			$send_data['card_token']          = $card_cvc_token;
			$send_data['stock_card_mode']     = 1;
			$send_data['customer_id']         = $card_user_id;
		} else {
			// Credit Card Token Information.
			$send_data['card_token'] = $card_token;
		}
		return $send_data;
	}

	/**
	 * Add payer information for 3D Secure 2.0.
	 *
	 * @param array  $send_data Send Data.
	 * @param string $merchant_name Merchant Name.
	 * @param object $order WC_Order.
	 *
	 * @return array $send_data
	 */
	public function set_send_data_for_tds2( $send_data, $merchant_name, $order ) {
		unset( $send_data['3dsecure_ryaku'] );
		$send_data['http_accept']         = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';
		$send_data['http_user_agent']     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$send_data['term_url']            = $order->get_checkout_payment_url( true );
		$send_data['merchant_name']       = $merchant_name;
		$send_data['authentication_type'] = '01';
		// Specify card information.
		if ( isset( $send_data['customer_card_id'] ) ) {
			$send_data['card_set_method'] = 'customer';
			unset( $send_data['card_token'] );
		} else {
			$send_data['card_set_method'] = 'token';
		}
		// Adding Items in Risk-Based Authentication.
		$send_data['cardholder_name'] = $this->jp4wc_framework->get_post( 'paygent_cardholder_name' );
		// Login related information.
		if ( is_user_logged_in() ) {
			$send_data['login_type'] = '02';
			$user                    = wp_get_current_user();
			$last_login_date         = get_user_meta( $user->ID, 'wc_last_active', true );
			$today                   = date_i18n( 'Y-m-d H:i' );
			$today                   = strtotime( $today );
			if ( ! empty( $last_login_date ) ) {
				$send_data['login_date'] = date_i18n( 'YmdHi', $last_login_date );
			}
			$last_update_date = get_user_meta( $user->ID, 'last_update', true );
			if ( ! empty( $last_update_date ) ) {
				$send_data['account_change_date'] = date_i18n( 'Ymd', $last_update_date );
				$update_diff                      = ( $today - $last_update_date ) / ( 60 * 60 * 24 );
				if ( 0 === $update_diff ) {
					$send_data['account_change_indicator'] = '01';
				} elseif ( 0 < $update_diff && 30 > $update_diff ) {
					$send_data['account_change_indicator'] = '02';
				} elseif ( 30 <= $update_diff && 60 > $update_diff ) {
					$send_data['account_change_indicator'] = '03';
				} elseif ( 60 <= $update_diff ) {
					$send_data['account_change_indicator'] = '04';
				}
			}
			$registered_date      = strtotime( $user->user_registered );
			$password_update_date = get_the_author_meta( 'jp4wc_password_update', $user->ID );
			if ( ! empty( $password_update_date ) ) {
				$send_data['password_change_date'] = date_i18n( 'Ymd', $password_update_date );
				$password_diff                     = ( $today - $password_update_date ) / ( 60 * 60 * 24 );
				if ( 0 === $password_diff ) {
					$send_data['password_change_indicator'] = '02';
				} elseif ( $password_diff > 0 && $password_diff < 30 ) {
					$send_data['password_change_indicator'] = '03';
				} elseif ( $password_diff >= 30 && $password_diff < 60 ) {
					$send_data['password_change_indicator'] = '04';
				} elseif ( $password_diff >= 60 ) {
					$send_data['password_change_indicator'] = '05';
				} else {
					$send_data['password_change_indicator'] = '01';
				}
			}
			$send_data['account_create_date'] = date_i18n( 'Ymd', $registered_date );
		} else {
			$send_data['login_type'] = '01';
		}
		// Customer address information.
		// Billing address.
		if ( $order->get_billing_city() ) {
			$send_data['bill_address_city'] = $order->get_billing_city();
		}
		if ( 'JP' === $order->get_billing_country() ) {
			$send_data['bill_address_country'] = 392;
		}
		if ( $order->get_billing_address_1() ) {
			$send_data['bill_address_line1'] = $order->get_billing_address_1();
		}
		if ( $order->get_billing_address_2() ) {
			$send_data['bill_address_line2'] = $order->get_billing_address_2();
		}
		if ( $order->get_billing_postcode() ) {
			$send_data['bill_address_post_code'] = $order->get_billing_postcode();
		}
		if ( $order->get_billing_state() ) {
			$send_data['bill_address_state'] = preg_replace( '/[^0-9]/', '', $order->get_billing_state() );
		}
		if ( $order->get_billing_email() ) {
			$send_data['email_address'] = $order->get_billing_email();
		}
		if ( $order->get_billing_phone() && $order->get_billing_country() === 'JP' ) {
			$send_data['home_phone_cc']         = 81;
			$send_data['home_phone_subscriber'] = ltrim( preg_replace( '/[^0-9]/', '', $order->get_billing_phone() ), '0' );
		}
		// Shipping address.
		if ( $order->needs_shipping_address() ) {
			if ( $order->get_shipping_city() ) {
				$send_data['ship_address_city'] = $order->get_shipping_city();
			}
			if ( $order->get_shipping_country() === 'JP' ) {
				$send_data['ship_address_country'] = 392;
			}
			if ( $order->get_shipping_address_1() ) {
				$send_data['ship_address_line1'] = $order->get_shipping_address_1();
			}
			if ( $order->get_shipping_address_2() ) {
				$send_data['ship_address_line2'] = $order->get_shipping_address_2();
			}
			if ( $order->get_shipping_postcode() ) {
				$send_data['ship_address_post_code'] = $order->get_shipping_postcode();
			}
			if ( $order->get_shipping_state() ) {
				$send_data['ship_address_state'] = preg_replace( '/[^0-9]/', '', $order->get_billing_state() );
			}
		}
		if ( $order->has_billing_address() && $order->has_shipping_address() && $order->get_formatted_billing_address() === $order->get_formatted_shipping_address() ) {
			$send_data['shipping_indicator'] = '01';
		}
		return $send_data;
	}

	/**
	 * Process 3D Secure payment after authentication
	 *
	 * @param object $order WC_Order.
	 * @param string $paymentaction Payment action (sale or authorization).
	 * @param string $store_card_info Whether to store card info.
	 * @param string $telegram_kind Telegram kind setting.
	 * @param string $test_mode Test mode setting.
	 * @param string $debug Debug mode setting.
	 * @param string $currency_code Currency code.
	 * @return mixed int or bool
	 */
	public function paygent_tds_proceed_payment( $order, $paymentaction, $store_card_info, $telegram_kind, $test_mode, $debug, $currency_code = 'JPY' ) {
		$order_id     = $order->get_id();
		$order        = wc_get_order( $order_id );
		$prefix_order = get_option( 'wc-paygent-prefix_order' );
		if ( $prefix_order ) {
			$send_data['trading_id'] = $prefix_order . $order_id;
		} else {
			$send_data['trading_id'] = 'wc_' . $order_id;
		}
		if ( 'JPY' !== $currency_code ) {
			$send_data['currency_code'] = $currency_code;
		}
		unset( $send_data['card_token'] );
		$send_data['payment_id']        = '';
		$send_data['3dsecure_use_type'] = 2;
		$send_data['security_code_use'] = 1;
		$send_data['3ds_auth_id']       = wc_clean( wp_unslash( $_GET['3ds_auth_id'] ) );// phpcs:ignore
		$payment_class                  = $order->get_meta( '_payment_class' );
		if ( $payment_class ) {
			$send_data['payment_class'] = $payment_class;
		}
		if ( 'sale' === $paymentaction && isset( $paymentaction ) ) {
			$send_data['sales_mode'] = 1;
		}
		$split_count = $order->get_meta( '_split_count' );
		if ( $split_count ) {
			$send_data['split_count'] = $split_count;
		}
		$card_token       = $order->get_meta( '_paygent_card_token' );
		$card_cvc_token   = $order->get_meta( '_paygent_card_cvc_token' );
		$customer_card_id = $order->get_meta( '_paygent_customer_card_id' );
		$set_login        = false;
		if ( $customer_card_id && 'yes' === $store_card_info ) {
			$set_login                     = true;
			$send_data['customer_card_id'] = $customer_card_id;
		}
		$card_user_id = 'wc' . $order->get_user_id();
		$send_data    = $this->set_stored_card( $card_user_id, $card_token, $card_cvc_token, $send_data, $set_login );

		$send_data['payment_amount'] = $order->get_total();
		$proceed_response            = $this->paygent_request->send_paygent_request( $test_mode, $order, $telegram_kind, $send_data, $debug );
		if ( isset( $proceed_response['result'] ) && $proceed_response['result_array'] ) {
			if ( '0' === $proceed_response['result'] ) {
				// set transaction id for Paygent Order Number.
				$order->payment_complete( wc_clean( $proceed_response['result_array'][0]['payment_id'] ) );
			}
			return $proceed_response['result'];
		} else {
			$this->paygent_request->error_response( $proceed_response, $order );
			return false;
		}
	}

	/**
	 * Add stored card information for 3D Secure 2.0
	 *
	 * @param string $user_id    User ID to store the card for.
	 * @param string $card_token Token representing the card to store.
	 * @param object $order      Order object.
	 * @return mixed Result from add_stored_user_data call
	 */
	public function paygent_tds_add_stored_card( $user_id, $card_token, $order ) {
		$card_user_id = 'wc' . $user_id;
		$result       = $this->add_stored_user_data( $card_user_id, $card_token, $this->test_mode, $this->debug, $order );
		return $result;
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
				$user_wants_save_card = '1' === $order->get_meta( '_paygent_save_card_preference' );
				if ( 'yes' === $this->store_card_info && $user_wants_save_card ) {
					$card_token = $order->get_meta( '_paygent_card_token' );
					$user_id    = $order->get_user_id();
					if ( false === $order->get_meta( '_paygent_customer_card_id' ) ) {
						$add_card_result = $this->paygent_tds_add_stored_card( $user_id, $card_token, $order );
						if ( false === $add_card_result ) {
							$order->add_order_note( __( 'Failed to store card information.', 'woocommerce-for-paygent-payment-main' ) );
						}
					}
				}

				$result = $this->paygent_tds_proceed_payment( $order, $this->paymentaction, $this->store_card_info, '020', $this->test_mode, $this->debug );
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
	 * No 3D Secure 2.0 Card Response
	 *
	 * @param object $order WC_Order.
	 * @return void
	 */
	public function paygent_no_tds_card_response( $order ) {
		if ( ! empty( $this->attempt_notice_email ) ) {
			$to       = $this->attempt_notice_email;
			$subject  = __( 'Notion: Attempt order#', 'woocommerce-for-paygent-payment-main' ) . $order->get_id();
			$message  = __( 'This payment uses a card that does not have 3D Secure.', 'woocommerce-for-paygent-payment-main' );
			$message .= __( 'This order is a caution && is not eligible for cashback. Please be careful about shipping etc.', 'woocommerce-for-paygent-payment-main' );
			wc_mail( $to, $subject, $message );
		}
		if ( 'yes' === $this->no_tds_card ) {
			wc_increase_stock_levels( $order->get_id() );
			$order->update_status( 'cancelled', __( 'No 3D Secure 2.0 card.', 'woocommerce-for-paygent-payment-main' ) );
			wc_add_notice(
				__( 'This payment uses a card that does not have 3D Secure.', 'woocommerce-for-paygent-payment-main' )
				. ' ' . __( 'Please use a card that supports 3D Secure.', 'woocommerce-for-paygent-payment-main' ),
				'error'
			);
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
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
	 * Make the Error message by response data
	 *
	 * @return void
	 */
	private function attention_to_ie_user() {
		echo '<span style="color:#ff0000; font-weight:bold;">';
		// Attention display for IE users.
		$browser = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';
		if ( isset( $browser ) && strstr( $browser, 'edge' ) ) {
			echo '';
		} elseif ( strstr( $browser, 'trident' ) || strstr( $browser, 'msie' ) ) {
			esc_html_e( 'If you use IE, this payment may fail. If this payment is not successful, please try a browser such as Edge.', 'woocommerce-for-paygent-payment-main' );
		}
		echo '</span>';
	}

	/**
	 * Make the Error message by response data
	 *
	 * @param  array $response_data Response Data.
	 * @return  string
	 */
	public function make_error_message( $response_data ) {
		if ( 'P009' === $response_data['responseCode'] ) {// Number of digits error.
			if ( strpos( $response_data['responseDetail'], 'card_conf_number' ) !== false ) {
				// translators: %s is the field name (e.g., Security code).
				$error_message = sprintf( __( 'The number of digits of %s is invalid.', 'woocommerce-for-paygent-payment-main' ), __( 'Security code', 'woocommerce-for-paygent-payment-main' ) );
			} elseif ( strpos( $response_data['responseDetail'], 'card_valid_term' ) !== false ) {
				// translators: %s is the field name (e.g., Expiration date).
				$error_message = sprintf( __( 'The number of digits of %s is invalid.', 'woocommerce-for-paygent-payment-main' ), __( 'Expiration date', 'woocommerce-for-paygent-payment-main' ) ) . __( 'Please enter the expiration date in 4 digits of the month / year. Example) In the case of November 2018 it will be 11/18.', 'woocommerce-for-paygent-payment-main' );
			} elseif ( strpos( $response_data['responseDetail'], 'card_number' ) !== false ) {
				// translators: %s is the field name (e.g., Credit Card Number).
				$error_message = sprintf( __( 'The number of digits of %s is invalid.', 'woocommerce-for-paygent-payment-main' ), __( 'Credit Card Number', 'woocommerce-for-paygent-payment-main' ) );
			} else {
				$error_message = __( 'Parameter value has illegal number of digits.', 'woocommerce-for-paygent-payment-main' );
			}
		} elseif ( 'P010' === $response_data['responseCode'] ) {
			if ( strpos( $response_data['responseDetail'], 'card_conf_number' ) !== false ) {
				// translators: %s is the field name (e.g., Security code).
				$error_message = sprintf( __( '%s is an invalid value.', 'woocommerce-for-paygent-payment-main' ), __( 'Security code', 'woocommerce-for-paygent-payment-main' ) );
			} elseif ( strpos( $response_data['responseDetail'], 'card_valid_term' ) !== false ) {
				// translators: %s is the field name (e.g., Expiration date).
				$error_message = sprintf( __( '%s is an invalid value.', 'woocommerce-for-paygent-payment-main' ), __( 'Expiration date', 'woocommerce-for-paygent-payment-main' ) ) . __( 'The expiration date is too old or too future.', 'woocommerce-for-paygent-payment-main' ) . __( 'Please enter the expiration date in 4 digits of the month / year. Example) In the case of November 2018 it will be 11/18.', 'woocommerce-for-paygent-payment-main' );
			} elseif ( strpos( $response_data['responseDetail'], 'card_number' ) !== false ) {
				// translators: %s is the field name (e.g., Credit Card Number).
				$error_message = sprintf( __( '%s is an invalid value.', 'woocommerce-for-paygent-payment-main' ), __( 'Credit Card Number', 'woocommerce-for-paygent-payment-main' ) );
			} else {
				$error_message = __( 'The value of the parameter is an invalid value.', 'woocommerce-for-paygent-payment-main' );
			}
		} else {
			$error_message = mb_convert_encoding( $response_data['responseDetail'], 'UTF-8', 'SJIS' );
		}
		return $error_message;
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
		 * Add payment method at my account page
		 *
		 * @return array
		 */
	public function add_payment_method() {
		$nonce_value = wc_get_var( $_REQUEST['woocommerce-add-payment-method-nonce'], wc_get_var( $_REQUEST['_wpnonce'], '' ) ); // @codingStandardsIgnoreLine.
		if ( isset( $_POST['payment_method'] ) && $_POST['payment_method'] === $this->id && wp_verify_nonce( $nonce_value, 'woocommerce-add-payment-method' ) ) {
			$user       = wp_get_current_user();
			$user_id    = 'wc' . $user->ID;
			$order      = null;
			$card_token = $this->jp4wc_framework->get_post( 'paygent_cc-token' );

			$result = $this->add_stored_user_data( $user_id, $card_token, $this->test_mode, $this->debug, $order );
			if ( $result ) {
				return array(
					'result'   => 'success',
					'redirect' => wc_get_endpoint_url( 'payment-methods' ),
				);
			} else {
				return array(
					'result'   => 'failure',
					'redirect' => wc_get_endpoint_url( 'payment-methods' ),
				);
			}
		}
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
				$telegram_kind           = '022';
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
	 * Display payment method in Payment page when user have stored card data
	 *
	 * @param  array $tokens Token data.
	 * @param  int   $id     User ID.
	 */
	public function display_stored_user_data( $tokens, $id ) {
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
			<label for="<?php echo esc_attr( $id ); ?>-stored-card-cvc"><?php echo esc_html__( 'Card code', 'woocommerce-for-paygent-payment-main' ); ?><span class="required">*</span></label>
			<input id="<?php echo esc_attr( $id ); ?>-stored-card-cvc" class="input-text wc-credit-card-form-card-cvc" inputmode="numeric" autocomplete="off" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" maxlength="4" placeholder="CVC" name="<?php echo esc_attr( $id ); ?>-stored-card-cvc" style="width:100px">
		</p>
		</fieldset>
		<fieldset>
		<input type="radio" name="paygent-use-stored-payment-info" id="paygent-use-stored-payment-info-no" value="no" onclick="document.getElementById('paygent-stored-info').style.display='none'; document.getElementById('paygent-new-info').style.display='block'"; />
		<label for="paygent-use-stored-payment-info-no"  style="display: inline;"><?php esc_html_e( 'Use a new payment method', 'woocommerce-for-paygent-payment-main' ); ?></label>
		</fieldset>
			<?php } else { ?>
		<div id="error"></div>
		<!-- Show input boxes for new data -->
				<?php
			}
	}

	/**
	 * Add User card info to Paygent server && Token system in WooCommerce
	 *
	 * @param string $user_id      User ID.
	 * @param string $card_token   Card Token.
	 * @param bool   $test_mode    Test mode.
	 * @param bool   $debug        Debug mode.
	 * @param object $order WC_Order.
	 * @return mixed
	 */
	public function add_stored_user_data( $user_id, $card_token, $test_mode, $debug, $order = null ) {
		$telegram_kind = '025';
		$send_data     = array(
			'trading_id'      => '',
			'customer_id'     => $user_id,
			'valid_check_flg' => '0',
		);

		// Check && Set site id.
		if ( '1' !== $this->paygent_request->site_id ) {
			$send_data['site_id'] = $this->paygent_request->site_id;
		}

		if ( isset( $card_token ) ) {
			$send_data['card_token'] = $card_token;
		} else {
			wc_add_notice( __( 'Input information of the credit card is not enough.', 'woocommerce-for-paygent-payment-main' ), 'error' );
			return false;
		}
		$result = $this->paygent_request->send_paygent_request( $test_mode, $order, $telegram_kind, $send_data, $debug );
		if ( '1' === $result['result'] ) {
			if ( ! is_null( $order ) ) {
				$order->add_order_note( __( 'Card information input error. Fault to stored your card info.', 'woocommerce-for-paygent-payment-main' ) . $result['responseCode'] . ':' . mb_convert_encoding( $result['responseDetail'], 'UTF-8', 'SJIS' ) );
			}
			$error_message = $this->make_error_message( $result );
			wc_add_notice( $error_message . __( 'Card information input error. Fault to stored your card info.', 'woocommerce-for-paygent-payment-main' ), 'error' );
			return false;
		} else {
			if ( ! is_null( $order ) ) {
				$order->add_order_note( __( 'Stored card info.', 'woocommerce-for-paygent-payment-main' ) . ' Customer Card Id : ' . $result['result_array'][0]['customer_card_id'] );
			}
			$customer_card_id = $result['result_array'][0]['customer_card_id'];
			$card_last4       = substr( $result['result_array'][0]['masked_card_number'], -4 );
			$expiry_month     = substr( $result['result_array'][0]['card_valid_term'], 0, 2 );
			$expiry_year      = substr( $result['result_array'][0]['card_valid_term'], -2 );
			if ( $order ) {
				$card_type = $order->get_meta( '_paygent_card_type', true );
			} else {
				$card_type = $this->jp4wc_framework->get_post( 'card_type' );
			}
			// Set && save token to WooCommerce.
			$token = new WC_Payment_Token_CC();
			$token->set_default( true );
			$token->set_token( $card_token );
			$token->set_gateway_id( $this->id );
			$token->set_last4( $card_last4 );
			$token->set_card_type( $card_type );
			$token->set_expiry_month( $expiry_month );
			$token->set_expiry_year( '20' . $expiry_year );
			$token->set_user_id( get_current_user_id() );
			$token->add_meta_data( 'customer_card_id', $customer_card_id );
			$token->save();
			if ( ! is_null( $order ) ) {
				$order->add_meta_data( '_paygent_customer_card_id', $customer_card_id );
				$order->save();
			}
		}
		return $result;
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
		$card_token          = $this->jp4wc_framework->get_post( 'paygent_cc-token' );
		$card_cvc_token      = $this->jp4wc_framework->get_post( 'paygent_cc-cvc_token' );
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
	 * Register the amount increase meta box for CC orders.
	 *
	 * @param string|WC_Order $post_type_or_order Post type string or WC_Order (HPOS).
	 * @param WP_Post|null    $post               WP_Post object or null.
	 */
	public function paygent_cc_add_increase_meta_box( $post_type_or_order, $post = null ) {
		$order = null;
		if ( $post_type_or_order instanceof WC_Order ) {
			$order = $post_type_or_order;
		} elseif ( $post && isset( $post->ID ) ) {
			$order = wc_get_order( $post->ID );
		}
		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return;
		}
		$hpos_enabled = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled();
		$screen       = $hpos_enabled ? 'woocommerce_page_wc-orders' : 'shop_order';
		add_meta_box(
			'paygent-cc-increase-amount',
			__( 'Paygent CC - Amount Correction (028/029)', 'woocommerce-for-paygent-payment-main' ),
			array( $this, 'paygent_cc_increase_amount_meta_box' ),
			$screen,
			'side',
			'default'
		);
	}

	/**
	 * Render the amount increase meta box HTML.
	 */
	public function paygent_cc_increase_amount_meta_box() {
		if ( isset( $_GET['post'] ) ) {// phpcs:ignore
			$order_id = absint( $_GET['post'] );// phpcs:ignore
		} elseif ( isset( $_GET['id'] ) ) {// phpcs:ignore
			$order_id = absint( $_GET['id'] );// phpcs:ignore
		} else {
			return;
		}
		$order       = wc_get_order( $order_id );
		$order_total = $order->get_total();
		$currency    = get_woocommerce_currency_symbol( $order->get_currency() );
		$tds2_used   = ! empty( $order->get_meta( '_3ds_auth_id' ) );

		wp_nonce_field( 'paygent_cc_increase_amount_' . $order_id, 'paygent_cc_increase_nonce' );
		echo '<input type="hidden" id="paygent_cc_increase_order_id" value="' . esc_attr( $order_id ) . '">';

		echo '<p><strong>' . esc_html__( 'Current total:', 'woocommerce-for-paygent-payment-main' ) . '</strong> ' . esc_html( $currency . number_format( $order_total, 0 ) ) . '</p>';

		if ( $tds2_used ) {
			echo '<p style="color:#c00;border:1px solid #c00;padding:6px 8px;border-radius:3px;"><strong>⚠ ' . esc_html__( '3D Secure orders: amount correction (028/029) is not supported by Paygent. This form has been disabled.', 'woocommerce-for-paygent-payment-main' ) . '</strong></p>';
		}

		echo '<p class="description">';
		echo esc_html__( 'Notes (from Paygent spec):', 'woocommerce-for-paygent-payment-main' ) . '<br>';
		echo '• ' . esc_html__( '3D Secure is not supported — prior 3DS orders will fail.', 'woocommerce-for-paygent-payment-main' ) . '<br>';
		echo '• ' . esc_html__( 'CVV check is skipped (value not retained by Paygent).', 'woocommerce-for-paygent-payment-main' ) . '<br>';
		echo '• ' . esc_html__( 'Card last-used date is not updated when using stored card.', 'woocommerce-for-paygent-payment-main' );
		echo '</p>';

		$disabled = $tds2_used ? ' disabled' : '';
		echo '<p>';
		echo '<label for="paygent_cc_new_amount"><strong>' . esc_html__( 'New total amount:', 'woocommerce-for-paygent-payment-main' ) . '</strong></label><br>';
		echo '<input type="number" id="paygent_cc_new_amount" min="1" step="1" style="width:100%;" placeholder="' . esc_attr( number_format( $order_total, 0, '', '' ) ) . '"' . $disabled . '>';// phpcs:ignore
		echo '</p>';

		echo '<button type="button" id="paygent_cc_increase_submit" class="button button-primary" style="width:100%;"' . $disabled . '>' // phpcs:ignore
			. esc_html__( 'Execute Amount Correction', 'woocommerce-for-paygent-payment-main' )
			. '</button>';

		echo '<div id="paygent_cc_increase_result" style="margin-top:8px;"></div>';
		?>
		<script type="text/javascript">
		jQuery( function( $ ) {
			$( '#paygent_cc_increase_submit' ).on( 'click', function() {
				var newAmount = $( '#paygent_cc_new_amount' ).val();
				if ( ! newAmount || isNaN( newAmount ) || parseFloat( newAmount ) <= 0 ) {
					$( '#paygent_cc_increase_result' ).html( '<span style="color:red;"><?php echo esc_js( __( 'Please enter a valid amount.', 'woocommerce-for-paygent-payment-main' ) ); ?></span>' );
					return;
				}
				$( '#paygent_cc_increase_submit' ).prop( 'disabled', true ).text( '<?php echo esc_js( __( 'Processing...', 'woocommerce-for-paygent-payment-main' ) ); ?>' );
				$.post( ajaxurl, {
					action:   'paygent_cc_increase_amount',
					order_id: $( '#paygent_cc_increase_order_id' ).val(),
					amount:   newAmount,
					nonce:    $( '#paygent_cc_increase_nonce' ).val()
				}, function( response ) {
					$( '#paygent_cc_increase_submit' ).prop( 'disabled', false ).text( '<?php echo esc_js( __( 'Execute Amount Correction', 'woocommerce-for-paygent-payment-main' ) ); ?>' );
					if ( response.success ) {
						$( '#paygent_cc_increase_result' ).html( '<span style="color:green;">' + $( '<span>' ).text( response.data ).html() + '</span>' );
					} else {
						$( '#paygent_cc_increase_result' ).html( '<span style="color:red;">' + $( '<span>' ).text( response.data ).html() + '</span>' );
					}
				} );
			} );
		} );
		</script>
		<?php
	}

	/**
	 * AJAX handler: send 028 (auth) or 029 (sale) with reduction_flag=0 for amount increase.
	 */
	public function paygent_cc_process_increase_amount() {
		check_ajax_referer( 'paygent_cc_increase_amount_' . absint( $_POST['order_id'] ?? 0 ), 'nonce' );// phpcs:ignore

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'woocommerce-for-paygent-payment-main' ) );
		}

		$order_id   = absint( $_POST['order_id'] ?? 0 );// phpcs:ignore
		$new_amount = isset( $_POST['amount'] ) ? (int) round( (float) wc_clean( wp_unslash( $_POST['amount'] ) ) ) : 0;// phpcs:ignore

		if ( $order_id <= 0 || $new_amount <= 0 ) {
			wp_send_json_error( __( 'Invalid order ID or amount.', 'woocommerce-for-paygent-payment-main' ) );
		}

		$order            = wc_get_order( $order_id );
		$transaction_id   = $order->get_transaction_id();
		$paygent_order_id = $order->get_meta( '_paygent_order_id' );
		$trading_id       = $paygent_order_id ? $paygent_order_id : 'wc_' . $order_id;

		// Query current payment status via 094.
		$check_data    = array(
			'payment_id' => $transaction_id,
			'trading_id' => $trading_id,
		);
		$status_result = $this->paygent_request->send_paygent_request( $this->test_mode, $order, '094', $check_data, $this->debug );
		if ( '0' !== $status_result['result'] || empty( $status_result['result_array'][0]['payment_status'] ) ) {
			wp_send_json_error( __( 'Failed to retrieve current payment status.', 'woocommerce-for-paygent-payment-main' ) );
		}

		$payment_status = $status_result['result_array'][0]['payment_status'];
		if ( '20' === $payment_status ) {
			$telegram_kind = '028';// Auth correction.
		} elseif ( '40' === $payment_status ) {
			$telegram_kind = '029';// Sale correction.
		} else {
			$msg = sprintf(
				// translators: %s: payment status code.
				__( 'Amount correction is not available for payment status %s. Only status 20 (auth) or 40 (sale) is supported.', 'woocommerce-for-paygent-payment-main' ),
				$payment_status
			);
			wp_send_json_error( $msg );
		}

		$send_data = array(
			'payment_id'     => $transaction_id,
			'trading_id'     => $trading_id,
			'payment_amount' => $new_amount,
			'reduction_flag' => '0', // Must be string: reqPut() uses loose == null check, integer 0 would be treated as null.
		);
		$result    = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );

		if ( '0' !== $result['result'] ) {
			$error_code   = isset( $result['responseCode'] ) ? $result['responseCode'] : '';
			$error_detail = isset( $result['responseDetail'] ) ? mb_convert_encoding( $result['responseDetail'], 'UTF-8', 'SJIS' ) : '';
			wp_send_json_error( __( 'Amount correction failed.', 'woocommerce-for-paygent-payment-main' ) . ' [' . $error_code . ': ' . $error_detail . ']' );
		}

		// Update WC order total to reflect the corrected amount.
		$old_total = $order->get_total();
		$order->set_total( $new_amount );

		// Update transaction ID if a new payment_id was returned.
		$old_payment_id = $transaction_id;
		$new_payment_id = $transaction_id;
		if ( ! empty( $result['result_array'][0]['payment_id'] ) ) {
			$new_payment_id = wc_clean( $result['result_array'][0]['payment_id'] );
			$order->set_transaction_id( $new_payment_id );
		}

		$order->save();

		// Always record an order note so the correction is visible in the order history.
		if ( $new_payment_id !== $old_payment_id ) {
			$note = sprintf(
				// translators: %1$s: telegram kind, %2$s: old amount, %3$s: new amount, %4$s: old payment_id, %5$s: new payment_id.
				__( 'Paygent amount correction (%1$s) succeeded. Amount: %2$s → %3$s. payment_id changed from %4$s to %5$s.', 'woocommerce-for-paygent-payment-main' ),
				$telegram_kind,
				number_format( (int) $old_total ),
				number_format( $new_amount ),
				$old_payment_id,
				$new_payment_id
			);
		} else {
			$note = sprintf(
				// translators: %1$s: telegram kind, %2$s: old amount, %3$s: new amount.
				__( 'Paygent amount correction (%1$s) succeeded. Amount: %2$s → %3$s.', 'woocommerce-for-paygent-payment-main' ),
				$telegram_kind,
				number_format( (int) $old_total ),
				number_format( $new_amount )
			);
		}
		$order->add_order_note( $note );

		$success_msg = sprintf(
			// translators: %1$s: telegram kind, %2$s: old amount, %3$s: new amount.
			__( 'Amount correction (%1$s) succeeded. Amount: %2$s → %3$s.', 'woocommerce-for-paygent-payment-main' ),
			$telegram_kind,
			number_format( (int) $old_total ),
			number_format( $new_amount )
		);
		wp_send_json_success( $success_msg );
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
		$telegram_array   = array(
			'auth_cancel' => '021',
			'sale_cancel' => '023',
			'auth_change' => '028',
			'sale_change' => '029',
		);
		$permit_statuses  = array(
			0 => array(
				'auth_cancel' => array( '20' ),
				'sale_cancel' => array( '40' ),
				'auth_change' => array( '20' ),
				'sale_change' => array( '40' ),
			),
		);
		$send_data_refund = array(
			'payment_amount' => $amount,
			'reduction_flag' => 1,
		);
		return $this->paygent_request->paygent_process_refund( $order_id, $amount, $telegram_array, $permit_statuses, $send_data_refund, $this );
	}

	/**
	 * Read Paygent Token javascript
	 */
	public function paygent_token_scripts_method() {
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
	 * Read Paygent Token javascript
	 *
	 * @param array $delete_card_data Delete Card Data.
	 */
	public function delete_card( $delete_card_data ) {
		$telegram_kind = '026';
		$order         = null;

		// Check && Set site id.
		if ( '1' !== $this->paygent_request->site_id ) {
			$delete_card_data['site_id'] = $this->paygent_request->site_id;
		}
		$delete_card_data['trading_id'] = '';

		$delete_card_res = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $delete_card_data, $this->debug );
		if ( '0' === $delete_card_res['result'] ) {
			// Set the remaining card as the default.
			$tokens = WC_Payment_Tokens::get_customer_tokens( get_current_user_id(), $this->id );
			if ( ! empty( $tokens ) ) {
				$default_token = reset( $tokens );
				$default_token->set_default( true );
				$default_token->save();
			}
		}
		return $delete_card_res;
	}

	/**
	 * Update Sale from Auth to Paygent System
	 *
	 * @param int $order_id Order ID.
	 */
	public function order_paygent_cc_status_completed( $order_id ) {
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
		$telegram_kind = '022';
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
	 * Get the error message by error code
	 *
	 * @param  string $error_code Error Code.
	 * @return  string
	 */
	public function tdsecure_requestor_error_codes( $error_code ) {
		$error_codes_message = array(
			'R1001' => __( 'Could not connect to system.', 'woocommerce-for-paygent-payment-main' ),
			'R1002' => __( 'The transaction has timed out.', 'woocommerce-for-paygent-payment-main' ),
			'R1003' => __( 'The transaction status is abnormal.', 'woocommerce-for-paygent-payment-main' ),
			'R1100' => __( 'A required item check error occurred.', 'woocommerce-for-paygent-payment-main' ),
			'R1101' => __( 'A length check error occurred.', 'woocommerce-for-paygent-payment-main' ),
			'R1102' => __( 'A report message check error occurred.', 'woocommerce-for-paygent-payment-main' ),
			'R1103' => __( 'The 3DS Merchant TransID is duplicated.', 'woocommerce-for-paygent-payment-main' ),
			'R2001' => __( 'Could not connect to system.', 'woocommerce-for-paygent-payment-main' ),
			'R2002' => __( 'The transaction has timed out.', 'woocommerce-for-paygent-payment-main' ),
			'R2003' => __( 'The transaction status is abnormal.', 'woocommerce-for-paygent-payment-main' ),
			'R2100' => __( 'A required item check error occurred.', 'woocommerce-for-paygent-payment-main' ),
			'R2101' => __( 'A length check error occurred.', 'woocommerce-for-paygent-payment-main' ),
			'R2102' => __( 'A report message check error occurred.', 'woocommerce-for-paygent-payment-main' ),
			'R3001' => __( 'Could not connect to system.', 'woocommerce-for-paygent-payment-main' ),
			'R3002' => __( 'The transaction has timed out.', 'woocommerce-for-paygent-payment-main' ),
			'R3003' => __( 'The transaction status is abnormal.', 'woocommerce-for-paygent-payment-main' ),
			'R3100' => __( 'A required item check error occurred.', 'woocommerce-for-paygent-payment-main' ),
			'R3101' => __( 'A length check error occurred.', 'woocommerce-for-paygent-payment-main' ),
			'R3102' => __( 'A report message check error occurred.', 'woocommerce-for-paygent-payment-main' ),
		);
		if ( isset( $error_codes_message[ $error_code ] ) ) {
			$message = $error_codes_message[ $error_code ];
		} else {
			$message = __( 'A system error has occurred.', 'woocommerce-for-paygent-payment-main' );
		}
		return $message;
	}

		/**
		 * Get the error message by error code
		 *
		 * @param  string $error_code Error Code.
		 * @return  string
		 */
	public function tdsecure_server_error_codes( $error_code ) {
		$error_codes_message = array(
			'101'  => __( 'The received message is invalid.', 'woocommerce-for-paygent-payment-main' ),
			'102'  => __( 'Unsupported message version number.', 'woocommerce-for-paygent-payment-main' ),
			'201'  => __( 'A required message element defined according to the specification is missing.', 'woocommerce-for-paygent-payment-main' ),
			'202'  => __( 'A critical message extension is not present.', 'woocommerce-for-paygent-payment-main' ),
			'203'  => __( 'A data element is not in the required format or has an invalid value defined according to the specification.', 'woocommerce-for-paygent-payment-main' ),
			'204'  => __( 'Duplicate data elements were found.', 'woocommerce-for-paygent-payment-main' ),
			'301'  => __( 'For receiving components, the received transaction ID is invalid.', 'woocommerce-for-paygent-payment-main' ),
			'302'  => __( 'Data encryption failed.', 'woocommerce-for-paygent-payment-main' ),
			'303'  => __( 'The API request endpoint is invalid. Please check the request URL.', 'woocommerce-for-paygent-payment-main' ),
			'304'  => __( 'The ISO code is invalid.', 'woocommerce-for-paygent-payment-main' ),
			'305'  => __( 'The transaction data is invalid.', 'woocommerce-for-paygent-payment-main' ),
			'306'  => __( 'Merchant category code is invalid.', 'woocommerce-for-paygent-payment-main' ),
			'402'  => __( 'The transaction has timed out.', 'woocommerce-for-paygent-payment-main' ),
			'403'  => __( 'The system crashed for a short period of time.', 'woocommerce-for-paygent-payment-main' ),
			'404'  => __( 'The system has permanently failed.', 'woocommerce-for-paygent-payment-main' ),
			'405'  => __( 'Could not connect to system.', 'woocommerce-for-paygent-payment-main' ),
			'1000' => __( 'An error occurred when communicating with the directory server.', 'woocommerce-for-paygent-payment-main' ),
			'1001' => __( 'The directory server for the specified provider could not be found.', 'woocommerce-for-paygent-payment-main' ),
			'1002' => __( 'An error occurred while saving the transaction.', 'woocommerce-for-paygent-payment-main' ),
			'1004' => __( 'Unhandled exception.', 'woocommerce-for-paygent-payment-main' ),
			'1011' => __( 'The merchant does not have a valid license.', 'woocommerce-for-paygent-payment-main' ),
			'1013' => __( 'The 3DS Server transaction ID is not recognized.', 'woocommerce-for-paygent-payment-main' ),
			'1014' => __( 'The 3DS Requestor\'s transaction ID is not recognized.', 'woocommerce-for-paygent-payment-main' ),
			'1016' => __( 'A required element is missing.', 'woocommerce-for-paygent-payment-main' ),
			'1020' => __( 'An error occurred during data transfer.', 'woocommerce-for-paygent-payment-main' ),
			'1021' => __( 'An error occurred while setting the requester\'s pre-trade ID. The pre-trade ID was not found.', 'woocommerce-for-paygent-payment-main' ),
			'1022' => __( 'The format of one or more elements is invalid according to the specification.', 'woocommerce-for-paygent-payment-main' ),
			'1026' => __( 'acquirerMerchantID/threeDSRequestorID is invalid.', 'woocommerce-for-paygent-payment-main' ),
			'1027' => __( 'Unsupported API version number.', 'woocommerce-for-paygent-payment-main' ),
			'2002' => __( 'The input is invalid.', 'woocommerce-for-paygent-payment-main' ),
			'2005' => __( 'Access denied.', 'woocommerce-for-paygent-payment-main' ),
			'2007' => __( 'Internal Server Error.', 'woocommerce-for-paygent-payment-main' ),
			'2009' => __( 'The session timed out.', 'woocommerce-for-paygent-payment-main' ),
		);
		if ( isset( $error_codes_message[ $error_code ] ) ) {
			$message = $error_codes_message[ $error_code ];
		} else {
			$message = __( 'Other exceptions.', 'woocommerce-for-paygent-payment-main' );
		}
		return $message;
	}

	/**
	 * Deletes a saved payment card from Paygent when it's removed from WooCommerce
	 *
	 * @param int              $token_id The ID of the token being deleted.
	 * @param WC_Payment_Token $token    The payment token object being deleted.
	 * @return void
	 */
	public function paygent_delete_card( $token_id, $token ) {
		$customer_card_id = $token->get_meta( 'customer_card_id' );
		$delete_card_data = array(
			'customer_id'      => 'wc' . get_current_user_id(),
			'customer_card_id' => $customer_card_id,
		);
		$delete_result    = $this->delete_card( $delete_card_data );
	}
}
