<?php
/**
 * WooCommerce Paygent Credit Card Payment Gateway with Subscription Support
 *
 * @package WooCommerce\Gateways\Paygent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class WC_Gateway_Paygent_Addon_CC
 *
 * Extends the Paygent credit card payment gateway to add subscription support.
 *
 * @extends WC_Gateway_Paygent_CC
 */
class WC_Gateway_Paygent_Addon_CC extends WC_Gateway_Paygent_CC {

	/**
	 * Constructor for the gateway.
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() {
		parent::__construct();

		if ( class_exists( 'WC_Subscriptions_Order' ) ) {
			add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );
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
	 * Is $order_id a subscription?
	 *
	 * @param  int $order_id Order ID.
	 * @return boolean
	 */
	protected function is_subscription( $order_id ) {
		return ( function_exists( 'wcs_order_contains_subscription' ) && ( wcs_order_contains_subscription( $order_id ) || wcs_is_subscription( $order_id ) || wcs_order_contains_renewal( $order_id ) ) );
	}

	/**
	 * Process the subscription.
	 *
	 * @param  WC_Order $order Order object.
	 * @param  boolean  $subscription Is this a subscription payment.
	 * @return array
	 */
	protected function process_subscription( $order, $subscription = false ) {
		$payment_response = $this->process_subscription_payment( $order, $order->get_total() );
		return $payment_response;
	}

	/**
	 * Process the payment.
	 *
	 * @param  int     $order_id Order ID.
	 * @param  boolean $subscription Is this a subscription payment.
	 * @return array
	 */
	public function process_payment( $order_id, $subscription = false ) {
		// Processing subscription.
		if ( $this->is_subscription( $order_id ) ) {
			// Regular payment with force customer enabled.
			return parent::process_payment( $order_id, true );
		} else {
			return parent::process_payment( $order_id, false );
		}
	}

	/**
	 * Process_subscription_payment function.
	 *
	 * @param object $order WC_order object.
	 * @param int    $amount (default: 0).
	 * @return bool|WP_Error
	 */
	public function process_subscription_payment( $order = '', $amount = 0 ) {
		if ( 0 === $amount ) {
			// Payment complete.
			$order->payment_complete();

			return true;
		}
		$order_id     = $order->get_id();
		$customer_id  = $order->get_customer_id();
		$card_user_id = 'wc' . $customer_id;
		$send_data    = array();
		// Common header.
		$telegram_kind = '020';
		$prefix_order  = get_option( 'wc-paygent-prefix_order' );
		if ( $prefix_order ) {
			$send_data['trading_id'] = $prefix_order . $order_id;
		} else {
			$send_data['trading_id'] = 'wc_' . $order_id;
		}
		$send_data['payment_id'] = '';

		$send_data['payment_amount'] = $order->get_total();

		// Payment times.
		$send_data['payment_class']       = 10;// One time payment.
		$send_data['3dsecure_ryaku']      = 1;
		$send_data['stock_card_mode']     = 1;
		$send_data['security_code_token'] = 0;
		$send_data['customer_id']         = $card_user_id;
		$default_token                    = WC_Payment_Tokens::get_customer_default_token( $customer_id );
		if ( $default_token ) {
			foreach ( $default_token->get_meta_data() as $data_key => $data_value ) {
				$main_key = 'key';
				foreach ( $data_value->get_data() as $meta_key => $meta_value ) {
					if ( 'key' === $meta_key ) {
						$main_key = $meta_value;
					} elseif ( 'value' === $meta_key ) {
						$paygent_tokens[ $main_key ] = $meta_value;
					}
				}
			}
			if ( isset( $paygent_tokens ) ) {
				$send_data['customer_card_id'] = $paygent_tokens['customer_card_id'];
			}

			$response = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );

			// Check response.
			if ( 0 === $response['result'] && $response['result_array'] ) {
				// Success.
				$order->add_order_note( __( 'Subscription Credit Card Payment completed.', 'woocommerce-for-paygent-payment-main' ) );
				$order->add_meta_data( '_paygent_order_id', $send_data['trading_id'], true );
				// set transaction id for Paygent Order Number.
				$order->payment_complete( wc_clean( $response['result_array'][0]['payment_id'] ) );
				if ( isset( $this->paymentaction ) && 'sale' === $this->paymentaction ) {
					$telegram_kind = '022';
					$response_sale = $this->paygent_request->send_paygent_request( $this->test_mode, $order, $telegram_kind, $send_data, $this->debug );
					if ( 0 !== $response_sale['result'] ) {
						$this->paygent_request->error_response( $response_sale, $order );
					}
				}
				return true;
			} else {
				// Failed.
				$order->add_order_note( __( 'Subscription Credit Card Payment failed.', 'woocommerce-for-paygent-payment-main' ) );
				return $this->paygent_request->error_response( $response, $order );
			}
		}
		// Failed.
		$order->add_order_note( __( 'Subscription Credit Card Payment failed.', 'woocommerce-for-paygent-payment-main' ) . __( 'There are no saved cards.', 'woocommerce-for-paygent-payment-main' ) );
		return false;
	}

	/**
	 * Add scheduled_subscription_payment function.
	 *
	 * @param float    $amount_to_charge The amount to charge.
	 * @param WC_Order $renewal_order A WC_Order object created to record the renewal payment.
	 */
	public function scheduled_subscription_payment( $amount_to_charge, $renewal_order ) {
		$user_id = $renewal_order->get_user_id();
		if ( 'yes' === $this->delete_expired_cards ) :
			$tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, 'paygent_cc' );
			// Delete expired paygent credit card token.
			foreach ( $tokens as $key => $token ) {
				$now    = (int) date_i18n( 'Y' ) . date_i18n( 'm' );
				$expire = (int) $token->get_expiry_year() . $token->get_expiry_month();
				if ( $now > $expire ) {
					WC_Payment_Tokens::delete( $key );
					$renewal_order->add_order_note( __( 'Expired Credit Card has been deleted.', 'woocommerce-for-paygent-payment-main' ) );
				}
			}
			// If the customer has another card, make it the default card.
			$default_token = WC_Payment_Tokens::get_customer_default_token( $user_id );
			if ( ! $default_token ) {
				$new_tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, 'paygent_cc' );
				if ( $new_tokens ) {
					$default_token = $new_tokens[0];
					$default_token->set_default( true );
					$default_token->save();
				} else {
					$renewal_order->update_status( 'cancelled', __( 'There are no saved cards.', 'woocommerce-for-paygent-payment-main' ) );
				}
			}
		endif;

		$result = $this->process_subscription_payment( $renewal_order, $amount_to_charge );
		if ( is_wp_error( $result ) ) {
			// translators: %s: Error message.
			$renewal_order->update_status( 'failed', sprintf( __( 'Paygent Transaction Failed (%s)', 'woocommerce' ), $result->get_error_message() ) );
		}
	}
}
