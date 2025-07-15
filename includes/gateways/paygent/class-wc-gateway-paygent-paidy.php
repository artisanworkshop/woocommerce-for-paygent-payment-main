<?php
/**
 * WooCommerce Paygent Paidy Gateway
 *
 * Provides a Paygent Paidy Payment Gateway integration for WooCommerce.
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
 * Class WC_Gateway_Paygent_Paidy
 *
 * Handles Paidy payments through Paygent payment gateway.
 *
 * @version     1.4.8
 * @extends WC_Payment_Gateway
 */
class WC_Gateway_Paygent_Paidy extends WC_Payment_Gateway {
	/**
	 * Framework.
	 *
	 * @var stdClass
	 */
	public $jp4wc_framework;

	/**
	 * Settings parameter
	 *
	 * @var string
	 */
	public $paidy_description;

	/**
	 * Order button text.
	 *
	 * @var string
	 */
	public $order_button_text;

	/**
	 * Environment.
	 *
	 * @var string
	 */
	public $environment;

	/**
	 * API public key.
	 *
	 * @var string
	 */
	public $api_public_key;

	/**
	 * Test API public key.
	 *
	 * @var string
	 */
	public $test_api_public_key;

	/**
	 * Store name.
	 *
	 * @var string
	 */
	public $store_name;

	/**
	 * Logo image URL.
	 *
	 * @var string
	 */
	public $logo_image_url;

	/**
	 * Debug mode.
	 *
	 * @var bool
	 */
	public $debug;

	/**
	 * Webhook URL.
	 *
	 * @var string
	 */
	public $webhook;

	/**
	 * Notice email.
	 *
	 * @var string
	 */
	public $notice_email;

	/**
	 * Instructions.
	 *
	 * @var string
	 */
	public $instructions;

	/**
	 * Account details.
	 *
	 * @var array
	 */
	public $account_details;

	/**
	 * Constructor for the gateway.
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() {

		$this->id         = 'paygent_paidy';
		$this->icon       = apply_filters( 'woocommerce_paidy_icon', WC_PAYGENT_PLUGIN_URL . 'assets/images/paidy_logo_100_2023.png' );
		$this->has_fields = false;
		// translators: %s: Payment method name.
		$this->order_button_text = sprintf( __( 'Proceed to %s', 'paidy-wc' ), __( 'Paidy', 'paidy-wc' ) );

		// Create plugin fields and settings.
		$this->init_form_fields();
		$this->init_settings();

		$this->method_title       = __( 'Paidy Payment', 'woocommerce-for-paygent-payment-main' );
		$this->method_description = __( '"Paidy next month payment" reduces the opportunity loss due to the payment method and contributes to sales increase.', 'woocommerce-for-paygent-payment-main' );
		$this->supports           = array(
			'products',
			'refunds',
		);
		// When no save setting error at chackout page.
		if ( is_null( $this->title ) ) {
			$this->title = __( 'Please set this payment at Control Panel! ', 'woocommerce-for-paygent-payment-main' ) . $this->method_title;
		}

		// Set JP4WC framework.
		$this->jp4wc_framework = new Framework\JP4WC_Framework();

		// Get setting values.
		foreach ( $this->settings as $key => $val ) {
			$this->$key = $val;
		}

		include_once 'includes/class-wc-gateway-paygent-request.php';
		$this->paygent_request = new WC_Gateway_Paygent_Request();

		// Set Test mode.
		$this->test_mode   = get_option( 'wc-paygent-testmode' );
		$this->environment = ( '1' !== get_option( 'wc-paygent-testmode' ) ) ? 'live' : 'sandbox';

		// Actions Hook.
		add_action( 'woocommerce_update_options_payment_gateways', array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'paidy_make_order' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'paidy_token_scripts_method' ) );

		add_action( 'woocommerce_before_checkout_form', array( $this, 'checkout_reject_to_cancel' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'jp4wc_order_paidy_status_completed' ) );

		add_action( 'woocommerce_order_status_completed', array( $this, 'order_paidy_status_completed' ) );
	}

	/**
	 * Initialize Gateway Settings Form Fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'             => array(
				'title'       => __( 'Enable/Disable', 'woocommerce-for-paygent-payment-main' ),
				'label'       => __( 'Enable paygent Paidy Payment', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
			'title'               => array(
				'title'       => __( 'Title', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-for-paygent-payment-main' ),
				'default'     => __( 'Paidy', 'woocommerce-for-paygent-payment-main' ),
			),
			'description'         => array(
				'title'       => __( 'Description', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce-for-paygent-payment-main' ),
				// translators: %s: Paidy.
				'default'     => sprintf( __( 'Pay with your %s via Paygent.', 'woocommerce-for-paygent-payment-main' ), __( 'Paidy', 'woocommerce-for-paygent-payment-main' ) ),
			),
			'paidy_description'   => array(
				'title'             => __( 'Paidy description', 'woocommerce-for-paygent-payment-main' ),
				'type'              => 'textarea',
				'custom_attributes' => array( 'rows' => 6 ),
				'description'       => __( 'Payment method description for paidy explanation that the customer will see on your checkout.', 'woocommerce-for-paygent-payment-main' ),
				'default'           => $this->paidy_explanation(),
				'desc_tip'          => true,
			),
			'order_button_text'   => array(
				'title'       => __( 'Order Button Text', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce-for-paygent-payment-main' ),
				// translators: %s: Paidy.
				'default'     => sprintf( __( 'Proceed to %s', 'woocommerce-for-paygent-payment-main' ), __( 'Paidy', 'woocommerce-for-paygent-payment-main' ) ),
			),
			'api_public_key'      => array(
				'title'       => __( 'API Public Key', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'text',
				// translators: %s: API Public Key.
				'description' => sprintf( __( 'Please enter %s from Paidy Admin site.', 'woocommerce-for-paygent-payment-main' ), __( 'API Public Key', 'woocommerce-for-paygent-payment-main' ) ),
				'default'     => '',
			),
			'test_api_public_key' => array(
				'title'       => __( 'Test API Public Key', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'text',
				// translators: %s: Test API Public Key.
				'description' => sprintf( __( 'Please enter %s from Paidy Admin site.', 'woocommerce-for-paygent-payment-main' ), __( 'Test API Public Key', 'woocommerce-for-paygent-payment-main' ) ),
				'default'     => '',
			),
			'store_name'          => array(
				'title'       => __( 'Store Name', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'text',
				'description' => __( 'This controls the store name which the user sees during paidy checkout.', 'woocommerce-for-paygent-payment-main' ),
				'default'     => __( 'Paidy', 'woocommerce-for-paygent-payment-main' ),
			),
			'logo_image_url'      => array(
				'title'       => __( 'Logo Image (168×168 recommend)', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'image',
				'description' => __( 'URL of a custom logo that can be displayed in the checkout application header. If no value is specified, the Paidy logo will be displayed.', 'woocommerce-for-paygent-payment-main' ),
				'default'     => '',
				'desc_tip'    => true,
				'placeholder' => __( 'Optional', 'woocommerce-for-paygent-payment-main' ),
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
	 * UI - Payment page Description fields for Paidy Payment.
	 */
	public function payment_fields() {
		// Description of payment method from settings.
		?>
		<br />
		<a href="https://paidy.com/consumer" target="_blank" class="jp4wc-paidy-icon">
			<img src="<?php echo esc_url( WC_PAYGENT_PLUGIN_URL ) . 'assets/images/checkout_banner_320x100.png'; ?>" alt="Paidy 翌月まとめてお支払い" style="max-height: none; float: none;">
		</a>
		<br />
		<p class="jp4wc-paidy-description"><?php echo esc_html( $this->description ); ?></p>
		<br />
		<?php
		if ( empty( $this->paidy_description ) ) {
			$paidy_explanation = $this->paidy_explanation();
		} else {
			$paidy_explanation = $this->paidy_description;
		}
		$allowed_html = array(
			'a'      => array(
				'href'   => array(),
				'target' => array(),
			),
			'br'     => array(),
			'strong' => array(),
			'b'      => array(),
			'div'    => array(),
			'ul'     => array(),
			'li'     => array(),
		);
		echo wp_kses( $paidy_explanation, $allowed_html );
	}

	/**
	 * Provides the explanation for Paidy payment method.
	 *
	 * @return string HTML content explaining Paidy payment method.
	 */
	protected function paidy_explanation() {
		$image_url    = WC_PAYGENT_PLUGIN_URL . 'assets/images/paidy_checkout_2023_320x100.png';
		$explain_html = '
        <div class="jp4wc-paidy-explanation">
		<img src="' . esc_url( $image_url ) . '" alt="Paidy 翌月まとめてお支払い" style="max-height: none; float: none;">
        <ul>
            <li style="list-style: disc !important;">クレジットカード、事前登録不要。</li>
            <li style="list-style: disc !important;">メールアドレスと携帯番号だけで、今すぐお買い物。</li>
            <li style="list-style: disc !important;">1か月に何度お買い物しても、お支払いは翌月まとめて1回でOK。</li>
            <li style="list-style: disc !important;">お支払いは翌月10日までに、コンビニ払い・銀行振込・口座振替で。</li>
        </ul>
        さらにペイディアプリから本人確認をすると、分割手数料無料*の３回あと払い**や、使い過ぎを防止する予算設定など、便利な機能をご利用いただけます。<br />
*銀行振込・口座振替のみ無料<br />
**1回のご利用金額が3,000円以上の場合のみ利用可能<br />
        Paidyについて詳しくは<a href="https://paidy.com/payments/" target="_blank">こちら</a>。
        </div>
        ';
		return apply_filters( 'jp4wc_paidy_explanation', $explain_html );
	}

	/**
	 * Process the payment and return the result
	 *
	 * @param int $order_id Order ID.
	 * @return array | mixed
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		// Return thankyou redirect.
		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	/**
	 * Make Paidy JavaScript for payment process
	 *
	 * @param string $order_id Order ID.
	 * @return mixed
	 */
	public function paidy_make_order( $order_id ) {
		// Set Order.
		$order = wc_get_order( $order_id );
		// Set public key by environment.
		if ( 'live' === $this->environment ) {
			$api_public_key = $this->api_public_key;
		} else {
			$api_public_key = $this->test_api_public_key;
		}
		// Set logo image url.
		if ( isset( $this->logo_image_url ) ) {
			$logo_image_url = wp_get_attachment_url( $this->logo_image_url );
		} else {
			$logo_image_url = 'https://www.paidy.com/images/logo.png';
		}
		$paidy_order_ref = $order_id;
		// Set user id.
		if ( is_user_logged_in() ) {
			$user_id = get_current_user_id();
		} else {
			$user_id = 'guest-paidy' . $paidy_order_ref;
		}

		if ( version_compare( WC_VERSION, '3.6', '>=' ) ) {
			$jp4wc_countries = new WC_Countries();
			$states          = $jp4wc_countries->get_states();
		} else {
			global $states;
		}
		// Set shipping address.
		if ( $order->get_shipping_postcode() ) {
			$shipping_address['line1'] = $order->get_shipping_address_2();
			$shipping_address['line2'] = $order->get_shipping_address_1();
			$shipping_address['city']  = $order->get_shipping_city();
			$shipping_address['state'] = $states['JP'][ $order->get_shipping_state() ];
			$shipping_address['zip']   = $order->get_shipping_postcode();
		} else {
			$shipping_address['line1'] = $order->get_billing_address_2();
			$shipping_address['line2'] = $order->get_billing_address_1();
			$shipping_address['city']  = $order->get_billing_city();
			$shipping_address['state'] = $states['JP'][ $order->get_billing_state() ];
			$shipping_address['zip']   = $order->get_billing_postcode();
		}

		// Get products and coupons information from order.
		$order_items  = $order->get_items( array( 'line_item', 'coupon' ) );
		$items_count  = 0;
		$cart_total   = 0;
		$fees         = $order->get_fees();
		$items        = '';
		$paidy_amount = 0;
		foreach ( $order_items as $key => $item ) {
			if ( isset( $item['product_id'] ) ) {
				$unit_price    = round( $item['subtotal'] / $item['quantity'], 0 );
				$items        .= '{
                    "id":"' . $item['product_id'] . '",
                    "quantity":' . $item['quantity'] . ',
                    "title":"' . $item['name'] . '",
                    "unit_price":' . $unit_price;
				$paidy_amount += $item['quantity'] * $unit_price;
			} elseif ( isset( $item['discount'] ) ) {
				$items        .= '{
                    "id":"' . $item['code'] . '",
                    "quantity":1,
                    "title":"' . $item['name'] . '",
                    "unit_price":-' . $item['discount'];
				$paidy_amount -= $item['discount'];
			}
			if ( end( $order_items ) === $item && ( ! isset( $fees ) ) ) {
				$items .= '}
';
			} else {
				$items .= '},
                    ';
			}
			$items_count += $item['quantity'];
			$cart_total  += $item['subtotal'];
		}
		if ( isset( $fees ) ) {
			$i = 1;
			foreach ( $fees as $fee ) {
				$items        .= '{
                    "id":"fee' . $i . '",
                    "quantity":1,
                    "title":"' . esc_html( $fee->get_name() ) . '",
                    "unit_price":' . esc_html( $fee->get_amount() );
				$paidy_amount += esc_html( $fee->get_amount() );
				if ( end( $fees ) === $fee ) {
					$items .= '}
';
				} else {
					$items .= '},
                    ';
				}
				++$i;
			}
		}
		// Get latest order.
		$args               = array(
			'customer_id' => $user_id,
			'status'      => 'completed',
			'orderby'     => 'date',
			'order'       => 'DESC',
		);
		$orders             = wc_get_orders( $args );
		$total_order_amount = 0;
		$order_count        = 0;
		foreach ( $orders as $each_order ) {
			if ( $each_order->get_payment_method() !== $this->id ) {
				$selected_orders[]   = $each_order;
				$total_order_amount += $each_order->get_total();
				++$order_count;
			}
		}
		if ( isset( $selected_orders[1] ) ) {
			foreach ( $selected_orders as $each_order ) {
				if ( end( $selected_orders ) === $each_order ) {
					$latest_order = $each_order;
				}
			}
		} elseif ( isset( $selected_orders ) ) {
			$latest_order = $selected_orders[0];
		} else {
			$latest_order = null;
		}
		if ( isset( $latest_order ) ) {
			$last_order_amount = $latest_order->get_total();
			$day1              = strtotime( $latest_order->get_date_created() );
			$day2              = strtotime( date_i18n( 'Y-m-d H:i:s' ) );
			$diff_day          = floor( ( $day2 - $day1 ) / ( 60 * 60 * 24 ) );
			if ( $diff_day <= 0 ) {
				$diff_day = 0;
			}
		} else {
			$last_order_amount = 0;
			$diff_day          = 0;
		}
		$order_amount = $order->get_total();
		$tax          = $order_amount - $paidy_amount - $order->get_shipping_total();
		if ( 'yes' === $this->enabled && isset( $api_public_key ) && '' !== $api_public_key ) :
			?>
			<script type="text/javascript">
				jQuery(window).on('load', function(){
					paidyPay();
				})
				var config = {
					"api_key": "<?php echo esc_attr( $api_public_key ); ?>",
					"logo_url": "<?php echo esc_attr( $logo_image_url ); ?>",
					"closed": function(callbackData) {
						/*
						Data returned in the callback:
						callbackData.id,
						callbackData.amount,
						callbackData.currency,
						callbackData.created_at,
						callbackData.status
						*/
						if(callbackData.status === "rejected"){
							window.location.href = "<?php echo esc_url( wc_get_checkout_url() ) . '?status='; ?>" + callbackData.status + "&order_id=<?php echo esc_js( $order_id ); ?>";
						}else if(callbackData.status === "authorized"){
							window.location.href = "<?php echo esc_url( $this->get_return_url( $order ) ) . '&transaction_id='; ?>" + callbackData.id;
						}else{
							window.location.href = "<?php echo esc_url( wc_get_checkout_url() ) . '?status='; ?>" + callbackData.status + "&order_id=<?php echo esc_js( $order_id ); ?>";
						}
					}
				};
				var paidyHandler = Paidy.configure(config);
				function paidyPay() {
					var payload = {
						"amount": <?php echo esc_js( $order_amount ); ?>,
						"currency": "JPY",
						"store_name": "<?php echo esc_js( $this->store_name ); ?>",
						"buyer": {
							"email": "<?php echo esc_js( $order->get_billing_email() ); ?>",
							"name1": "<?php echo esc_js( $order->get_billing_last_name() ) . ' ' . esc_js( $order->get_billing_first_name() ); ?>",
			<?php
			$billing_yomigana_last_name = $order->get_meta( '_billing_yomigana_last_name' );
			if ( isset( $billing_yomigana_last_name ) ) :
				?>
							"name2": "<?php echo esc_js( $order->get_meta( '_billing_yomigana_last_name' ) ) . ' ' . esc_js( $order->get_meta( '_billing_yomigana_first_name' ) ); ?>",
<?php endif; ?>
							"phone": "<?php echo esc_js( $order->get_billing_phone() ); ?>"
						},
						"buyer_data": {
							"user_id": "<?php echo esc_js( $user_id ); ?>",
							"order_count": <?php echo esc_js( $order_count ); ?>,
							"ltv": <?php echo esc_js( $total_order_amount ); ?>,
							"last_order_amount": <?php echo esc_js( $last_order_amount ); ?>,
							"last_order_at": <?php echo esc_js( $diff_day ); ?>
						},
						"order": {
							"items": [
								<?php echo $items; // phpcs:ignore ?>
							],
							"order_ref": "<?php echo esc_js( $paidy_order_ref ); ?>",
							"shipping": <?php echo esc_js( $order->get_shipping_total() ); ?>,
							"tax": <?php echo esc_js( $tax ); ?>
						},
						"shipping_address": {
							"line1": "<?php echo esc_js( $shipping_address['line1'] ); ?>",
							"line2": "<?php echo esc_js( $shipping_address['line2'] ); ?>",
							"city": "<?php echo esc_js( $shipping_address['city'] ); ?>",
							"state": "<?php echo esc_js( $shipping_address['state'] ); ?>",
							"zip": "<?php echo esc_js( $shipping_address['zip'] ); ?>"
						},
						"description": "<?php echo esc_js( $this->store_name ); ?>"
					};
					paidyHandler.launch(payload);
				}
			</script>
		<?php else : ?>
			<h2><?php esc_html_e( 'API Public key is not set. Please set an API public key in the admin page.', 'woocommerce-for-paygent-payment-main' ); ?></h2>
			<?php
		endif;
	}

	/**
	 * Update Sale from Auth to Paidy System
	 *
	 * @param string $order_id Order ID.
	 * @return mixed
	 */
	public function jp4wc_order_paidy_status_completed( $order_id ) {
		$order          = wc_get_order( $order_id );
		$current_status = $order->get_status();
		if ( 'pending' === $current_status && isset( $_GET['transaction_id'] ) ) {// phpcs:ignore
			// Reduce stock levels.
			wc_reduce_stock_levels( $order_id );
			$order->payment_complete( $_GET['transaction_id'] );// phpcs:ignore
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
		$send_data = array();
		$order     = wc_get_order( $order_id );
		// Set Order ID for Paygent.
		$send_data['trading_id'] = $order_id;

		if ( $order->get_status() === 'processing' ) {// Processing to cancel.
			$telegram_kind = '340';
			$response      = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );
			if ( '0' === $response['result'] ) {
				$order->add_order_note( __( 'Success the cancel for paygent.', 'woocommerce-for-paygent-payment-main' ) );
				return true;
			} else {
				$order->add_order_note( __( 'Failed the cancel for paygent.', 'woocommerce-for-paygent-payment-main' ) . $response['responseCode'] . ':' . $response['responseDetail'] . ':' . $response['result'] );
				return false;
			}
		} elseif ( $order->get_status() === 'completed' ) {
			$telegram_kind = '342';
			$response      = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );
			if ( '0' === $response['result'] ) {
				$order->add_order_note( __( 'Success the refund for paygent.', 'woocommerce-for-paygent-payment-main' ) );
				return true;
			} else {
				$order->add_order_note( __( 'Failed the cancel for paygent.', 'woocommerce-for-paygent-payment-main' ) . $response['responseCode'] . $response['responseDetail'] );
				return false;
			}
		} else {
			$order->add_order_note( __( 'Failed this order to refund for paygent.', 'woocommerce-for-paygent-payment-main' ) );
			return false;
		}
	}

	/**
	 * Update Sale from Auth to Paygent System
	 *
	 * @param int $order_id Order ID.
	 */
	public function order_paidy_status_completed( $order_id ) {
		$telegram_kind = '341';
		$this->paygent_request->order_paygent_status_completed( $order_id, $telegram_kind, $this );
	}

	/**
	 * Load Paygent Paidy Token javascript
	 */
	public function paidy_token_scripts_method() {
		// Image upload.
		wp_enqueue_media();

		$paygent_token_js_link = 'https://apps.paidy.com/';
		if ( is_checkout() ) {
			wp_enqueue_script(
				'paidy-token',
				$paygent_token_js_link,
				array(),
				WC_PAYGENT_VERSION,
				false
			);
			// Paidy Payment for Checkout page.
			wp_register_style(
				'jp4wc-paidy',
				WC_PAYGENT_PLUGIN_URL . '/assets/css/jp4wc-paidy.css',
				false,
				WC_PAYGENT_VERSION
			);
			wp_enqueue_style( 'jp4wc-paidy' );
		}
	}

	/**
	 * Load Paidy javascript for Admin
	 */
	public function admin_enqueue_scripts() {
		// Image upload.
		wp_enqueue_media();
		if ( is_admin() && false === wp_script_is( 'wc-gateway-ppec-settings' ) && 'paidy' === $_GET['section'] ) {// phpcs:ignore
			wp_enqueue_script(
				'wc-gateway-paidy-settings',
				WC_PAYGENT_PLUGIN_URL . '/assets/js/wc-gateway-paidy-settings.js',
				array( 'jquery' ),
				WC_PAYGENT_VERSION,
				true
			);
		}
	}

	/**
	 * Update Cancel from Auth to Paidy System
	 *
	 * @param object $checkout Checkout object.
	 * @return mixed
	 */
	public function checkout_reject_to_cancel( $checkout ) {
		if ( isset( $_GET['status'] ) ) {// phpcs:ignore
			if ( 'closed' === $_GET['status'] && isset( $_GET['order_id'] ) ) {// phpcs:ignore
				$message = __( 'Once the customer interrupted the payment.. Order ID:', 'woocommerce-for-paygent-payment-main' ) . wp_unslash( $_GET['order_id'] );// phpcs:ignore
				$this->jp4wc_framework->jp4wc_debug_log( $message, $this->debug, 'woocommerce-for-paygent-payment-main' );
			} elseif ( 'rejected' === $_GET['status'] || isset( $_GET['order_id'] ) ) {// phpcs:ignore
				$reject_message = __( 'This Paidy payment has been declined. Please select another payment method.', 'woocommerce-for-paygent-payment-main' );
				wc_add_notice( $reject_message, 'error' );
			}
		}
	}

	/**
	 * Generate Image HTML.
	 *
	 * @param  mixed $key Key.
	 * @param  mixed $data Data.
	 * @since  1.5.0
	 * @return string
	 */
	public function generate_image_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$defaults  = array(
			'title'             => '',
			'disabled'          => false,
			'class'             => '',
			'css'               => '',
			'placeholder'       => '',
			'type'              => 'text',
			'desc_tip'          => false,
			'description'       => '',
			'custom_attributes' => array(),
		);

		$data  = wp_parse_args( $data, $defaults );
		$value = $this->get_option( $key );

		// Hide show add remove buttons.
		$maybe_hide_add_style    = '';
		$maybe_hide_remove_style = '';

		// For backwards compatibility (customers that already have set a url).
		$value_is_url = filter_var( $value, FILTER_VALIDATE_URL ) !== false;

		if ( empty( $value ) || $value_is_url ) {
			$maybe_hide_remove_style = 'display: none;';
		} else {
			$maybe_hide_add_style = 'display: none;';
		}

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?> <?php echo wp_kses_post( $this->get_tooltip_html( $data ) ); ?></label>
			</th>

			<td class="image-component-wrapper">
				<div class="image-preview-wrapper">
					<?php
					if ( ! $value_is_url ) {
						echo wp_get_attachment_image( $value, 'thumbnail' );
					} else {
						// translators: %s: URL.
						printf( esc_html__( 'Already using URL as image: %s', 'woocommerce-for-paygent-payment-main' ), esc_html( $value ) );
					}
					?>
				</div>

				<button
						class="button image_upload"
						data-field-id="<?php echo esc_attr( $field_key ); ?>"
						data-media-frame-title="<?php echo esc_attr( __( 'Select a image to upload', 'woocommerce-for-paygent-payment-main' ) ); ?>"
						data-media-frame-button="<?php echo esc_attr( __( 'Use this image', 'woocommerce-for-paygent-payment-main' ) ); ?>"
						data-add-image-text="<?php echo esc_attr( __( 'Add image', 'woocommerce-for-paygent-payment-main' ) ); ?>"
						style="<?php echo esc_attr( $maybe_hide_add_style ); ?>"
				>
					<?php echo esc_html__( 'Add image', 'woocommerce-for-paygent-payment-main' ); ?>
				</button>

				<button
						class="button image_remove"
						data-field-id="<?php echo esc_attr( $field_key ); ?>"
						style="<?php echo esc_attr( $maybe_hide_remove_style ); ?>"
				>
					<?php echo esc_html__( 'Remove image', 'woocommerce-for-paygent-payment-main' ); ?>
				</button>

				<input type="hidden"
						name="<?php echo esc_attr( $field_key ); ?>"
						id="<?php echo esc_attr( $field_key ); ?>"
						value="<?php echo esc_attr( $value ); ?>"
				/>
			</td>
		</tr>
		<?php

		return ob_get_clean();
	}
}
