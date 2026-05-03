<?php
/**
 * Paygent Endpoint
 *
 * @package PaygentForWooCommerce
 * @version 2.4.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ArtisanWorkshop\PluginFramework\v2_0_13 as Framework;

/**
 * WC_Paygent_Endpoint class.
 *
 * @version 2.4.8
 */
class WC_Paygent_Endpoint {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'paygent_register_routes' ) );
	}

	/**
	 * Callback.
	 */
	public function paygent_register_routes() {
		// POST /wp-json/paygent/v1/check/ .
		register_rest_route(
			'paygent/v1',
			'/check',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'paygent_check_webhook' ),
				'permission_callback' => array( $this, 'paygent_permission_callback' ),
			)
		);
	}

	/**
	 * Paygent Webhook response.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return void
	 */
	public function paygent_check_webhook( $request ) {
		$jp4wc_framework = new Framework\JP4WC_Framework();

		$body_data = $request->get_body();
		$get_array = $jp4wc_framework->jp4wc_url_to_array( $body_data );
		// Debug.
		$message_log = 'This is payment notice from Paygent.' . "\n" . $body_data . "\n";
		$jp4wc_framework->jp4wc_debug_log( $message_log, true, 'wc-paygent' );

		$payment_status = $get_array['payment_status'];
		$payment_type   = $get_array['payment_type'];

		if ( isset( $get_array['trading_id'] ) && '' !== $get_array['trading_id'] ) {
			$order_id = preg_replace( '/[^0-9]/', '', $get_array['trading_id'] );
			$order    = wc_get_order( $order_id );
			if ( $order ) {
				$order_payment_method = $order->get_payment_method();
				// Debug check by payment method.
				$debug = false;
				if ( isset( $order_payment_method ) ) {
					$option_name = 'woocommerce_' . $order_payment_method . '_settings';
					$get_setting = get_option( $option_name );
					$debug       = $get_setting['debug'] ?? false;
				}
				// Debug.
				$get_log_array = array();
				foreach ( $get_array as $key => $value ) {
					if ( isset( $value ) ) {
						$get_log_array[ $key ] = $value;
					}
				}
				$message_log = 'This is payment notice from Paygent to array' . "\n" . $jp4wc_framework->jp4wc_array_to_message( $get_log_array );
				$jp4wc_framework->jp4wc_debug_log( $message_log, $debug, 'wc-paygent' );
				if ( isset( $payment_type ) ) {
					switch ( $payment_type ) {
						case 01:// ATM.
							break;
						case 02:// Credit Card.
							$this->paygent_cc_webhook( $order, $get_array );
							break;
						case 03:// Convenience store(Number).
							$this->paygent_cv_webhook( $order, $get_array );
							break;
						case 05:// Bank Net.
							$this->paygent_bn_webhook( $order, $get_array );
							break;
						case 06:// Carrier payment.
							$this->paygent_mb_webhook( $order, $get_array );
							break;
						case 22:// Paidy.
							$this->paygent_paidy_webhook( $order, $get_array );
							break;
						case 26:// PayPay.
							$this->paygent_paypay_webhook( $order, $get_array );
							break;
						case 17:// Rakuten Pay.
							$this->paygent_rakutenpay_webhook( $order, $get_array );
							break;
					}
				}
				$status_array = $this->paygent_payment_status_array();
				if ( isset( $status_array[ $payment_status ] ) ) {
					// translators: %s: payment status.
					$order->add_order_note( sprintf( __( 'I received a payment information inquiry telegram with a payment status of %s.', 'woocommerce-for-paygent-payment-main' ), $status_array[ $payment_status ] . ':' . $payment_status ) );
				} else {
					$order->add_order_note( 'No payment status from paygent. payment_status:' . $payment_status . 'payment_type:' . $payment_type );
				}
			} else {
				// Debug.
				$message_log = __( 'Trading ID was not received.', 'woocommerce-for-paygent-payment-main' ) . "\n" . $body_data . "\n";
				$jp4wc_framework->jp4wc_debug_log( $message_log, true, 'wc-paygent' );
			}
		} else {
			// Debug check by Credit Card Payment.
			$option_name = 'woocommerce_paygent_cc_settings';
			$get_setting = get_option( $option_name );
			$debug       = $get_setting['debug'] ?? false;
			if ( '10' === $get_array['payment_status'] ) {// Validity confirmed.
				$message = __( 'Validity confirmed', 'woocommerce-for-paygent-payment-main' ) . "\n";
			} else {
				$message = __( 'Validity confirmation NG', 'woocommerce-for-paygent-payment-main' ) . "\n" . 'payment_status:' . $get_array['payment_status'] . "\n";
			}
			$message .= $jp4wc_framework->jp4wc_array_to_message( $get_array );
			$jp4wc_framework->jp4wc_debug_log( $message, $debug, 'wc-paygent' );
		}

		if ( empty( $request ) ) {
			$message = 'No data,but this site get the request from paygent.';
			$jp4wc_framework->jp4wc_debug_log( $message, 'yes', 'wc-paygent' );
		}

		header( 'Content-type: text/plain; charset=utf-8' );
		echo 'result=0';
	}

	/**
	 * Check if the request is permitted.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool True if the request is permitted, false otherwise.
	 */
	public function paygent_permission_callback( $request ) {
		$is_permitted_ips = apply_filters(
			'paygent_permitted_ips',
			array(
				'27.110.52.4', // Add Paygent IP address.
				'202.232.189.65', // SandBox IP address.
			)
		);

		// Get remote IP address from various sources.
		$remote_ip = '';

		// Check REMOTE_ADDR first as it's the most reliable and cannot be spoofed.
		// Only use X-Real-IP and X-Forwarded-For as fallback methods.
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$remote_ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		} elseif ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			$remote_ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded_ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$remote_ip     = trim( $forwarded_ips[0] );
		}

		$is_permitted = false;

		if ( ! empty( $remote_ip ) && in_array( $remote_ip, $is_permitted_ips, true ) ) {
			$is_permitted = true;
		}

		// X-Forwarded-For is user-controlled and can be spoofed, so it is disabled by default.
		// Enable only if the site runs behind a trusted reverse proxy via this filter.
		if ( ! $is_permitted && apply_filters( 'paygent_allow_x_forwarded_for_ip_check', false ) && isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded_ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			foreach ( $forwarded_ips as $ip ) {
				$ip = trim( $ip );
				if ( in_array( $ip, $is_permitted_ips, true ) ) {
					$is_permitted = true;
					break;
				}
			}
		}

		if ( ! $is_permitted ) {
			$wc_logger = wc_get_logger();
			$wc_logger->info(
				__( 'Paygent IP permission error.', 'woocommerce-for-paygent-payment-main' ),
				array(
					'remote_ip' => $remote_ip,
					'source'    => 'paygent-endpoint',
				)
			);
			if ( get_transient( 'paygent_ip_permission_error_sent' ) ) {
				return false;
			}

			$to           = 'wp-admin@artws.info';
			$subject      = 'Paygent IP permission error';
			$message      = 'Paygent IP permission error occurred. Please check the IP address settings in the Paygent plugin settings.' . "\n\n" .
			'Remote IP: ' . $remote_ip . "\n\n";
			$headers      = $request->get_headers();
			$request_data = '';
			foreach ( $headers as $key => $value ) {
				$value_str     = is_array( $value ) ? implode( ', ', $value ) : $value;
				$request_data .= esc_html( "$key: $value_str\n" );
			}
			$message    .= "\nRequest Data:\n" . $request_data;
			$server_info = '';
			foreach ( $_SERVER as $key => $value ) {
				$clean_value  = is_array( $value ) ? wp_json_encode( $value ) : (string) $value;
				$server_info .= "$key: $clean_value\n";
			}
			$message .= "\nServer Information:\n" . $server_info;
			$headers  = array( 'Content-Type: text/plain; charset=UTF-8' );
			wp_mail( $to, $subject, $message, $headers );

			set_transient( 'paygent_ip_permission_error_sent', true, 1440 * MINUTE_IN_SECONDS );
		}

		return $is_permitted;
	}

	/**
	 * Paygent update status by endpoint action.
	 *
	 * @param object $order WP_Order object.
	 * @param string $status default set are pending, on-hold, processing, completed, cancelled, refunded, failed.
	 */
	public function paygent_update_status_webhook( $order, $status ) {
		if ( 'not_set' === $status ) {
			return;
		}

		$current_status = $order->get_status();
		if ( 'pre-ordered' === $current_status ) {
			$order->add_order_note( __( 'This order is pre-order. Notice status is ', 'woocommerce-for-paygent-payment-main' ) . $status );
			return;
		}

		$order_type  = $order->get_type();
		$active_flag = false;
		if ( $current_status !== $status ) {
			$normal_flag = true;
			if ( 'shop_order' === $order_type ) {
				$base_status = array(
					0 => 'pending',
					1 => 'on-hold',
					2 => 'processing',
					3 => 'completed',
				);
			} elseif ( 'shop_subscription' === $order_type ) {
				$base_status = array(
					0 => 'pending',
					1 => 'on-hold',
					2 => 'pending-cancel',
					3 => 'active',
					4 => 'expired',
					5 => 'cancelled',
				);
				if ( 'active' === $current_status ) {
					$active_flag = true;
				}
			} else {
				$order->add_order_note( __( 'This order type does not support Paygent payment.', 'woocommerce-for-paygent-payment-main' ) );
				return;
			}
			if ( $active_flag && 'processing' === $status ) {
				$order->add_order_note( __( 'Since the subscription is activated, the payment order is also activated.', 'woocommerce-for-paygent-payment-main' ) );
				return;
			}
			$current_status_id = array_search( $order->get_status(), $base_status, true );
			$all_statuses      = wc_get_order_statuses();
			if ( in_array( $status, $base_status, true ) && $current_status_id ) {
				$status_id = array_search( $status, $base_status, true );
				if ( (int) $status_id < (int) $current_status_id ) {
					$order->add_order_note( __( 'Order status change due to Paygent notification is abnormal. Please confirm it.', 'woocommerce-for-paygent-payment-main' ) );
					$normal_flag = false;
				}
			}
			$next_status          = '';
			$current_status_title = $all_statuses[ 'wc-' . $current_status ];
			$status_title         = $all_statuses[ 'wc-' . $status ];
			if ( 'all_refunded' === $status ) {
				$status_title = __( 'Sales canceled', 'woocommerce-for-paygent-payment-main' );
				$next_status  = 'refunded';
			}
			// translators: %1$s: current status, %2$s: new status.
			$status_message = sprintf( __( 'The current status of this order is %1$s. I received an application from Paygent and changed the status to %2$s.', 'woocommerce-for-paygent-payment-main' ), $current_status_title, $status_title );
			if ( 'refunded' === $next_status ) {
				$order->update_status( $next_status, $status_message );
			} elseif ( 'refunded' === $status || false === $normal_flag ) {
				$order->add_order_note( $status_message );
			} else {
				$order->update_status( $status, $status_message );
			}
		}
	}

	/**
	 * Paygent Credit Card response.
	 *
	 * @param object $order WP_Order.
	 * @param array  $get_array Paygent response.
	 */
	public function paygent_cc_webhook( $order, $get_array ) {
		if ( isset( $get_array['payment_status'] ) ) {
			$payment_status = $get_array['payment_status'];
			switch ( $payment_status ) {
				case '10':// Apply.
					$this->paygent_update_status_webhook( $order, 'pending' );
					break;
				case '11':// Approval failure.
					$this->paygent_update_status_webhook( $order, 'cancelled' );
					break;
				case '13':// 3D secure failure.
					$this->paygent_update_status_webhook( $order, 'cancelled' );
					break;
				case '20':// Authority OK.
					$this->paygent_update_status_webhook( $order, 'processing' );
					break;
				case '32':// Approval revoked.
					$this->paygent_update_status_webhook( $order, 'cancelled' );
					break;
				case '33':// Authorization expired.
					$this->paygent_update_status_webhook( $order, 'cancelled' );
					break;
				case '40':// Sales Completed.
					$paygent_cc = new WC_Gateway_Paygent_CC();
					if ( 'sale' !== $paygent_cc->paymentaction ) {
						$this->paygent_update_status_webhook( $order, 'completed' );
					}
					break;
				case '60':// Sales canceled.
					if ( $order->get_transaction_id() === $get_array['payment_id'] ) {
						$this->paygent_update_status_webhook( $order, 'all_refunded' );
					} else {
						$this->paygent_update_status_webhook( $order, 'refunded' );
					}
					break;
			}
		}
	}

	/**
	 * Paygent convenience store response.
	 *
	 * @param object $order WP_Order.
	 * @param array  $get_array Paygent response.
	 */
	public function paygent_cv_webhook( $order, $get_array ) {
		if ( isset( $get_array['payment_status'] ) ) {
			switch ( $get_array['payment_status'] ) {
				case '10':// Apply.
					$this->paygent_update_status_webhook( $order, 'on-hold' );
					break;
				case '12':// Expired payment.
					$this->paygent_update_status_webhook( $order, 'cancelled' );
					break;
				case '40':// Sales Completed.
					$this->paygent_update_status_webhook( $order, 'processing' );
					break;
				case '43':// Breaking news detected.
					$this->paygent_update_status_webhook( $order, 'processing' );
					break;
				case '61':// Breaking news canceled.
					$this->paygent_update_status_webhook( $order, 'cancelled' );
					break;
			}
		}
	}

	/**
	 * Paygent Bank Net response.
	 *
	 * @param object $order WP_Order.
	 * @param array  $get_array Paygent response.
	 */
	public function paygent_bn_webhook( $order, $get_array ) {
		if ( isset( $get_array['payment_status'] ) ) {
			switch ( $get_array['payment_status'] ) {
				case '10':// Apply.
					$this->paygent_update_status_webhook( $order, 'on-hold' );
					break;
				case '15':// Application interruption.
					$this->paygent_update_status_webhook( $order, 'cancelled' );
					break;
				case '40':// Sales Completed.
					$this->paygent_update_status_webhook( $order, 'processing' );
					break;
			}
		}
	}

	/**
	 * Paygent Carrier payment response.
	 *
	 * @param object $order WP_Order.
	 * @param array  $get_array Paygent response.
	 */
	public function paygent_mb_webhook( $order, $get_array ) {
		if ( isset( $get_array['payment_status'] ) ) {
			if ( ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) ) ||
				( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order ) ) ) {
				$renewal_status      = 'not_set';
				$subscription_status = 'not_set';
				switch ( $get_array['payment_status'] ) {
					case '10':// Apply.
						$renewal_status      = 'on-hold';
						$subscription_status = 'on-hold';
						break;
					case '15':// Application interruption.
						$renewal_status      = 'cancelled';
						$subscription_status = 'cancelled';
						break;
					case '20':// Authority OK.
						$renewal_status      = 'processing';
						$subscription_status = 'active';
						break;
					case '21':// Authority complete.
						$renewal_status      = 'processing';
						$subscription_status = 'not_set';
						break;
					case '32':// Approval revoked.
						$renewal_status      = 'cancelled';
						$subscription_status = 'cancelled';
						break;
					case '33':// Authorization expired.
						$renewal_status      = 'cancelled';
						$subscription_status = 'not_set';
						break;
					case '36':// Sales hold.
						$renewal_status      = 'on-hold';
						$subscription_status = 'on-hold';
						break;
					case '40':// Sales Completed.
						$renewal_status      = 'processing';
						$subscription_status = 'active';
						break;
					case '41':// Sales Completed (no change more).
						$renewal_status      = 'not_set';
						$subscription_status = 'not_set';
						break;
					case '43':// Breaking news detected.
						$renewal_status      = 'processing';
						$subscription_status = 'active';
						break;
					case '44':// Sales Completed.
						$renewal_status      = 'processing';
						$subscription_status = 'active';
						break;
					case '50':// Sales canceled.
						$renewal_status      = 'cancelled';
						$subscription_status = 'cancelled';
						break;
					case '60':// Sales canceled.
						$renewal_status      = 'refunded';
						$subscription_status = 'cancelled';
						break;
					case '62':// Cancellation completed.
						$renewal_status      = 'cancelled';
						$subscription_status = 'cancelled';
						break;
				}
				$this->paygent_update_status_webhook( $order, $renewal_status );
				$subscriptions = wcs_get_subscriptions_for_order( $order );
				foreach ( $subscriptions as $subscription ) {
					$this->paygent_update_status_webhook( $subscription, $subscription_status );
				}
			} elseif ( $order->get_type() === 'shop_order' ) {
				switch ( $get_array['payment_status'] ) {
					case '10':// Apply.
						$this->paygent_update_status_webhook( $order, 'on-hold' );
						break;
					case '15':// Application interruption.
						$this->paygent_update_status_webhook( $order, 'cancelled' );
						break;
					case '20':// Authority OK.
						$this->paygent_update_status_webhook( $order, 'processing' );
						break;
					case '21':// Authority complete.
						$this->paygent_update_status_webhook( $order, 'processing' );
						break;
					case '32':// Approval revoked.
						$this->paygent_update_status_webhook( $order, 'cancelled' );
						break;
					case '33':// Authorization expired.
						$this->paygent_update_status_webhook( $order, 'cancelled' );
						break;
					case '36':// Sales hold.
						$this->paygent_update_status_webhook( $order, 'on-hold' );
						break;
					case '40':// Sales Completed.
						$this->paygent_update_status_webhook( $order, 'processing' );
						break;
					case '41':// Sales Completed (no change more).
						$this->paygent_update_status_webhook( $order, 'not_set' );
						break;
					case '43':// Breaking news detected.
						$this->paygent_update_status_webhook( $order, 'processing' );
						break;
					case '44':// Sales Completed.
						$this->paygent_update_status_webhook( $order, 'processing' );
						break;
					case '60':// Sales canceled.
						$this->paygent_update_status_webhook( $order, 'refunded' );
						break;
					case '62':// Cancellation completed.
						$this->paygent_update_status_webhook( $order, 'cancelled' );
						break;
				}
			} elseif ( $order->get_type() === 'shop_subscription' ) {
				switch ( $get_array['payment_status'] ) {
					case '10':// Apply.
						$this->paygent_update_status_webhook( $order, 'on-hold' );
						break;
					case '15':// Application interruption.
						$this->paygent_update_status_webhook( $order, 'cancelled' );
						break;
					case '20':// Authority OK.
						$this->paygent_update_status_webhook( $order, 'active' );
						break;
					case '21':// Authority complete.
						$this->paygent_update_status_webhook( $order, 'not_set' );
						break;
					case '33':// Authorization expired.
						$this->paygent_update_status_webhook( $order, 'not_set' );
						break;
					case '40':// Sales Completed.
						$this->paygent_update_status_webhook( $order, 'active' );
						break;
					case '50':// Sales canceled.
						$this->paygent_update_status_webhook( $order, 'cancelled' );
						break;
					case '60':// Sales canceled.
						$this->paygent_update_status_webhook( $order, 'cancelled' );
						break;
				}
			}
		}
	}

	/**
	 * Paygent Paidy response.
	 *
	 * @param object $order WP_Order.
	 * @param array  $get_array Paygent response.
	 */
	public function paygent_paidy_webhook( $order, $get_array ) {
		if ( isset( $get_array['payment_status'] ) ) {
			switch ( $get_array['payment_status'] ) {
				case '20':// Authority OK.
					$this->paygent_update_status_webhook( $order, 'processing' );
					break;
				case '30':// Requesting sales.
					$this->paygent_update_status_webhook( $order, 'processing' );
					break;
				case '31':// The authority is being canceled.
					$this->paygent_update_status_webhook( $order, 'cancelled' );
					break;
				case '32':// Approval revoked.
					$this->paygent_update_status_webhook( $order, 'cancelled' );
					break;
				case '33':// Authorization expired.
					$this->paygent_update_status_webhook( $order, 'cancelled' );
					break;
				case '40':// Cleared.
					$this->paygent_update_status_webhook( $order, 'completed' );
					break;
				case '41':// Cleared (no change more).
					$this->paygent_update_status_webhook( $order, 'not_set' );
					break;
			}
		}
	}

	/**
	 * Paygent PayPay response.
	 *
	 * @param object $order WP_Order.
	 * @param array  $get_array Paygent response.
	 */
	public function paygent_paypay_webhook( $order, $get_array ) {
		if ( isset( $get_array['payment_status'] ) ) {
			switch ( $get_array['payment_status'] ) {
				case '10':// Applied.
					$this->paygent_update_status_webhook( $order, 'pending' );
					break;
				case '15':// Application suspension.
					$this->paygent_update_status_webhook( $order, 'cancelled' );
					break;
				case '40':// Cleared.
					$this->paygent_update_status_webhook( $order, 'processing' );
					break;
				case '41':// Cleared (no change more).
					$this->paygent_update_status_webhook( $order, 'not_set' );
					break;
				case '60':// Refund.
					$this->paygent_update_status_webhook( $order, 'all_refunded' );
					break;
			}
		}
	}

	/**
	 * Paygent RakutenPay response.
	 *
	 * @param object $order WP_Order.
	 * @param array  $get_array Paygent response.
	 */
	public function paygent_rakutenpay_webhook( $order, $get_array ) {
		if ( isset( $get_array['payment_status'] ) ) {
			switch ( $get_array['payment_status'] ) {
				case '15':// Application suspension.
					$this->paygent_update_status_webhook( $order, 'cancelled' );
					break;
				case '20':// Authority OK.
					$this->paygent_update_status_webhook( $order, 'processing' );
					break;
				case '40':// Cleared.
					$this->paygent_update_status_webhook( $order, 'processing' );
					break;
				case '32':// Approval revoked.
					$this->paygent_update_status_webhook( $order, 'cancelled' );
					break;
				case '60':// Refund.
					$this->paygent_update_status_webhook( $order, 'all_refunded' );
					break;
				case '33':// Authorization expired.
					$this->paygent_update_status_webhook( $order, 'cancelled' );
					break;
				case '41':// Sales Completed (no change more).
					$this->paygent_update_status_webhook( $order, 'not_set' );
					break;
			}
		}
	}

	/**
	 * Get the array of payment statuses.
	 *
	 * @return array Array of payment statuses.
	 */
	public function paygent_payment_status_array() {
		return array(
			'10' => __( 'Apply', 'woocommerce-for-paygent-payment-main' ),
			'11' => __( 'Approval failure', 'woocommerce-for-paygent-payment-main' ),
			'12' => __( 'Expired payment', 'woocommerce-for-paygent-payment-main' ),
			'13' => __( '3D secure failure', 'woocommerce-for-paygent-payment-main' ),
			'15' => __( 'Application interruption', 'woocommerce-for-paygent-payment-main' ),
			'20' => __( 'Authority OK', 'woocommerce-for-paygent-payment-main' ),
			'30' => __( 'Requesting sales', 'woocommerce-for-paygent-payment-main' ),
			'31' => __( 'The authority is being canceled', 'woocommerce-for-paygent-payment-main' ),
			'32' => __( 'Approval revoked', 'woocommerce-for-paygent-payment-main' ),
			'33' => __( 'Authorization expired', 'woocommerce-for-paygent-payment-main' ),
			'36' => __( 'Sales hold', 'woocommerce-for-paygent-payment-main' ),
			'40' => __( 'Sales Completed', 'woocommerce-for-paygent-payment-main' ),
			'41' => __( 'Sales Completed (no change more)', 'woocommerce-for-paygent-payment-main' ),
			'43' => __( 'Breaking news detected', 'woocommerce-for-paygent-payment-main' ),
			'44' => __( 'Sales Completed', 'woocommerce-for-paygent-payment-main' ),
			'60' => __( 'Sales canceled', 'woocommerce-for-paygent-payment-main' ),
			'61' => __( 'Breaking news canceled', 'woocommerce-for-paygent-payment-main' ),
			'62' => __( 'Cancellation completed', 'woocommerce-for-paygent-payment-main' ),
		);
	}
}
