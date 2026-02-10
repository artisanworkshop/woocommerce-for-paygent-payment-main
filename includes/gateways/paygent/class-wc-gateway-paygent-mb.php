<?php
/**
 * WooCommerce Paygent Mobile Gateway
 *
 * Provides a Paygent Mobile Payment Gateway integration for WooCommerce.
 *
 * @version 2.4.8
 * @package WooCommerce/Gateways
 * @category Payment Gateways
 * @author Artisan Workshop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ArtisanWorkshop\PluginFramework\v2_0_13 as Framework;

/**
 * Class WC_Gateway_Paygent_MB
 *
 * Handles Mobile payments through Paygent payment gateway.
 *
 * @extends WC_Payment_Gateway
 */
class WC_Gateway_Paygent_MB extends WC_Payment_Gateway {

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
	 * Prefix for Order
	 *
	 * @var string
	 */
	public $prefix_order;

	/**
	 * Set gmopg request class
	 *
	 * @var stdClass
	 */
	public $paygent_request;
	/**
	 * Order status setting after payment
	 *
	 * @var string
	 */
	public $update_status;

	/**
	 * Carrier types
	 *
	 * @var arrray
	 */
	public $carrier_types;

	/**
	 * Carrier types au
	 *
	 * @var string
	 */
	public $setting_ct_04;

	/**
	 * Carrier types docomo
	 *
	 * @var string
	 */
	public $setting_ct_05;

	/**
	 * Carrier types SoftBank
	 *
	 * @var string
	 */
	public $setting_ct_06;

	/**
	 * Consolidate sales when the subscription flag
	 *
	 * @var string
	 */
	public $subscription_amount;


	/**
	 * Constructor for the gateway.
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() {

		$this->id         = 'paygent_mb';
		$this->has_fields = false;
		// translators: Payment method name.
		$this->order_button_text = sprintf( __( 'Proceed to %s', 'woocommerce-for-paygent-payment-main' ), __( 'Carrier Payment', 'woocommerce-for-paygent-payment-main' ) );

		// Create plugin fields and settings.
		$this->init_form_fields();
		$this->init_settings();
		// translators: Payment method name.
		$this->method_title = sprintf( __( 'Paygent %s Gateway', 'woocommerce-for-paygent-payment-main' ), __( 'Carrier Payment', 'woocommerce-for-paygent-payment-main' ) );
		// translators: Payment method name.
		$this->method_description = sprintf( __( 'Allows payments by Paygent %s in Japan.', 'woocommerce-for-paygent-payment-main' ), __( 'Carrier Payment', 'woocommerce-for-paygent-payment-main' ) );
		$this->supports           = array(
			'products',
			'refunds',
			'subscriptions',
			'subscription_cancellation',
			'subscription_reactivation',
			'subscription_suspension',
			'subscription_amount_changes',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
			'subscription_date_changes',
		);
		// When no save setting error at checkout page.
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

		// Set Prefix.
		$this->prefix_order = get_option( 'wc-paygent-prefix_order' );

		// Set Carrier type.
		$this->carrier_types = array();
		if ( 'yes' === $this->setting_ct_04 ) {
			$this->carrier_types = array_merge(
				$this->carrier_types,
				array( '04' => __( 'au Easy Payment', 'woocommerce-for-paygent-payment-main' ) )
			);
		}
		if ( 'yes' === $this->setting_ct_05 ) {
			$this->carrier_types = array_merge(
				$this->carrier_types,
				array( '05' => __( 'Docomo Payment', 'woocommerce-for-paygent-payment-main' ) )
			);
		}
		if ( 'yes' === $this->setting_ct_06 ) {
			$this->carrier_types = array_merge(
				$this->carrier_types,
				array( '06' => __( 'SoftBank Matomete Payment(B)', 'woocommerce-for-paygent-payment-main' ) )
			);
		}

		// Actions.
		add_action( 'woocommerce_update_options_payment_gateways', array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'mb_check_open_id' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'mb_thankyou' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'order_mb_status_completed' ) );

		// Add meta boxes for both traditional and HPOS orders.
		add_action( 'add_meta_boxes', array( $this, 'paygent_mb_add_meta_box' ), 24, 2 );
		add_action( 'add_meta_boxes_woocommerce_page_wc-orders', array( $this, 'paygent_mb_add_meta_box' ), 24, 2 );
		add_action( 'add_meta_boxes_woocommerce_page_wc-orders--shop_subscription', array( $this, 'paygent_mb_add_meta_box' ), 24, 2 );
		// Cancel order.
		add_action( 'woocommerce_before_cart', array( $this, 'paygent_cart_cancel' ) );

		// Allow redirects to Paygent payment gateway domains.
		add_filter( 'allowed_redirect_hosts', array( $this, 'add_allowed_redirect_hosts' ) );
	}

	/**
	 * Initialize Gateway Settings Form Fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'           => array(
				'title'       => __( 'Enable/Disable', 'woocommerce-for-paygent-payment-main' ),
				// translators: Payment method name.
				'label'       => sprintf( __( 'Enable paygent %s Payment', 'woocommerce-for-paygent-payment-main' ), __( 'Carrier Payment', 'woocommerce-for-paygent-payment-main' ) ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
			'title'             => array(
				'title'       => __( 'Title', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-for-paygent-payment-main' ),
				'default'     => __( 'Carrier Payment', 'woocommerce-for-paygent-payment-main' ),
			),
			'description'       => array(
				'title'       => __( 'Description', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce-for-paygent-payment-main' ),
				// translators: Payment method name.
				'default'     => sprintf( __( 'Pay with your %s via Paygent.', 'woocommerce-for-paygent-payment-main' ), __( 'Carrier Payment', 'woocommerce-for-paygent-payment-main' ) ),
			),
			'order_button_text' => array(
				'title'       => __( 'Order Button Text', 'woocommerce-for-paygent-payment-main' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce-for-paygent-payment-main' ),
				// translators: Payment method name.
				'default'     => sprintf( __( 'Proceed to %s', 'woocommerce-for-paygent-payment-main' ), __( 'Carrier Payment', 'woocommerce-for-paygent-payment-main' ) ),
			),
			'setting_ct_04'     => array(
				'id'      => 'wc-paygent-ct-04',
				'type'    => 'checkbox',
				'label'   => __( 'au Easy Payment', 'woocommerce-for-paygent-payment-main' ),
				'default' => 'yes',
			),
			'setting_ct_05'     => array(
				'id'      => 'wc-paygent-ct-05',
				'type'    => 'checkbox',
				'label'   => __( 'Docomo Payment', 'woocommerce-for-paygent-payment-main' ),
				'default' => 'yes',
			),
			'setting_ct_06'     => array(
				'id'      => 'wc-paygent-ct-06',
				'type'    => 'checkbox',
				'label'   => __( 'SoftBank Matomete Payment(B)', 'woocommerce-for-paygent-payment-main' ),
				'default' => 'yes',
			),
			'update_status'     => array(
				'title'   => __( 'Payment Action', 'woocommerce-for-paygent-payment-main' ),
				'type'    => 'checkbox',
				'label'   => __( 'Payment Completed', 'woocommerce-for-paygent-payment-main' ),
				'default' => 'no',
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
			<p><?php echo wp_kses_post( $this->description ); ?></p>
		<?php } ?>
		<fieldset  style="padding-left: 40px;">
			<p><?php esc_html_e( 'Please select carrier type where you want to pay', 'woocommerce-for-paygent-payment-main' ); ?></p>
			<?php $this->carrier_type_select(); ?>
		</fieldset>
		<?php
	}

	/**
	 * Display the carrier type selection dropdown.
	 */
	public function carrier_type_select() {
		?>
		<select name="career_type">
		<?php
		$carrier_types = apply_filters( 'paygent_mb_carrier_types', $this->carrier_types );
		foreach ( $carrier_types as $num => $value ) {
			?>
			<option value="<?php echo esc_attr( $num ); ?>"><?php echo esc_html( $value ); ?></option>
		<?php } ?>
		</select>
		<?php
	}

	/**
	 * Confirmation of mobile terminal.
	 */
	public function is_device() {
		$device_info = '';
		// Store the user agent in a variable.
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		// Put the UA of the terminal you want to judge on the smartphone in the array.
		$spes = array(
			'iPhone',         // Apple iPhone.
			'iPod',           // Apple iPod touch.
			'Android',        // Android.
			'dream',          // Pre 1.5 Android.
			'CUPCAKE',        // 1.5+ Android.
			'blackberry',     // blackberry.
			'webOS',          // Palm Pre Experimental.
			'incognito',      // Other iPhone browser.
			'webmate',         // Other iPhone browser.
		);
		// Put the UA of the terminal you want to judge with the tablet in the array.
		$tabs = array(
			'iPad',
			'Android',
		);
		// Put the UA of the terminal you want to judge with a phone call into the array.
		$mbes = array(
			'DoCoMo',
			'KDDI',
			'DDIPOKET',
			'UP.Browser',
			'J-PHONE',
			'Vodafone',
			'SoftBank',
		);

		// デバイス変数が空だったら判定する.
		if ( empty( $device_info ) ) {
			// タブレット判定.
			foreach ( $tabs as $tab ) {
				$str = '/' . $tab . '/i';
				// ユーザーエージェントにstrが含まれていたら実行する.
				if ( preg_match( $str, $ua ) ) {
					// strがAndroidだったらのモバイル判定を行う。.
					if ( '/Android/i' === $str ) {
						// ユーザーエージェントにMobileが含まれていなかったらタブレット.
						if ( ! preg_match( '/Mobile/i', $ua ) ) {
							$device_info = 'tab';
						} else {
							// ユーザーエージェントにMobileが含まれていたらスマートフォン.
							$device_info = 'sp';
						}
					} else {
						// Android以外はそのまま結果を返す.
						$device_info = 'tab';
					}
				}
			}
		}

		// デバイス変数が空だったら判定する.
		if ( empty( $device_info ) ) {
			// スマートフォン判定.
			foreach ( $spes as $sp ) {
				$str = '/' . $sp . '/i';
				// ユーザーエージェントにstrが含まれていたらスマートフォン.
				if ( preg_match( $str, $ua ) ) {
					$device_info = 'sp';
				}
			}
		}

		// デバイス変数が空だったら判定する.
		if ( empty( $device_info ) ) {
			// ガラケー判定.
			foreach ( $mbes as $mb ) {
				$str = '/' . $mb . '/i';
				// ユーザーエージェントにstrが含まれていたらガラケー.
				if ( preg_match( $str, $ua ) ) {
					if ( 'DoCoMo' === $mb ) {
						$device_info = 'mb-docomo';
					} elseif ( 'KDDI' === $mb ) {
						$device_info = 'mb-au';
					} elseif ( 'SoftBank' === $mb || 'Vodafone' === $mb || 'J-PHONE' === $mb ) {
						$device_info = 'mb-softbank';
					}
				}
			}
		}

		// If none of the judgments are successful, consider it a PC.
		if ( empty( $device_info ) ) {
			$device_info = 'pc';
		}
		return $device_info;
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id Order ID.
	 * @return mixed
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		$send_data = array();

		// Common header.
		$telegram_kind           = '100';
		$send_data['payment_id'] = null;

		$send_data = $this->set_send_data( $send_data, $order_id );

		return self::payment_mb_process( $order, $send_data, $telegram_kind );
	}

	/**
	 * Common processing for carrier payment
	 *
	 * @param object $order WC_Order object.
	 * @param array  $send_data Send data.
	 * @param int    $telegram_kind Telegram kind.
	 */
	public function payment_mb_process( $order, $send_data, $telegram_kind ) {
		$payment_url = $this->jp4wc_framework->jp4wc_make_add_get_url( $order->get_checkout_payment_url( true ), array( 'telegram_kind' => $telegram_kind ) );
		// Check for payment provider - au (type 4) or Docomo (type 5).
		unset( $send_data['other_url'] );
		if ( 4 === $send_data['career_type'] || 5 === $send_data['career_type'] ) {
			if ( 5 === $send_data['career_type'] ) {
				$order_open_id = $order->get_meta( 'docomo_open_id', true );
			} else {
				$order_open_id = $order->get_meta( 'au_open_id', true );
			}
			if ( isset( $order_open_id ) && ! empty( $order_open_id ) ) {
				$send_data['open_id'] = $order_open_id;
			} else {
				$send_data['amount']       = null;
				$send_data['trading_id']   = null;
				$send_data['redirect_url'] = $payment_url;
				$telegram_kind             = '104';
				$response_user             = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );
				if ( '0' === $response_user['result'] && $response_user['result_array'] ) {
					if ( isset( $response_user['result_array'][0]['running_id'] ) ) {
						$order->add_meta_data( 'running_id', $response_user['result_array'][0]['running_id'] );
					}
					$order->add_meta_data( '_pc_mobile_type', wc_clean( $send_data['pc_mobile_type'] ), true );
					if ( 5 === $send_data['career_type'] ) {// Docomo.
						$mb_type = 'docomo';
						$order->add_meta_data( '_career_type', 'docomo', true );
						$order->add_meta_data( '_open_id_redirect_html', mb_convert_encoding( $response_user['result_array'][0]['redirect_html'], 'UTF-8', 'SJIS' ) );
					} else { // au-payment.
						$mb_type = 'au';
						$order->add_meta_data( '_career_type', 'au', true );
						$order->add_meta_data( '_redirect_url', esc_url_raw( $response_user['result_array'][0]['redirect_url'] ) );
						$payment_url = $response_user['result_array'][0]['redirect_url'];
					}
					$order->save_meta_data();
					$order->save();
					// translators: Payment method name.
					$order->update_status( 'pending', sprintf( __( 'Pending %s', 'woocommerce-for-paygent-payment-main' ), __( 'Carrier Payment', 'woocommerce-for-paygent-payment-main' ) . ':' . $mb_type ) );
					return array(
						'result'   => 'success',
						'redirect' => $payment_url,
					);
				} else {
					$this->paygent_request->error_response( $response_user, $order );
					return array( 'result' => 'failed' );
				}
			}
		}
		$send_data['first_auto_sales_flg'] = 1;
		// SoftBank Payment or Exist Open_ID.
		$response = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );
		$this->save_trading_id_running_id( $telegram_kind, $order, $response );
		// Check response.
		if ( '0' === $response['result'] && $response['result_array'] ) {
			if ( 6 === $send_data['career_type'] ) {
				$order->add_meta_data( '_career_type', 'sb', true );
				$order->add_order_note( 'Career type is Softbank.' );
			}
			$order->add_meta_data( '_paygent_order_id', $send_data['trading_id'], true );
			if ( isset( $response['result_array'][0]['redirect_html'] ) ) {
				$order->add_meta_data( '_redirect_html', mb_convert_encoding( $response['result_array'][0]['redirect_html'], 'UTF-8', 'SJIS' ) );
			}
			$order->save_meta_data();
			$order->save();
			$order->update_status(
				'pending',
				// translators: Payment method name.
				sprintf( __( 'Pending %s', 'woocommerce-for-paygent-payment-main' ), __( 'Carrier Payment', 'woocommerce-for-paygent-payment-main' ) . ':SB' )
			);
			// Return thank you redirect.
			if ( isset( $response['result_array'][0]['redirect_url'] ) ) {
				return array(
					'result'   => 'success',
					'redirect' => $response['result_array'][0]['redirect_url'],
				);
			} elseif ( isset( $response['result_array'][0]['redirect_html'] ) ) {
				return array(
					'result'   => 'success',
					'redirect' => $payment_url,
				);
			} else {
				return array( 'result' => 'failed' );
			}
		} else {
			$this->paygent_request->error_response( $response, $order );
			return array( 'result' => 'failed' );
		}
	}

	/**
	 * Save trading_id and running_id for Order and Subscriptions Order
	 *
	 * @param int    $telegram_kind Telegram kind.
	 * @param object $order WC_Order object.
	 * @param array  $response Response data.
	 * @return void
	 */
	public function save_trading_id_running_id( $telegram_kind, $order, $response ) {
		if ( '120' === $telegram_kind ) {
			if ( isset( $response['result_array'][0]['running_id'] ) ) {
				$running_id = $response['result_array'][0]['running_id'];
			}
			if ( isset( $response['result_array'][0]['trading_id'] ) ) {
				$trading_id = $response['result_array'][0]['trading_id'];
			}
			if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order ) ) {
				$subscription_parent = wcs_get_subscriptions_for_order( $order );
				$keys                = array_keys( $subscription_parent );
				$subscription        = wcs_get_subscription( $keys[0] );
				// Set next payment date to first of next month with random time between 12:00:00 and 23:59:59.
				$dates['next_payment'] = $this->calculate_next_payment_date();
				$subscription->update_dates( $dates );
				if ( isset( $running_id ) ) {
					$subscription->update_meta_data( 'running_id', wc_clean( $running_id ) );
				}
				if ( isset( $trading_id ) ) {
					if ( isset( $trading_id ) ) {
						$subscription->update_meta_data( 'trading_id', wc_clean( $trading_id ) );
					}
					if ( isset( $trading_id ) ) {
						$order->update_meta_data( 'trading_id', wc_clean( $trading_id ) );
					}
				}
				$subscription->save_meta_data();
				$subscription->save();
			}
		}
	}

	/**
	 * Set trading_id for Order and Subscriptions Order
	 *
	 * @param array $send_data array send data.
	 * @param int   $order_id Order ID.
	 *
	 * @return array
	 */
	public function set_send_data( $send_data, $order_id ) {
		$order                   = wc_get_order( $order_id );
		$send_data['trading_id'] = $this->set_trading_id( $order );
		$post_career_type        = $this->get_post( 'career_type' );
		if ( isset( $post_career_type ) ) {
			$send_data['career_type'] = intval( $post_career_type );
		} else {
			$career_type              = $order->get_meta( '_career_type', true );
			$send_data['career_type'] = $this->set_career_type_num( $career_type );
		}
		$send_data['amount'] = $order->get_total();

		$send_data['return_url'] = $this->get_return_url( $order );
		$send_data['cancel_url'] = wc_get_cart_url() . '?mb_cancel=yes';
		$send_data['other_url']  = wc_get_cart_url() . '?mb_cancel=yes';
		if ( $this->is_device() === 'mb-docomo' ) {
			$send_data['pc_mobile_type'] = '1';
		} elseif ( $this->is_device() === 'mb-au' ) {
			$send_data['pc_mobile_type'] = '2';
		} elseif ( $this->is_device() === 'mb-softbank' ) {
			$send_data['pc_mobile_type'] = '3';
		} elseif ( $this->is_device() === 'sp' ) {
			$send_data['pc_mobile_type'] = '4';
		} else {
			$send_data['pc_mobile_type'] = '0';
		}
		$order->add_meta_data( '_pc_mobile_type', $send_data['pc_mobile_type'], true );
		$order->save_meta_data();
		return $send_data;
	}

	/**
	 * Set the career type number based on the career type string.
	 *
	 * @param string $career_type The career type string.
	 * @return int The career type number.
	 */
	public function set_career_type_num( $career_type ) {
		if ( 'docomo' === $career_type ) {
			$career_type_num = 5;
		} elseif ( 'sb' === $career_type ) {
			$career_type_num = 6;
		} elseif ( 'au' === $career_type ) {
			$career_type_num = 4;
		}
		return $career_type_num;
	}

	/**
	 * Get open_id and move to payment
	 *
	 * @param int $order_id Order ID.
	 */
	public function mb_check_open_id( $order_id ) {
		$order          = wc_get_order( $order_id );
		$payment_method = $order->get_payment_method();
		if ( $payment_method === $this->id ) {
			$javascript_auto_send_code = '
<script type="text/javascript">
function send_form_submit() {
    document.form.submit();
}
window.onload = send_form_submit;
</script>';
			$send_data                 = array();
			$career_type               = $order->get_meta( '_career_type', true );
			$send_data['career_type']  = $this->set_career_type_num( $career_type );

			// Common header.
			if ( isset( $_GET['telegram_kind'] ) ) {// phpcs:ignore
				$telegram_kind = sanitize_text_field( wp_unslash( $_GET['telegram_kind'] ) );// phpcs:ignore
			} else {
				$telegram_kind = '';
			}
			if ( 5 === $send_data['career_type'] || 6 === $send_data['career_type'] ) {// docomo and Softbank.
				$allow_redirect_html = array(
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
				$redirect_html       = $order->get_meta( '_redirect_html', true );
				if ( isset( $_GET['open_id'] ) && ! isset( $_GET['change_proceed'] ) ) {// phpcs:ignore
					$send_data['first_auto_sales_flg'] = 1;
					if ( 5 === $send_data['career_type'] ) {// docomo.
						$order->add_meta_data( 'docomo_open_id', wp_unslash( $_GET['open_id'] ), true );// phpcs:ignore
					}
					$send_data = $this->mb_order_send_data( $order_id, $send_data );
					$response  = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );
					$cart_url  = wc_get_cart_url();
					// Check response.
					if ( '0' === $response['result'] && isset( $response['result_array'] ) ) {
						$order->add_meta_data( '_paygent_order_id', $response['result_array'][0]['trading_id'], true );

						$this->save_trading_id_running_id( $telegram_kind, $order, $response );
						// Return thank you redirect.
						if ( isset( $response['result_array'][0]['redirect_html'] ) ) {
							echo wp_kses( $response['result_array'][0]['redirect_html'], $allow_redirect_html );
							echo wp_kses( $javascript_auto_send_code, array( 'script' => array( 'type' => array() ) ) );
						} else {
							$order->add_order_note( 'No redirect HTML' );
							wc_add_notice( __( 'Payment has failed. Please try again.', 'woocommerce-for-paygent-payment-main' ), 'error' );
							wp_safe_redirect( $cart_url );
							exit;
						}
					} else {
						$this->paygent_request->error_response( $response, $order );
						wp_safe_redirect( $cart_url );
						exit;
					}
				} elseif ( $redirect_html && 6 === $send_data['career_type'] ) {// SoftBank.
					echo wp_kses( $redirect_html, $allow_redirect_html );
					echo wp_kses( $javascript_auto_send_code, array( 'script' => array( 'type' => array() ) ) );
				} else { // docomo.
					echo wp_kses( $order->get_meta( '_open_id_redirect_html', true ), $allow_redirect_html );
					echo wp_kses( $javascript_auto_send_code, array( 'script' => array( 'type' => array() ) ) );
				}
			} elseif ( 4 === $send_data['career_type'] ) { // au payment.
				if ( isset( $_GET['open_id'] ) ) {// phpcs:ignore
					$open_id = $_GET['open_id']; // phpcs:ignore
					$order->add_meta_data( 'au_open_id', wp_unslash( $open_id ), true );
					// Common header.
					if ( '100' !== $telegram_kind ) {
						$send_data['first_sales_date'] = date_i18n( 'Ymd', strtotime( date_i18n( 'Ymd' ) . ' +1 day' ) );
						$send_data['sales_timing']     = 1;
					} else {
						$send_data['sales_flg'] = 1;
					}
					$order->save_meta_data();
					$order->save();
					$send_data = $this->mb_order_send_data( $order_id, $send_data );
					$response  = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );
					// Check response.
					if ( '0' === $response['result'] && isset( $response['result_array'] ) ) {
						$this->save_trading_id_running_id( $telegram_kind, $order, $response );
						// Return thank you redirect.
						if ( isset( $response['result_array'][0]['redirect_url'] ) ) {
							$order->add_meta_data( '_redirect_url', esc_url_raw( $response['result_array'][0]['redirect_url'] ), true );
							$order->save_meta_data();
							$order->save();
							// Redirect to Paygent payment gateway (whitelisted via allowed_redirect_hosts filter).
							wp_safe_redirect( $response['result_array'][0]['redirect_url'] );
							exit;
						} else {
							$order->add_order_note( 'No redirect URL' );
							wp_safe_redirect( wc_get_cart_url() );
							exit;
						}
					} else {
						$this->paygent_request->error_response( $response, $order );
					}
				}
			}
			$order->save_meta_data();
		}
	}

	/**
	 * Undocumented function
	 *
	 * @param int   $order_id Order ID.
	 * @param array $send_data Send data.
	 * @return array
	 */
	public function mb_order_send_data( $order_id, $send_data ) {
		$order                       = wc_get_order( $order_id );
		$send_data['amount']         = $this->set_amount( $order );
		$send_data['trading_id']     = $this->set_trading_id( $order );
		$send_data['return_url']     = $this->get_return_url( $order );
		$send_data['cancel_url']     = wc_get_cart_url() . '?mb_cancel=yes';
		$send_data['other_url']      = wc_get_cart_url();
		$send_data['pc_mobile_type'] = $order->get_meta( '_pc_mobile_type', true );
		$send_data['open_id']        = $_GET['open_id'];// phpcs:ignore
		return $send_data;
	}

	/**
	 * Set trading id for simple order and subscription order
	 *   Subscription order: 'wcs_'.$career_type.'_'.$user_id
	 *   Simple order: 'wc_'.$order_id
	 *
	 * @param object $order WC_Order object.
	 *
	 * @return string
	 */
	public function set_trading_id( $order ) {
		if ( $order->get_meta( 'trading_id', true ) ) {
			return $order->get_meta( 'trading_id', true );
		} elseif ( $this->prefix_order ) {
			return $this->prefix_order . $order->get_id();
		} else {
			return 'wc_' . $order->get_id();
		}
	}

	/**
	 * Set the amount requested when ordering
	 *
	 * @param object $order WC_Order object.
	 * @return float
	 */
	public function set_amount( $order ) {
		return $order->get_total();
	}

	/**
	 * Calculate the next payment date for subscription.
	 *
	 * Sets the next payment date to the first day of next month
	 * with a random time between 12:00:00 and 23:59:59.
	 * Uses WordPress timezone settings via current_datetime().
	 *
	 * @return string Formatted date string in 'Y-m-d H:i:s' format (in UTC).
	 */
	private function calculate_next_payment_date() {
		$random_hour   = wp_rand( 12, 23 );
		$random_minute = wp_rand( 0, 59 );
		$random_second = 0;
		// Get current date/time in WordPress timezone.
		$current_date = current_datetime();

		// Calculate next month's year and month.
		$current_year  = (int) $current_date->format( 'Y' );
		$current_month = (int) $current_date->format( 'm' );

		// Increment month.
		$next_month = $current_month + 1;
		$next_year  = $current_year;

		// Handle year rollover (December -> January).
		if ( $next_month > 12 ) {
			$next_month = 1;
			++$next_year;
		}

		// Create new date object for first day of next month.
		// Note: current_datetime() returns DateTimeImmutable, so setDate() and setTime() return new objects.
		$next_month_date = $current_date->setDate( $next_year, $next_month, 1 );
		$next_month_date = $next_month_date->setTime( $random_hour, $random_minute, $random_second );

		// Convert to UTC timezone.
		$next_month_date = $next_month_date->setTimezone( new DateTimeZone( 'UTC' ) );

		return $next_month_date->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Process the order status when the order is completed.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function mb_thankyou( $order_id ) {
		$order          = wc_get_order( $order_id );
		$payment_method = $order->get_payment_method();
		if ( $payment_method === $this->id && isset( $_GET['payment_id'] ) ) {// phpcs:ignore
			$payment_id = wc_clean( $_GET['payment_id'] );// phpcs:ignore
			// set transaction id for Paygent Order Number.
			$order->payment_complete( wc_clean( $payment_id ) );
			$order->add_meta_data( 'payment_id', wc_clean( $payment_id ), true );
			if ( 'yes' === $this->update_status ) {
				$order->update_status( 'completed' );
			} else {
				$order->update_status( 'processing' );
			}
			$order->save();
			if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order ) ) {
				$subscription_parent = wcs_get_subscriptions_for_order( $order );
				$keys                = array_keys( $subscription_parent );
				$subscription        = wcs_get_subscription( $keys[0] );
				// Set next payment date to first of next month with random time between 12:00:00 and 23:59:59.
				$dates['next_payment'] = $this->calculate_next_payment_date();
				$subscription->update_dates( $dates );
				if ( isset( $_GET['running_id'] ) ) {// phpcs:ignore
					if ( isset( $_GET['trading_id'] ) ) {// phpcs:ignore
						$subscription->update_meta_data( 'trading_id', wp_unslash( $_GET['trading_id'] ) );// phpcs:ignore
					}
					if ( isset( $_GET['running_id'] ) ) {// phpcs:ignore
						$subscription->update_meta_data( 'running_id', wp_unslash( $_GET['running_id'] ) );// phpcs:ignore
					}
					if ( isset( $_GET['payment_id'] ) ) {// phpcs:ignore
						$subscription->update_meta_data( 'payment_id', wp_unslash( $_GET['payment_id'] ) );// phpcs:ignore
					}
					if ( isset( $_GET['trading_id'] ) ) {// phpcs:ignore
						$order->update_meta_data( 'trading_id', wp_unslash( $_GET['trading_id'] ) );// phpcs:ignore
					}
				}
				$subscription->save_meta_data();
				$subscription->save();
			}
			$order->save_meta_data();
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
		if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order_id ) ) {
			return $this->subscription_order_refund( $order_id, $amount );
		} else {
			return $this->general_order_refund( $order_id, $amount );
		}
	}

	/**
	 * Refunds for general orders
	 *
	 * @param int   $order_id Order ID.
	 * @param float $amount Refund amount.
	 * @return mixed
	 */
	public function general_order_refund( $order_id, $amount = null ) {
		$telegram_array   = array(
			'auth_cancel' => '102',
			'sale_cancel' => '102',
			'auth_change' => '103',
			'sale_change' => '103',
		);
		$permit_statuses  = array(
			5 => array(// docomo.
				'auth_cancel' => array( 20, 21 ),
				'sale_cancel' => array( 44 ),
				'auth_change' => array( 20, 21 ),
				'sale_change' => array( 44 ),
			),
			4 => array(// au.
				'auth_cancel' => array( 20 ),
				'sale_cancel' => array( 40 ),
				'auth_change' => array( 20 ),
				'sale_change' => array( 40 ),
			),
			6 => array(// SoftBank(B).
				'auth_cancel' => array( 20, 21 ),
				'sale_cancel' => array( 40 ),
				'auth_change' => array( 20.21 ),
				'sale_change' => array( 40 ),
			),
		);
		$send_data_refund = array(
			'amount' => $amount,
		);
		return $this->paygent_request->paygent_process_refund( $order_id, $amount, $telegram_array, $permit_statuses, $send_data_refund, $this );
	}

	/**
	 * Refunds for subscription orders
	 *
	 * @param int   $order_id Order ID.
	 * @param float $amount Refund amount.
	 * @return mixed
	 */
	public function subscription_order_refund( $order_id, $amount = null ) {
		$telegram_array   = array(
			'sale_cancel' => '122',
		);
		$permit_statuses  = array(
			5 => array(// docomo.
				'sale_cancel' => array( 20, 21, 44 ),
			),
			4 => array(// au.
				'sale_cancel' => array( 40 ),
			),
			6 => array(// SoftBank(B).
				'sale_cancel' => array( 20, 21, 40 ),
			),
		);
		$order            = wc_get_order( $order_id );
		$target_ym        = date_i18n( 'YYYYMM', $order->get_date_created() );
		$running_id       = $order->get_meta( 'running_id', true );
		$send_data_refund = array(
			'running_id'        => $running_id,
			'running_target_ym' => $target_ym,
		);
		return $this->paygent_request->paygent_process_refund( $order_id, $amount, $telegram_array, $permit_statuses, $send_data_refund, $this );
	}

	/**
	 * Process the order status when the order is completed.
	 *
	 * @param int $order_id Order ID.
	 */
	public function order_mb_status_completed( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order->get_payment_method() === $this->id ) {
			if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order ) ) {
				$career_type = $order->get_meta( '_career_type', true );
				if ( 'au' !== $career_type ) {
					$telegram_kind                        = '121';
					$send_sales_data['running_id']        = $order->get_meta( 'running_id', true );
					$send_sales_data['running_target_ym'] = date_i18n( 'Ym' );
				}
			} else {
				$telegram_kind   = '101';
				$send_sales_data = array();
			}
			if ( isset( $telegram_kind ) && isset( $send_sales_data ) ) {
				$this->paygent_request->order_paygent_status_completed( $order_id, $telegram_kind, $this, $send_sales_data );
			}
		}
	}

	/**
	 * Add allowed redirect hosts for Paygent payment gateway
	 *
	 * @param array $hosts Array of allowed hosts.
	 * @return array Modified array of allowed hosts.
	 */
	public function add_allowed_redirect_hosts( $hosts ) {
		// Add Paygent payment gateway domains.
		$paygent_hosts = array(
			'connect.auone.jp',        // au Easy Payment.
			'id.smt.docomo.ne.jp',     // Docomo Payment.
			'link.paygent.co.jp',      // Paygent general domain.
		);

		return array_merge( $hosts, $paygent_hosts );
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
	 * Display payment information on the management screen
	 *
	 * @param string|object $post_type_or_order Post type/screen ID or WC_Order object (for HPOS).
	 * @param object|null   $post WP_Post object (for traditional) or null (for HPOS).
	 * @return void
	 */
	public function paygent_mb_add_meta_box( $post_type_or_order, $post = null ) {
		// Get order ID based on context (traditional or HPOS).
		$order_id = null;
		$order    = null;

		// HPOS: First parameter is a WC_Order object.
		if ( $post_type_or_order instanceof WC_Order ) {
			$order    = $post_type_or_order;
			$order_id = $order->get_id();
		} elseif ( $post && isset( $post->ID ) ) {
			// Traditional: $post is a WP_Post object.
			$order_id = $post->ID;
			$order    = wc_get_order( $order_id );
		}

		// Check if we have a valid order.
		if ( ! $order || ! $order_id ) {
			return;
		}

		// Check if this is the correct payment method.
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}

		// Check if HPOS is enabled.
		$hpos_enabled = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled();

		// Check if this is a subscription.
		$is_subscription = function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order );

		if ( $is_subscription ) {
			// Determine screen for subscription.
			$screen = $hpos_enabled ? 'woocommerce_page_wc-orders--shop_subscription' : 'shop_subscription';

			// Add payment information meta box for subscription.
			add_meta_box(
				'paygent-information',
				__( 'Payment Information', 'woocommerce-for-paygent-payment-main' ),
				array( &$this, 'paygent_mb_payment_information' ),
				$screen,
				'side',
				'default'
			);

			// Add running status meta box for subscription.
			add_meta_box(
				'paygent-running-type',
				__( 'Running Status', 'woocommerce-for-paygent-payment-main' ),
				array( &$this, 'paygent_mb_running_status' ),
				$screen,
				'side',
				'default'
			);
		} else {
			// Determine the screen ID for regular orders.
			$screen = $hpos_enabled ? 'woocommerce_page_wc-orders' : 'shop_order';

			// Add meta box for regular orders.
			add_meta_box(
				'paygent-information',
				__( 'Payment Information', 'woocommerce-for-paygent-payment-main' ),
				array( &$this, 'paygent_mb_payment_information' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	/**
	 * Display payment information on the management screen.
	 */
	public function paygent_mb_payment_information() {
		if ( isset( $_GET['post'] ) ) {// phpcs:ignore
			$order_id = $_GET['post'];// phpcs:ignore
		} elseif ( isset( $_GET['id'] ) ) {// phpcs:ignore
			$order_id = $_GET['id'];// phpcs:ignore
		} else {
			return;// No order ID found.
		}
		$order       = wc_get_order( $order_id );
		$career_type = $order->get_meta( '_career_type', true );
		echo '<div id="career_type_wrapper">';
		if ( $career_type ) {
			esc_html_e( 'Career type', 'woocommerce-for-paygent-payment-main' );
			echo ': ' . esc_html( $career_type );
		} else {
			esc_html_e( 'This order does not have Career type.', 'woocommerce-for-paygent-payment-main' );
		}
		echo '</div>';
		$trading_id = $order->get_meta( 'trading_id', true );
		echo '<div id="trading_id_wrapper">';
		if ( $trading_id ) {
			esc_html_e( 'Trading ID', 'woocommerce-for-paygent-payment-main' );
			echo ': ' . esc_html( $trading_id );
		} else {
			esc_html_e( 'This order does not have Trading ID.', 'woocommerce-for-paygent-payment-main' );
		}
		echo '</div>';
	}

	/**
	 * Display the running status of the subscription.
	 */
	public function paygent_mb_running_status() {
		if ( isset( $_GET['post'] ) ) {// phpcs:ignore
			$order_id = $_GET['post'];// phpcs:ignore
		} elseif ( isset( $_GET['id'] ) ) {// phpcs:ignore
			$order_id = $_GET['id'];// phpcs:ignore
		} else {
			return;// No order ID found.
		}
		$order      = wc_get_order( $order_id );
		$running_id = $order->get_meta( 'running_id', true );
		echo '<div id="running_id_wrapper">';
		if ( $running_id ) {
			esc_html_e( 'Running ID', 'woocommerce-for-paygent-payment-main' );
			echo ': ' . esc_html( $running_id );
			$telegram_kind           = '125';
			$send_data['running_id'] = $running_id;
			$send_data['trading_id'] = $order->get_meta( 'trading_id', true );
			$response                = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );
			echo '</div>';
			echo '<div id="running_status_wrapper">';
			if ( '0' === $response['result'] && $response['result_array'] ) {
				$running_status = $response['result_array'][0]['running_status'];
				$display_texts  = array(
					15 => __( 'Application interruption', 'woocommerce-for-paygent-payment-main' ),
					20 => __( 'Subscription is ongoing', 'woocommerce-for-paygent-payment-main' ),
					40 => __( 'Subscription ends', 'woocommerce-for-paygent-payment-main' ),
					50 => __( 'Subscription cancelled', 'woocommerce-for-paygent-payment-main' ),
				);
				if ( isset( $display_texts[ $running_status ] ) ) {
					$display_text = $display_texts[ $running_status ];
				} else {
					$display_text = $running_status;
				}
				echo esc_html__( 'Running status', 'woocommerce-for-paygent-payment-main' ) . ': ' . esc_html( $display_text );
			} else {
				echo 'Error';
			}
		} else {
			esc_html_e( 'This order does not have Running ID.', 'woocommerce-for-paygent-payment-main' );
		}
		echo '</div>';
	}

	/**
	 * Handle cart cancellation when a mobile payment is cancelled.
	 *
	 * Checks for the mb_cancel parameter in the URL and displays a notice to the customer.
	 * Also updates the order status to cancelled if the order can be found.
	 */
	public function paygent_cart_cancel() {
		if ( isset( $_GET['mb_cancel'] ) && 'yes' === $_GET['mb_cancel'] ) { // phpcs:ignore
			// Display a notice to the customer that their mobile payment was canceled.
			wc_add_notice( __( 'Your mobile payment has been canceled.', 'woocommerce-for-paygent-payment-main' ), 'notice' );
			$order_id = preg_replace( '/[^0-9]/', '', $_GET['trading_id'] ); // phpcs:ignore
			$order    = wc_get_order( $order_id );
			if ( $order ) {
				$order->update_status( 'cancelled', __( 'Mobile payment was canceled.', 'woocommerce-for-paygent-payment-main' ) );
			}
		}
	}
}
