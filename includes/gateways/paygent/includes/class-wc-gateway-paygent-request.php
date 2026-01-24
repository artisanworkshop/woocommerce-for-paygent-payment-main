<?php
/**
 * Paygent Payment Gateway
 *
 * Functions of a Paygent Payment Gateway.
 *
 * @class       WC_Gateway_Paygent_Request
 * @version     2.3.0
 * @package     PaygentForWooCommerce
 * @author      Artisan Workshop
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ArtisanWorkshop\PluginFramework\v2_0_13 as Framework;

use PaygentModule\System\PaygentB2BModule;

/**
 * Generates requests to send to Paygent
 */
class WC_Gateway_Paygent_Request {

	/**
	 * Merchant id for Paygent.
	 *
	 * @var string
	 */
	public $merchant_id;

	/**
	 * Connect id for Paygent.
	 *
	 * @var string
	 */
	public $connect_id;

	/**
	 * Connect password for Paygent.
	 *
	 * @var string
	 */
	public $connect_password;

	/**
	 * TEST Merchant id for Paygent.
	 *
	 * @var string
	 */
	public $merchant_test_id;

	/**
	 * TEST Connect id for Paygent.
	 *
	 * @var string
	 */
	public $connect_test_id;

	/**
	 * TEST Connect password for Paygent.
	 *
	 * @var string
	 */
	public $connect_test_password;

	/**
	 * Site id for Paygent.
	 *
	 * @var integer
	 */
	public $site_id;

	/**
	 * Prefix Order
	 *
	 * @var string
	 */
	public $prefix_order;

	/**
	 * Framework.
	 *
	 * @var object
	 */
	public $jp4wc_framework;

	/**
	 * Setting data.
	 *
	 * @var array
	 */
	public $app;

	/**
	 * Socket for SSL communication
	 *
	 * @var resource
	 */
	private $ch;

	/**
	 * Constructor
	 */
	public function __construct() {
		// Paygent Setting IDs.
		$this->merchant_id           = get_option( 'wc-paygent-mid' );
		$this->connect_id            = get_option( 'wc-paygent-cid' );
		$this->connect_password      = get_option( 'wc-paygent-cpass' );
		$this->merchant_test_id      = get_option( 'wc-paygent-test-mid' );
		$this->connect_test_id       = get_option( 'wc-paygent-test-cid' );
		$this->connect_test_password = get_option( 'wc-paygent-test-cpass' );
		$this->site_id               = get_option( 'wc-paygent-sid' );
		$this->prefix_order          = get_option( 'wc-paygent-prefix_order' );

		$this->jp4wc_framework = new Framework\JP4WC_Framework();
	}

	/**
	 * Get the Paygent request Post data for an order
	 *
	 * @param  boolean $test_mode Test mode.
	 * @param  object  $order WC_Order object.
	 * @param  string  $telegram_kind Telegram kind.
	 * @param  array   $send_data Send data.
	 * @param  string  $debug Debug mode.
	 * @return array
	 */
	public function send_paygent_request( $test_mode, $order, $telegram_kind, $send_data, $debug = 'yes' ) {
		$data = $this->merchant_data( $test_mode );

		$process = new PaygentB2BModule();
		$process->init();
		$process->reqPut( 'merchant_id', $data['merchant_id'] );
		$process->reqPut( 'connect_id', $data['connect_id'] );
		$process->reqPut( 'connect_password', $data['connect_password'] );
		$process->reqPut( 'telegram_kind', $telegram_kind );
		$process->reqPut( 'telegram_version', '1.0' );

		// Make Hash check header.
		if ( get_option( 'wc-paygent-hash_check' ) ) {
			if ( $test_mode ) {
				$hash_code = get_option( 'wc-paygent-test-hash_code' );
			} else {
				$hash_code = get_option( 'wc-paygent-hash_code' );
			}
			$hash_data = array(
				'merchant_id'      => $data['merchant_id'],
				'connect_id'       => $data['connect_id'],
				'connect_password' => $data['connect_password'],
				'telegram_kind'    => $telegram_kind,
				'telegram_version' => '1.0',
				'trading_id'       => $send_data['trading_id'],
			);
			if ( isset( $send_data['payment_id'] ) ) {
				$hash_data['payment_id'] = $send_data['payment_id'];
			}
			if ( isset( $send_data['payment_amount'] ) ) {
				$hash_data['payment_amount'] = $send_data['payment_amount'];
			} elseif ( isset( $send_data['amount'] ) ) {
				$hash_data['payment_amount'] = $send_data['amount'];
			}
			$hash_data['request_date'] = date_i18n( 'YmdHis' );
			$send_data['request_date'] = date_i18n( 'YmdHis' );
			$send_data['hc']           = $this->make_hash_data( $hash_data, $hash_code );
		}

		// set send_data to reqPut.
		foreach ( $send_data as $key => $value ) {
			$process->reqPut( $key, $value );
		}

		// Save debug send data.
		$send_message = 'telegram_kind : ' . $telegram_kind . "\n";
		if ( ! is_null( $order ) ) {
			$send_message .= __( 'This request send data for order ID:', 'woocommerce-for-paygent-payment-main' ) . $order->get_id() . "\n";
		}
		foreach ( $send_data as $key => $value ) {
			$request_array[ $key ] = mb_convert_encoding( $value, 'UTF-8', 'SJIS' );
		}
		$send_message .= __( 'The request transmission data is shown below.', 'woocommerce-for-paygent-payment-main' ) . "\n" . $this->jp4wc_framework->jp4wc_array_to_message( $request_array );
		$this->jp4wc_framework->jp4wc_debug_log( $send_message, $debug, 'wc-paygent' );

		$process->post();

		$res_array = array();
		while ( $process->hasResNext() ) {
			$res_array[] = $process->resNext();
		}

		$result_data = array(
			'result'         => $process->getResultStatus(),
			'responseCode'   => $process->getResponseCode(),
			'responseDetail' => $process->getResponseDetail(),
			'result_array'   => $res_array,
		);

		// Save debug response data.
		$send_message  = 'telegram_kind : ' . $telegram_kind . "\n";
		$send_message .= 'result : ' . $result_data['result'] . "\n";
		if ( '0' !== $result_data['result'] ) {
			$send_message .= 'responseCode : ' . $result_data['responseCode'] . "\n";
			$send_message .= 'responseDetail : ' . mb_convert_encoding( $result_data['responseDetail'], 'UTF-8', 'SJIS' ) . "\n";
		}
		if ( ! is_null( $order ) ) {
			$send_message .= __( 'This response data for order ID:', 'woocommerce-for-paygent-payment-main' ) . $order->get_id() . "\n";
		}
		if ( isset( $res_array[0] ) ) {
			$response_array = array();
			foreach ( $res_array[0] as $key => $value ) {
				$response_array[ $key ] = mb_convert_encoding( $value, 'UTF-8', 'SJIS' );
			}
			$send_message .= __( 'The response transmission data is shown below.', 'woocommerce-for-paygent-payment-main' ) . "\n" . $this->jp4wc_framework->jp4wc_array_to_message( $response_array );
			$this->jp4wc_framework->jp4wc_debug_log( $send_message, $debug, 'wc-paygent' );
		}

		return $result_data;
	}

	/**
	 * Set the Paygent IDs for request.
	 *
	 * @param  bool $test_mode Test mode.
	 * @return array
	 */
	public function merchant_data( $test_mode ) {
		if ( '1' === $test_mode ) {
			$data['merchant_id']      = $this->merchant_test_id;
			$data['connect_id']       = $this->connect_test_id;
			$data['connect_password'] = $this->connect_test_password;
		} else {
			$data['merchant_id']      = $this->merchant_id;
			$data['connect_id']       = $this->connect_id;
			$data['connect_password'] = $this->connect_password;
		}
		return $data;
	}

	/**
	 * Update Sale from Auth to Paygent System
	 *
	 * @param int    $order_id Order ID.
	 * @param string $telegram_kind Telegram kind.
	 * @param object $payment Payment object.
	 * @param array  $send_data Send data.
	 */
	public function order_paygent_status_completed( $order_id, $telegram_kind, $payment, $send_data = array() ) {
		$order                = wc_get_order( $order_id );
		$order_payment_method = $order->get_payment_method();
		if ( isset( $payment->paymentaction ) && 'sale' !== $payment->paymentaction && $order_payment_method === $payment->id ) {
			$send_data['payment_id'] = $order->get_transaction_id();
			// Set Order ID for Paygent.
			$paygent_order_id = $order->get_meta( '_paygent_order_id' );
			if ( $paygent_order_id ) {
				$send_data['trading_id'] = $paygent_order_id;
			} elseif ( $this->prefix_order ) {
				$send_data['trading_id'] = $this->prefix_order . $order->get_id();
			} else {
				$send_data['trading_id'] = 'wc_' . $order_id;
			}
			// Set Site ID.
			if ( '1' !== $this->site_id ) {
				$send_data['site_id'] = $this->site_id;
			}
			$response = $this->send_paygent_request( $payment->test_mode, $order, $telegram_kind, $send_data, $payment->debug );
			if ( '0' === $response['result'] ) {
				$order->add_order_note( __( 'Success this order set to sale at Paygent.', 'woocommerce-for-paygent-payment-main' ) );
			} elseif ( 'paygent_paidy' === $order_payment_method ) {
				// Paidy Payment.
					$send_data['trading_id'] = $order_id;
					$response_again          = $this->send_paygent_request( $payment->test_mode, $order, $telegram_kind, $send_data, $payment->debug );
				if ( '0' === $response_again['result'] ) {
					$order->add_order_note( __( 'Success this order set to sale at Paygent.', 'woocommerce-for-paygent-payment-main' ) );
				} else {
					$order->add_order_note( __( 'Failed this order set to sale at Paygent.', 'woocommerce-for-paygent-payment-main' ) );
				}
			}
		}
	}

	/**
	 * Process refund for Paygent.
	 *
	 * @param int    $order_id Order ID.
	 * @param int    $amount Amount.
	 * @param array  $telegram_array Telegram array.
	 * @param array  $permit_statuses Permit statuses.
	 * @param array  $send_data_refund Send data for refund.
	 * @param object $payment Payment object.
	 *
	 * @return mixed
	 */
	public function paygent_process_refund( $order_id, $amount, $telegram_array, $permit_statuses, $send_data_refund, $payment ) {
		if ( is_null( $amount ) ) {
			return false;
		}

		$order                         = wc_get_order( $order_id );
		$transaction_id                = $order->get_transaction_id();
		$send_data_check['payment_id'] = $transaction_id;
		// Set Order ID for Paygent.
		$paygent_order_id = $order->get_meta( '_paygent_order_id' );
		if ( $paygent_order_id ) {
			$send_data_check['trading_id'] = $paygent_order_id;
		} else {
			$send_data_check['trading_id'] = 'wc_' . $order_id;
		}
		$send_data_refund['payment_id'] = $transaction_id;
		$send_data_refund['trading_id'] = $send_data_check['trading_id'];

		$telegram_kind_check = '094';// Information inquiry.
		$order_result        = $this->send_paygent_request( $payment->test_mode, $order, $telegram_kind_check, $send_data_check, $payment->debug );
		$order_total         = $order->get_total();
		if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order_id ) ) {
			unset( $send_data_refund['payment_id'] );
		}
		if ( $amount === $order_total ) {
			foreach ( $permit_statuses as $key => $permit_status ) {
				if ( 0 === $key || ( isset( $order_result['result_array'][0]['career_type'] ) && $order_result['result_array'][0]['career_type'] === $key ) ) {
					if ( isset( $permit_status['auth_cancel'] ) && in_array( $order_result['result_array'][0]['payment_status'], $permit_status['auth_cancel'], true ) === true ) {
						$telegram_kind_del = $telegram_array['auth_cancel'];// Authority Cancel.
					} elseif ( isset( $permit_status['sale_cancel'] ) && in_array( $order_result['result_array'][0]['payment_status'], $permit_status['sale_cancel'], true ) === true ) {
						$telegram_kind_del = $telegram_array['sale_cancel'];// Sales Cancel.
					} else {
						// translators: %s: payment status.
						$message = __( 'Failed Refund. ', 'woocommerce-for-paygent-payment-main' ) . sprintf( __( 'Not matched payment_status %s for refund.', 'woocommerce-for-paygent-payment-main' ), $order_result['result_array'][0]['payment_status'] );
						$order->add_order_note( $message );
						return new \WP_Error( 'wc_' . $order_id . '_refund_failed', $message );
					}
				}
			}
			$del_result = $this->send_paygent_request( $payment->test_mode, $order, $telegram_kind_del, $send_data_check, $payment->debug );
			if ( '1' === $del_result['result'] ) {
				$message = __( 'Failed Refund. ', 'woocommerce-for-paygent-payment-main' ) . __( 'Error Code :', 'woocommerce-for-paygent-payment-main' ) . $del_result['responseCode'] . __( ' Error message :', 'woocommerce-for-paygent-payment-main' ) . mb_convert_encoding( $del_result['responseDetail'], 'UTF-8', 'SJIS' );
				$order->add_order_note( $message );
				return new \WP_Error( 'wc_' . $order_id . '_refund_failed', $message );
			} elseif ( '0' === $del_result['result'] ) {
				$message = __( 'This order has been successfully refunded by Paygent.', 'woocommerce-for-paygent-payment-main' );
				$order->add_order_note( $message );
				return true;
			} else {
				$message = __( 'Failed Refund.', 'woocommerce-for-paygent-payment-main' );
				$order->add_order_note( $message );
				return new \WP_Error( 'wc_' . $order_id . '_refund_failed', $message );
			}
		} elseif ( $amount < $order_total ) {
			foreach ( $permit_statuses as $key => $permit_status ) {
				if ( 0 === $key || ( isset( $order_result['result_array'][0]['career_type'] ) && $key === $order_result['result_array'][0]['career_type'] ) ) {
					if ( in_array( $order_result['result_array'][0]['payment_status'], $permit_status['auth_change'], true ) ) {
						$telegram_kind_refund = $telegram_array['auth_change'];// Authory Change.
					} elseif ( in_array( $order_result['result_array'][0]['payment_status'], $permit_status['sale_change'], true ) ) {
						$telegram_kind_refund = $telegram_array['sale_change'];// Sales Change.
					} else {
						// translators: %s: payment status.
						$message = __( 'Failed Refund. ', 'woocommerce-for-paygent-payment-main' ) . sprintf( __( 'Not matched payment_status %s for refund.', 'woocommerce-for-paygent-payment-main' ), $order_result['result_array'][0]['payment_status'] );
						$order->add_order_note( $message );
						return new \WP_Error( 'wc_' . $order_id . '_refund_failed', $message );
					}
				}
			}
			$refund_result = $this->send_paygent_request( $payment->test_mode, $order, $telegram_kind_refund, $send_data_refund, $payment->debug );
			if ( '1' === $refund_result['result'] ) {
				$message = __( 'Failed Refund. ', 'woocommerce-for-paygent-payment-main' ) . __( 'Error Code :', 'woocommerce-for-paygent-payment-main' ) . $refund_result['responseCode'] . __( ' Error message :', 'woocommerce-for-paygent-payment-main' ) . mb_convert_encoding( $refund_result['responseDetail'], 'UTF-8', 'SJIS' );
				$order->add_order_note( $message );
				return new \WP_Error( 'wc_' . $order_id . '_refund_failed', $message );
			} elseif ( '0' === $refund_result['result'] ) {
				$order->set_transaction_id( $refund_result['result_array'][0]['payment_id'] );
				$order->save();
				$order->add_order_note(
					__( 'This order has been successfully partial refunded by Paygent.', 'woocommerce-for-paygent-payment-main' )
					// translators: %1$s: base_payment_id, %2$s: payment_id.
					. sprintf( __( 'payment_id changed from %1$s to %2$s.', 'woocommerce-for-paygent-payment-main' ), $refund_result['result_array'][0]['base_payment_id'], $refund_result['result_array'][0]['payment_id'] )
				);
				return true;
			} else {
				$message = __( 'Failed Refund.', 'woocommerce-for-paygent-payment-main' );
				$order->add_order_note( $message );
				return new \WP_Error( 'wc_' . $order_id . '_refund_failed', $message );
			}
		}
		return false;
	}

	/**
	 * Error response for Paygent
	 *
	 * @param  array  $response Response data.
	 * @param  object $order WC_Order object.
	 * @return void
	 */
	public function error_response( $response, $order ) {
		$order_id = $order->get_id();
		if ( '1' === $response['result'] ) {// System Error
			// Other transaction error.
			$code        = str_replace( '″', '', $response['responseDetail'] );
			$code        = str_replace( '”', '', $response['responseDetail'] );
			$error_texts = $this->error_text();
			if ( isset( $error_texts[ $code ] ) ) {
				$message = $code . ':' . $error_texts[ $code ];
			} else {
				$message = $response['responseDetail'];
			}

			$order->add_order_note( __( 'paygent Payment failed. Sysmte Error: ', 'woocommerce-for-paygent-payment-main' ) . $response['responseCode'] . ':' . mb_convert_encoding( $message, 'UTF-8', 'auto' ) . ':' . $response['responseDetail'] );
			if ( is_checkout() ) {
				wc_add_notice( __( 'Sorry, there was an error: ', 'woocommerce-for-paygent-payment-main' ) . $response['responseCode'] . ':' . mb_convert_encoding( $message, 'UTF-8', 'auto' ), 'error' );
			}
		} else {
			// No response or unexpected response.
			$order->add_order_note( __( 'paygent Payment failed. Some trouble happened.', 'woocommerce-for-paygent-payment-main' ) . $response['result'] . ':' . $response['responseCode'] . ':' . mb_convert_encoding( $response['responseDetail'], 'UTF-8', 'auto' ) . ':wc_' . $order_id );
			if ( is_checkout() ) {
				wc_add_notice( __( 'No response from payment gateway server. Try again later or contact the site administrator.', 'woocommerce-for-paygent-payment-main' ), 'error' );
			}
		}
	}

	/**
	 * Make hash data via hash code
	 *
	 * @param  array  $hash_data Hash data.
	 * @param  string $hash_code Hash code.
	 * @return string
	 */
	public function make_hash_data( $hash_data, $hash_code ) {
		$header_text = '';
		foreach ( $hash_data as $key => $value ) {
			if ( isset( $value ) ) {
				$header_text = $header_text . $value;
			}
		}
		$header_text = $header_text . $hash_code;
		$hc          = hash( 'sha256', $header_text );
		return $hc;
	}

	/**
	 * Get payment status from Paygent API
	 *
	 * @param WC_Order $order        Order object.
	 * @param object   $this_gateway Payment gateway object.
	 * @return array   Response from Paygent API
	 */
	public function paygent_get_payment_status( $order, $this_gateway ) {
		$telegram_kind           = '094'; // Information inquiry.
		$transaction_id          = $order->get_transaction_id();
		$send_data['payment_id'] = $transaction_id;
		// Set Order ID for Paygent.
		$paygent_order_id = $order->get_meta( '_paygent_order_id' );
		if ( $paygent_order_id ) {
			$send_data['trading_id'] = $paygent_order_id;
		} else {
			$send_data['trading_id'] = 'wc_' . $order_id;
		}
		$order_result = $this->send_paygent_request( $this_gateway->test_mode, $order, $telegram_kind, $send_data, $this_gateway->debug );
		if ( '0' !== $order_result['result'] ) {
			// No response or unexpected response.
			$this->error_response( $order_result, $order );
			return false;
		}
		return $order_result['result_array'][0];
	}

	/**
	 * Error text for Paygemnt
	 *
	 * @return array
	 */
	public function error_text() {
		return array(
			'1G02' => __( '[Issuer error] Insufficient card loan balance', 'woocommerce-for-paygent-payment-main' ),
			'1G03' => __( '[Issuer error] Card loan limit exceeded', 'woocommerce-for-paygent-payment-main' ),
			'1G04' => __( '[Issuer error] Insufficient cash loan balance', 'woocommerce-for-paygent-payment-main' ),
			'1G05' => __( '[Issuer error] Cashing limit exceeded', 'woocommerce-for-paygent-payment-main' ),
			'1G06' => __( '[Issuer error] Insufficient debit card balance', 'woocommerce-for-paygent-payment-main' ),
			'1G07' => __( '[Issuer error] Debit card limit exceeded', 'woocommerce-for-paygent-payment-main' ),
			'1G12' => __( '[Issuer error] Card cannot be used', 'woocommerce-for-paygent-payment-main' ),
			'1G22' => __( '[Issuer error] Permanent payment ban', 'woocommerce-for-paygent-payment-main' ),
			'1G30' => __( '[Issuer error] Transaction judgment pending (attended judgment)', 'woocommerce-for-paygent-payment-main' ),
			'1G42' => __( '[Issuer error] PIN code error', 'woocommerce-for-paygent-payment-main' ),
			'1G44' => __( '[Issuer error] Incorrect card confirmation number', 'woocommerce-for-paygent-payment-main' ),
			'1G45' => __( '[Issuer error] Card confirmation number not entered', 'woocommerce-for-paygent-payment-main' ),
			'1G46' => __( '[Issuer error] JIS 2nd page information error', 'woocommerce-for-paygent-payment-main' ),
			'1G54' => __( '[Issuer error] Number of uses per day amount exceeded', 'woocommerce-for-paygent-payment-main' ),
			'1G55' => __( '[Issuer error] Daily usage limit exceeded', 'woocommerce-for-paygent-payment-main' ),
			'1G56' => __( '[Issuer error] Credit card import', 'woocommerce-for-paygent-payment-main' ),
			'1G60' => __( '[Issuer error] Accident card', 'woocommerce-for-paygent-payment-main' ),
			'1G61' => __( '[Issuer error] Invalid card', 'woocommerce-for-paygent-payment-main' ),
			'1G65' => __( '[Issuer error] Membership number error', 'woocommerce-for-paygent-payment-main' ),
			'1G67' => __( '[Issuer error] Product code error', 'woocommerce-for-paygent-payment-main' ),
			'1G68' => __( '[Issuer error] Amount error', 'woocommerce-for-paygent-payment-main' ),
			'1G69' => __( '[Issuer error] Tax shipping error', 'woocommerce-for-paygent-payment-main' ),
			'1G70' => __( '[Issuer error] Bonus count error', 'woocommerce-for-paygent-payment-main' ),
			'1G71' => __( '[Issuer error] Bonus month error', 'woocommerce-for-paygent-payment-main' ),
			'1G72' => __( '[Issuer error] Bonus amount errorv', 'woocommerce-for-paygent-payment-main' ),
			'1G73' => __( '[Issuer error] Payment start month error', 'woocommerce-for-paygent-payment-main' ),
			'1G74' => __( '[Issuer error] Division count error', 'woocommerce-for-paygent-payment-main' ),
			'1G75' => __( '[Issuer error] Split amount error', 'woocommerce-for-paygent-payment-main' ),
			'1G76' => __( '[Issuer error] Initial amount error', 'woocommerce-for-paygent-payment-main' ),
			'1G77' => __( '[Issuer error] Business classification error', 'woocommerce-for-paygent-payment-main' ),
			'1G78' => __( '[Issuer error] Payment classification error', 'woocommerce-for-paygent-payment-main' ),
			'1G79' => __( '[Issuer error] Inquiry category error', 'woocommerce-for-paygent-payment-main' ),
			'1G80' => __( '[Issuer error] Cancellation classification error', 'woocommerce-for-paygent-payment-main' ),
			'1G81' => __( '[Issuer error] Handling classification/transaction classification error', 'woocommerce-for-paygent-payment-main' ),
			'1G83' => __( '[Issuer error] Expiration date error', 'woocommerce-for-paygent-payment-main' ),
			'1G84' => __( '[Issuer error] Approval number error', 'woocommerce-for-paygent-payment-main' ),
			'1G85' => __( '[Issuer error] CAFIS agency processing error', 'woocommerce-for-paygent-payment-main' ),
			'1G92' => __( '[Issuer error] Optional message output', 'woocommerce-for-paygent-payment-main' ),
			'1G94' => __( '[Issuer error] Cycle number error', 'woocommerce-for-paygent-payment-main' ),
			'1G95' => __( '[Issuer error] The relevant business has ended online', 'woocommerce-for-paygent-payment-main' ),
			'1G96' => __( '[Issuer error] Accident card data error', 'woocommerce-for-paygent-payment-main' ),
			'1G97' => __( '[Issuer error] Rejection of the request', 'woocommerce-for-paygent-payment-main' ),
			'1G98' => __( '[Issuer error] Business error for the company concerned', 'woocommerce-for-paygent-payment-main' ),
			'1G99' => __( '[Issuer error] Connection request refused by our company', 'woocommerce-for-paygent-payment-main' ),
		);
	}
}
