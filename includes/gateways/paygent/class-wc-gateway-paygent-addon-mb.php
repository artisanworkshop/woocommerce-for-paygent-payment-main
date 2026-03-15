<?php
/**
 * WooCommerce Paygent Mobile Payment Gateway with Subscription Support
 *
 * @package WooCommerce\Gateways\Paygent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class WC_Gateway_Paygent_Addon_MB
 *
 * Extends the Paygent Mobile payment gateway to add subscription support.
 *
 * @extends WC_Gateway_Paygent_MB
 */
class WC_Gateway_Paygent_Addon_MB extends WC_Gateway_Paygent_MB {

	/**
	 * Constructor for the gateway.
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() {
		parent::__construct();

		if ( class_exists( 'WC_Subscriptions_Order' ) ) {
			add_action( 'woocommerce_subscription_status_updated', array( $this, 'subscription_status_updated' ), 5, 3 );
			add_action( 'woocommerce_customer_changed_subscription_to_cancelled', array( $this, 'paygent_customer_changed_subscription_to_cancelled' ) );
			add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_mb_payment' ), 10, 2 );
			add_filter( 'wcs_view_subscription_actions', array( $this, 'paygent_mb_change_amout_payment' ), 10, 2 );
			add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'change_subscriptions_price' ) );
			add_action( 'woocommerce_subscription_checkout_switch_order_processed', array( $this, 'paygent_mb_wcs_switch_upgrade_order' ), 10, 2 );
			add_action( 'woocommerce_review_order_after_order_total', array( $this, 'paygent_mb_checkout_explain' ), 100 );
			add_action( 'woocommerce_payment_complete', array( $this, 'paygent_mb_wcs_switch_upgrade_action' ), 10, 2 );
		}
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'mb_subscriptions_thankyou' ) );
	}

	/**
	 * Process the payment.
	 *
	 * @param  int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $this->is_subscription( $order_id ) ) {
			return $this->process_subscription( $order );
		} else {
			return parent::process_payment( $order_id );
		}
	}

	/**
	 * Is $order_id a subscription?
	 *
	 * @param  int $order_id Order ID.
	 * @return boolean
	 */
	protected function is_subscription( $order_id ) {
		return ( function_exists( 'wcs_order_contains_subscription' ) && ( wcs_order_contains_subscription( $order_id ) || wcs_is_subscription( $order_id ) || wcs_order_contains_renewal( $order_id ) ) );
	}

	/**
	 * Check if this is the first order in a subscription
	 *
	 * @param object $order WC_order object.
	 * @return boolean
	 */
	public function no_fisrt_subscription( $order ) {
		$order_id         = $order->get_id();
		$career_type_flag = false;
		if ( $this->is_subscription( $order_id ) ) {
			$set_array                  = array(
				'customer_id'            => $order->get_user_id(),
				'subscription_status'    => array( 'active' ),
				'subscriptions_per_page' => -1,
			);
			$current_user_subscriptions = wcs_get_subscriptions( $set_array );
			foreach ( $current_user_subscriptions as $key => $subscription ) {
				$count = 0;
				if ( $subscription->get_payment_method() === $this->id && $subscription->get_meta( 'career_type' ) === $order->get_meta( 'career_type' ) ) {
					++$count;
				}
			}
			if ( $count > 1 ) {
				$career_type_flag = true;
			}
		}
		return $career_type_flag;
	}

	/**
	 * What to do when a subscription's status is updated
	 *
	 * @param object $subscription The subscription object.
	 * @param string $to (new status).
	 * @param string $from (old status).
	 * @return void
	 */
	public function subscription_status_updated( $subscription, $to, $from ) {
		if ( $subscription->get_payment_method() === $this->id ) {
			if ( 'pending-cancel' === $to && 'action' === $from ) {
				$this->cancel_to_paygent_mb_subscription( $subscription );
			}
		}
	}

	/**
	 * When the customer cancels(pending-cancel).
	 *
	 * @param object $subscription The subscription object.
	 * @param string $add_message Additional message.
	 * @return void
	 */
	public function cancel_to_paygent_mb_subscription( $subscription, $add_message = null ) {
		$subscription_id = $subscription->get_id();
		$running_id      = $subscription->get_meta( 'running_id', true );
		$telegram_kind   = '124';
		$send_data       = array();
		$send_data       = $this->set_send_data_for_subscription( $running_id, $subscription_id );
		unset( $send_data['other_url'] );
		unset( $send_data['amount'] );
		$career_type = $subscription->get_meta( '_career_type', true );
		if ( 'docomo' === $career_type ) {
			$send_data['user_certification_ryaku'] = 1;
		}
		$response = array();
		$response = $this->paygent_request->send_paygent_request( $this->test_mode, $subscription, $telegram_kind, $send_data, $this->debug );
		if ( '0' === $response['result'] && $response['result_array'] ) {
			$subscription->add_order_note( __( 'Success cancel subscription.', 'woocommerce-for-paygent-payment-main' ) . $add_message );
		} else {
			$this->paygent_request->error_response( $response, $subscription );
			$subscription->add_order_note( __( 'Fail cancel subscription.', 'woocommerce-for-paygent-payment-main' ) . $add_message );
			$this->jp4wc_framework->jp4wc_debug_log( 'Cancel subscription failed. Subscription ID: ' . $subscription_id, true, 'wc-paygent' );
			$this->jp4wc_framework->send_notice_email(
				get_option( 'admin_email' ),
				__( 'Cancel subscription failed', 'woocommerce-for-paygent-payment-main' ),
				__( 'Cancel subscription failed. Subscription ID: ', 'woocommerce-for-paygent-payment-main' ) . $subscription_id
			);
		}
	}

	/**
	 * When the customer cancels(pending-cancel).
	 *
	 * @param object $subscription The subscription object.
	 * @param float  $new_amount New amount.
	 * @param string $add_message Additional message.
	 * @return array
	 */
	public function change_amount_paygent_mb_subscription( $subscription, $new_amount, $add_message = null ) {
		$subscription_id     = $subscription->get_id();
		$running_id          = $subscription->get_meta( 'running_id', true );
		$telegram_kind       = '126';
		$send_data           = array();
		$send_data           = $this->set_send_data_for_subscription( $running_id, $subscription_id );
		$send_data['amount'] = $new_amount;
		unset( $send_data['other_url'] );
		$career_type = $subscription->get_meta( '_career_type', true );
		if ( 'docomo' === $career_type ) {
			$send_data['user_certification_ryaku'] = 1;
		}
		$response = array();
		$response = $this->paygent_request->send_paygent_request( $this->test_mode, $subscription, $telegram_kind, $send_data, $this->debug );
		if ( '0' === $response['result'] && $response['result_array'] ) {
			// translators: %s: new amount.
			$subscription->add_order_note( sprintf( __( 'Success change amount to %s.', 'woocommerce-for-paygent-payment-main' ), $new_amount ) . $add_message );
		} else {
			$this->paygent_request->error_response( $response, $subscription );
			$subscription->add_order_note( __( 'Fail change amount.', 'woocommerce-for-paygent-payment-main' ) . $add_message );
		}
		return $response;
	}

	/**
	 * Adjust variables sent for subscription status changes
	 *
	 * @param int $running_id Running ID.
	 * @param int $subscription_id Subscription ID.
	 * @return array
	 */
	public function set_send_data_for_subscription( $running_id, $subscription_id ) {
		$send_data               = array();
		$send_data['running_id'] = $running_id;
		$send_data               = $this->set_send_data( $send_data, $subscription_id );
		return $send_data;
	}

	/**
	 * Behavior when customers cancel by themself
	 *
	 * @param object $subscription The subscription object.
	 * @return void
	 */
	public function paygent_customer_changed_subscription_to_cancelled( $subscription ) {
		if ( $subscription->get_payment_method() === $this->id ) {
			$this->cancel_to_paygent_mb_subscription( $subscription, '(by Customer)' );
		}
	}

	/**
	 * Check if order contains subscriptions.
	 *
	 * @param  int $order_id Order ID.
	 * @return bool
	 */
	protected function order_contains_subscription( $order_id ) {
		return function_exists( 'wcs_order_contains_subscription' ) && ( wcs_order_contains_subscription( $order_id ) || wcs_order_contains_renewal( $order_id ) );
	}

	/**
	 * Process the subscription.
	 *
	 * @param object $order WC_order object.
	 * @return array
	 */
	protected function process_subscription( $order ) {
		$amount = $order->get_total();
		if ( isset( $amount ) && 0 === $amount ) {
			// Payment complete.
			$order->payment_complete();

			return array(
				'result'   => 'success',
				'redirect' => $order->get_checkout_payment_url( true ),
			);
		}

		$send_data = array();

		// Common header.
		if ( $this->is_subscription( $order->get_id() ) ) {
			$telegram_kind = '120';
		} elseif ( $this->no_fisrt_subscription( $order ) ) {
			$telegram_kind = '126';
		}
		$send_data = $this->set_send_data( $send_data, $order->get_id() );

		if ( 5 === $send_data['career_type'] ) {// Docomo.
			$career_type = 'docomo';
		} elseif ( 4 === $send_data['career_type'] ) {// au.
			$career_type = 'au';
		} elseif ( 6 === $send_data['career_type'] ) {// SoftBank.
			$career_type = 'sb';
		}
		$subscriptions        = wcs_get_subscriptions_for_order( $order );
		$current_subscription = false;
		foreach ( $subscriptions as $subscription_id => $subscription ) {
			if ( isset( $career_type ) ) {
				$subscription->update_meta_data( '_career_type', $career_type, true );
				$subscription->save();
				$current_subscription = $subscription;
			}
		}
		if ( ! $current_subscription ) {
			// translators: %s: order ID.
			$message = sprintf( __( 'No subscription found for order %s.', 'woocommerce-for-paygent-payment-main' ), $order->get_id() );
			if ( '120' === $telegram_kind ) {
				$message .= __( 'This subscription is first order.', 'woocommerce-for-paygent-payment-main' ) . $career_type;
			}
			$this->jp4wc_framework->jp4wc_debug_log( $message, true, 'wc-paygent' );
			wc_add_notice( $message, 'error' );
			$order->update_status( 'failed', $message );
			return array( 'result' => 'failed' );
		}
		$send_data['trading_id'] = $this->set_trading_id( $current_subscription );
		// amount hook.
		$send_data['amount'] = apply_filters( 'paygent_mb_amount', $send_data['amount'], $order, $career_type );

		$payment_response = $this->payment_mb_process( $order, $send_data, $telegram_kind );
		return $payment_response;
	}

	/**
	 * Set process_subscription_payment function.
	 *
	 * @param int    $amount (default: 0).
	 * @param object $order WC_order object.
	 * @return bool|WP_Error
	 */
	public function process_subscription_payment( $amount, $order ) {
		if ( isset( $amount ) && 0 === $amount ) {
			// Payment complete.
			$order->payment_complete();

			return true;
		}
		$subscription_id = $order->get_meta( '_subscription_renewal', true );
		$subscription    = wcs_get_subscription( $subscription_id );
		$running_id      = $subscription->get_meta( 'running_id', true );
		$trading_id      = $subscription->get_meta( 'trading_id', true );

		if ( isset( $running_id ) ) {
			$response = $this->paygent_mb_check_subscription_status( $running_id, $trading_id, $order );
			if ( '0' === $response['result'] && isset( $response['result_array'] ) ) {
				$running_status = $response['result_array'][0]['running_status'];

				if ( '10' === $running_status || '20' === $running_status ) {
					$payment_amount = $response['result_array'][0]['payment_amount'];
					if ( $order->get_total() !== $payment_amount ) {
						$message = __( 'The payment amount did not match. Amount is ', 'woocommerce-for-paygent-payment-main' ) . $payment_amount;
						$order->update_status( 'on-hold', $message );
						return false;
					}
					// Payment complete.
					$order->payment_complete();
					if ( 'yes' === $this->payment_status ) {
						$order->update_status( 'completed' );
					} else {
						$order->update_status( 'processing' );
					}
					return true;
				} else {
					// Failed.
					$message = __( 'Subscription MB Payment failed. Status is ', 'woocommerce-for-paygent-payment-main' ) . $running_status;
					try {
						$order->update_status( 'failed', $message );
						$subscription->update_status( 'cancelled' );
					} catch ( Exception $e ) {
						$message = $e->getMessage();
						$this->jp4wc_framework->jp4wc_debug_log( $message, true, 'wc-paygent' );
					}
					$order->add_order_note( $message );
					return false;
				}
			} else {
				$this->paygent_request->error_response( $response, $order );
				try {
					$message = __( 'Paygent status check is error.', 'woocommerce-for-paygent-payment-main' );
					$order->update_status( 'on-hold', $message );
					$subscription->update_status( 'on-hold', $message );
				} catch ( Exception $e ) {
					$message = $e->getMessage();
					$this->jp4wc_framework->jp4wc_debug_log( $message, true, 'wc-paygent' );
				}
				return false;
			}
		} else {
			// Failed.
			$message = __( 'Subscription MB Payment failed. No running ID.', 'woocommerce-for-paygent-payment-main' );
			$order->update_status( 'failed', $message );
			$subscription->update_status( 'cancelled' );
			return false;
		}
	}

	/**
	 * Check order status for Subscriptions function.
	 *
	 * @param int    $running_id Running ID.
	 * @param string $trading_id Trading ID.
	 * @param object $order WC_order object.
	 * @return array
	 */
	public function paygent_mb_check_subscription_status( $running_id, $trading_id, $order ) {
		$telegram_kind           = '125';
		$send_data['running_id'] = $running_id;
		$send_data['trading_id'] = $trading_id;
		$response                = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );
		return $response;
	}

	/**
	 * Refung order for Subscriptions function
	 *
	 * @param int    $running_id Running ID.
	 * @param string $trading_id Trading ID.
	 * @param string $running_target_ym YYYYMM.
	 * @param object $order WC_order object.
	 * @return array
	 */
	public function paygent_mb_refund_subscription( $running_id, $trading_id, $running_target_ym, $order ) {
		$telegram_kind                  = '122';
		$send_data['running_id']        = $running_id;
		$send_data['trading_id']        = $trading_id;
		$send_data['running_target_ym'] = $running_target_ym;
		$response                       = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );
		return $response;
	}

	/**
	 * Set scheduled_subscription_mb_payment function.
	 *
	 * @param float    $amount_to_charge The amount to charge.
	 * @param WC_Order $renewal_order A WC_Order object created to record the renewal payment.
	 */
	public function scheduled_subscription_mb_payment( $amount_to_charge, $renewal_order ) {
		$result = $this->process_subscription_payment( $amount_to_charge, $renewal_order );

		if ( is_wp_error( $result ) ) {
			// translators: %s: error message.
			$renewal_order->update_status( 'failed', sprintf( __( 'Paygent Transaction Failed (%s)', 'woocommerce-for-paygent-payment-main' ), $result->get_error_message() ) );
		}
	}

	/**
	 * Handles the thank you page for subscriptions.
	 *
	 * @param int $order_id Order ID.
	 */
	public function mb_subscriptions_thankyou( $order_id ) {
		$order          = wc_get_order( $order_id );
		$payment_method = $order->get_payment_method();
		if ( $payment_method === $this->id ) {
			if ( $this->is_subscription( $order_id ) && isset( $_GET['running_id'] ) ) {// phpcs:ignore
				$order->update_meta_data( 'running_id', $_GET['running_id'] );// phpcs:ignore
				if ( 'yes' === $this->update_status ) {
					$order->update_status( 'completed' );
				} else {
					$order->update_status( 'processing' );
				}
				$order->save_meta_data();
				$order->save();
			}
		}
	}

	/**
	 * Set the change amount action.
	 *
	 * @param array  $actions Array of actions.
	 * @param object $subscription The subscription object.
	 * @return array
	 */
	public function paygent_mb_change_amout_payment( $actions, $subscription ) {
		if ( $subscription->get_payment_method() === $this->id ) {
			$action_name              = _x( 'Change the amount', 'woocommerce-for-paygent-payment-main' );
			$actions['change_amount'] = array(
				'url'  => wp_nonce_url( add_query_arg( array( 'change_amount' => $subscription->get_id() ), $subscription->get_checkout_payment_url() ) ),
				'name' => $action_name,
			);
		}
		return $actions;
	}

	/**
	 * Change the subscription price.
	 *
	 * @param int $order_id Order ID.
	 */
	public function change_subscriptions_price( $order_id ) {
		$order      = wc_get_order( $order_id );
		$cancel_url = $this->jp4wc_framework->jp4wc_make_add_get_url( $order->get_checkout_payment_url( true ), array( 'change_cancel' => 'yes' ) );
		if ( isset( $_GET['change_amount'] ) ) {// phpcs:ignore
			$total = 0;
			$items = $order->get_items();
			foreach ( $items as $item ) {
				$total += $item->get_total();
				$total += $item->get_total_tax();
			}
			$payment_url = $this->jp4wc_framework->jp4wc_make_add_get_url(
				$order->get_checkout_payment_url( true ),
				array(
					'telegram_kind' => 104,
					'change_result' => 'yes',
				)
			);
			echo '<div>';
			echo '定期購入の支払い金額を' . number_format( $total ) . '円に変更しましたので以下の変更ボタンから支払金額変更をお願いいたします。<br/>';
			echo '上記記載の次回の支払いに' . number_format( $total ) . '円が請求されます。<br/>';
			echo '<br />';
			echo '<a href="' . esc_url( $payment_url ) . '">金額変更</a>';
			echo '</div>';
		} elseif ( isset( $_GET['change_result'] ) ) {// phpcs:ignore
			$return_url   = $this->jp4wc_framework->jp4wc_make_add_get_url(
				$order->get_checkout_payment_url( true ),
				array(
					'telegram_kind'    => 126,
					'change_completed' => 'yes',
				)
			);
			$redirect_url = $this->jp4wc_framework->jp4wc_make_add_get_url(
				$order->get_checkout_payment_url( true ),
				array(
					'telegram_kind'  => 126,
					'change_proceed' => 'yes',
				)
			);
			$send_data    = array();
			$send_data    = $this->set_send_data( $send_data, $order_id );
			unset( $send_data['amount'] );
			unset( $send_data['trading_id'] );
			unset( $send_data['payment_id'] );
			$send_data['cancel_url']   = $cancel_url;
			$send_data['return_url']   = $return_url;
			$send_data['redirect_url'] = $redirect_url;
			$telegram_kind             = '104';
			$response_user             = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );
			if ( '0' === $response_user['result'] && $response_user['result_array'] ) {
				echo esc_html( mb_convert_encoding( $response_user['result_array'][0]['redirect_html'], 'UTF-8', 'SJIS' ) );
			} else {
				echo '何か障害が発生いたしました。また、時間をおいて試してください。';
			}
		} elseif ( isset( $_GET['change_proceed'] ) && isset( $_GET['open_id'] ) ) {// phpcs:ignore
			$total = 0;
			$items = $order->get_items();
			foreach ( $items as $item ) {
				$total += $item->get_total();
				$total += $item->get_total_tax();
			}
			$send_data = array();
			$send_data = $this->set_send_data( $send_data, $order_id );
			unset( $send_data['payment_id'] );
			unset( $send_data['redirect_url'] );
			$return_url              = $this->jp4wc_framework->jp4wc_make_add_get_url(
				$order->get_checkout_payment_url( true ),
				array(
					'telegram_kind'    => 126,
					'change_completed' => 'yes',
				)
			);
			$send_data['return_url'] = $return_url;
			$send_data['cancel_url'] = $cancel_url;
			$send_data['running_id'] = $order->get_meta( 'running_id', true );
			$send_data['amount']     = $total;
			$send_data['open_id']    = $_GET['open_id'];// phpcs:ignore
			$telegram_kind           = '126';
			$response                = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );
			if ( '0' === $response['result'] && isset( $response['result_array'] ) ) {
				if ( isset( $response['result_array'][0]['redirect_html'] ) ) {
					echo esc_html( mb_convert_encoding( $response['result_array'][0]['redirect_html'], 'SJIS', 'UTF-8' ) );
				} else {
					esc_html_e( 'The procedure to change the amount failed.', 'woocommerce-for-paygent-payment-main' );
				}
			}
		} elseif ( isset( $_GET['change_completed'] ) ) {// phpcs:ignore
			echo 'completed';
		} elseif ( isset( $_GET['change_cancel'] ) ) {// phpcs:ignore
			echo 'cancel';
		}
	}

	/**
	 * Switch upgrade order
	 *
	 * @param object $order WC_order object.
	 * @param array  $switch_order_data Switch order data.
	 * @return void
	 */
	public function paygent_mb_wcs_switch_upgrade_order( $order, $switch_order_data ) {
		$payment_method  = $order->get_payment_method();
		$subscription_id = $order->get_meta( '_subscription_switch' );
		$meta            = $order->get_meta( '_subscription_switch_data' );
		if ( 'paygent_mb' === $payment_method ) {
			$items       = $order->get_items();
			$total_price = 0;
			foreach ( $items as $key => $item ) {
				if ( isset( $meta[ $subscription_id ]['switches'][ $key ]['switch_direction'] ) && 'upgrade' === $meta[ $subscription_id ]['switches'][ $key ]['switch_direction'] ) {
					$product = wc_get_product( $item->get_variation_id() );
					if ( 'yes' === get_option( 'woocommerce_prices_include_tax' ) ) {
						$price = wc_get_price_excluding_tax( $product ) * $item->get_quantity();
					} else {
						$price = $product->get_price() * $item->get_quantity();
					}
					$item->set_subtotal( $price );
					$item->set_total( $price );
					$item->set_meta_data( '_switched_subscription_price_prorated', $price );
					$item->save();
					$total_price += wc_get_price_including_tax( $product ) * $item->get_quantity();
				}
			}
			$order->set_total( $total_price );
			$order->calculate_taxes();
			$order->save();
		}
		$subscription = wcs_get_subscription( $subscription_id );
		$order->add_meta_data( '_paygent_mb_upgrade_flag', 'no' );
		$current_running_id = $subscription->get_meta( 'running_id', true );
		if ( $current_running_id ) {
			$order->add_meta_data( '_paygent_mb_old_running_id', $current_running_id );
		}
		$current_trading_id = $subscription->get_meta( 'trading_id', true );
		if ( $current_trading_id ) {
			$order->add_meta_data( '_paygent_mb_old_trading_id', $current_trading_id );
		}
		$order->save_meta_data();
	}

	/**
	 * Display a notice on the checkout page for subscription switches.
	 */
	public function paygent_mb_checkout_explain() {
		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( isset( $cart_item['subscription_switch'] ) && 'upgraded' === $cart_item['subscription_switch']['upgraded_or_downgraded'] ) {
				$notice = __( 'If you switch your subscription using carrier billing, you will be charged the full amount instead of the difference that is currently displayed. <br />You will then be refunded your current subscription cost. please confirm.', 'woocommerce-for-paygent-payment-main' );
				echo '<tr class="order-total paygent-mb-notice"><td colspan=2>' . esc_html( $notice ) . '</td></tr>';
			}
		}
	}

	/**
	 * Handles the switch upgrade action for subscriptions.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $transaction_id Transaction ID.
	 */
	public function paygent_mb_wcs_switch_upgrade_action( $order_id, $transaction_id ) {
		$order          = wc_get_order( $order_id );
		$upgrade_flag   = $order->get_meta( '_paygent_mb_upgrade_flag', true );
		$old_running_id = $order->get_meta( '_paygent_mb_old_running_id', true );
		$old_trading_id = $order->get_meta( '_paygent_mb_old_trading_id', true );
		if ( $upgrade_flag && $old_running_id && $old_trading_id ) {
			// Cancel Subscription MB.
			$subscriptions = wcs_get_subscriptions_for_order( $order_id );
			foreach ( $subscriptions as $key => $subscription ) {
				$subscription_id = $key;
			}
			$subscription            = wcs_get_subscription( $subscription_id );
			$telegram_kind           = '124';
			$send_data               = array();
			$send_data['running_id'] = $old_running_id;
			$send_data['trading_id'] = $old_trading_id;
			$career_type             = $subscription->get_meta( '_career_type', true );
			if ( 'docomo' === $career_type ) {
				$send_data['user_certification_ryaku'] = 1;
			}
			$add_message = '(by System)';
			$response    = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );
			if ( '0' === $response['result'] && $response['result_array'] ) {
				$subscription->add_order_note( __( 'Success cancel the old MB subscription.', 'woocommerce-for-paygent-payment-main' ) . $add_message );
			} else {
				$this->paygent_request->error_response( $response, $subscription );
				$subscription->add_order_note( __( 'Fail cancel the old MB subscription.', 'woocommerce-for-paygent-payment-main' ) . $add_message );
			}
			if ( $order->get_payment_method() === $this->id ) {
				$running_target_ym = date_i18n( 'Ym' );
				$refund_response   = $this->paygent_mb_refund_subscription( $old_running_id, $old_trading_id, $running_target_ym, $order );
				if ( '0' === $refund_response['result'] && $refund_response['result_array'] ) {
					$subscription->add_order_note( __( 'Success refund this month MB subscription.', 'woocommerce-for-paygent-payment-main' ) . $add_message );
				} else {
					$this->paygent_request->error_response( $refund_response, $subscription );
					$subscription->add_order_note( __( 'Fail refund this month MB subscription.', 'woocommerce-for-paygent-payment-main' ) . $add_message );
				}
			}
		}
	}
}
