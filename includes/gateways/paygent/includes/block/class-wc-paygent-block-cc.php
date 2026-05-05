<?php
/**
 * Block checkout integration for the Paygent Credit Card gateway.
 *
 * @package WooCommerce_Paygent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WC_Paygent_Block_CC
 *
 * Registers `paygent_cc` as a Block payment method and provides
 * the data needed by the React card-form component:
 *   - Paygent merchant ID and token key (test/live)
 *   - Saved cards for the current user
 *   - Enabled payment methods (1-time, installment, bonus, revolving)
 *   - 3DS2 flag and save-card flag
 */
class WC_Paygent_Block_CC extends Abstract_WC_Paygent_Block_Payment {

	/**
	 * Gateway identifier.
	 *
	 * @var string
	 */
	protected $name = 'paygent_cc';

	/**
	 * Register the PaygentToken.js external script and the compiled block bundle.
	 *
	 * Only the handle array is returned; WooCommerce Blocks loads these scripts
	 * solely on pages that contain the checkout block.  Do NOT call wp_enqueue_script()
	 * here — doing so would inject external scripts on every page (including login).
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles(): array {
		// PaygentToken.js — registered as <head> script (in_footer=false) so that
		// window.PaygentToken exists when the React form mounts.
		// WooCommerce Blocks enqueues it via the dependency chain, not globally.
		if ( ! wp_script_is( 'paygent-token-js', 'registered' ) ) {
			$token_js_url = '1' === get_option( 'wc-paygent-testmode' )
				? '//sandbox.paygent.co.jp/js/PaygentToken.js'
				: '//token.paygent.co.jp/js/PaygentToken.js';

			wp_register_script(
				'paygent-token-js',
				$token_js_url,
				array(),
				null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
				false // Load in <head>.
			);
		}

		if ( ! wp_script_is( 'wc-paygent-block-cc', 'registered' ) ) {
			$asset_file = WC_PAYGENT_ABSPATH . 'build/paygent-cc.asset.php';
			$asset      = file_exists( $asset_file )
				? require $asset_file
				: array(
					'dependencies' => array(),
					'version'      => WC_PAYGENT_VERSION,
				);

			wp_register_script(
				'wc-paygent-block-cc',
				WC_PAYGENT_PLUGIN_URL . 'build/paygent-cc.js',
				array_merge( $asset['dependencies'], array( 'paygent-token-js' ) ),
				$asset['version'],
				true
			);
		}

		// CSS: associate with the checkout block so it loads only when that block
		// is present on the page (wp_enqueue_block_style, available since WP 5.9).
		if ( function_exists( 'wp_enqueue_block_style' ) ) {
			wp_enqueue_block_style(
				'woocommerce/checkout',
				array(
					'handle' => 'wc-paygent-block-cc',
					'src'    => WC_PAYGENT_PLUGIN_URL . 'assets/css/paygent-block-cc.css',
					'ver'    => WC_PAYGENT_VERSION,
					'path'   => WC_PAYGENT_ABSPATH . 'assets/css/paygent-block-cc.css',
				)
			);
		} else {
			wp_enqueue_style(
				'wc-paygent-block-cc',
				WC_PAYGENT_PLUGIN_URL . 'assets/css/paygent-block-cc.css',
				array(),
				WC_PAYGENT_VERSION
			);
		}

		return array( 'wc-paygent-block-cc' );
	}

	/**
	 * Pass the data required by the JS CardForm component.
	 *
	 * @return array
	 */
	public function get_payment_method_data(): array {
		$settings  = $this->settings;
		$test_mode = get_option( 'wc-paygent-testmode' );

		if ( '1' === $test_mode ) {
			$merchant_id = get_option( 'wc-paygent-test-mid' );
			$token_key   = get_option( 'wc-paygent-test-tokenkey' );
		} else {
			$merchant_id = get_option( 'wc-paygent-mid' );
			$token_key   = get_option( 'wc-paygent-tokenkey' );
		}

		// Saved cards for logged-in users.
		$saved_cards = array();
		$user_id     = get_current_user_id();
		if ( $user_id && 'yes' === ( $settings['store_card_info'] ?? 'no' ) ) {
			$tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, $this->name );
			foreach ( $tokens as $token ) {
				$customer_card_id = $token->get_meta( 'customer_card_id' );
				if ( $customer_card_id ) {
					$saved_cards[] = array(
						'tokenId'        => $token->get_id(),
						'customerCardId' => $customer_card_id,
						'last4'          => $token->get_last4(),
						'expiryMonth'    => $token->get_expiry_month(),
						'expiryYear'     => $token->get_expiry_year(),
						'cardType'       => $token->get_card_type(),
					);
				}
			}
		}

		// Payment method options (multiselect in admin).
		$label_map       = array(
			'10' => __( 'One-time payment', 'woocommerce-for-paygent-payment-main' ),
			'61' => __( 'Installment payment', 'woocommerce-for-paygent-payment-main' ),
			'23' => __( 'Bonus lump-sum payment', 'woocommerce-for-paygent-payment-main' ),
			'80' => __( 'Revolving payment', 'woocommerce-for-paygent-payment-main' ),
		);
		$raw_methods     = $settings['payment_method'] ?? array( '10' );
		$raw_methods     = is_array( $raw_methods ) ? $raw_methods : array( $raw_methods );
		$payment_methods = array();
		foreach ( $raw_methods as $code ) {
			$payment_methods[] = array(
				'code'  => $code,
				'label' => $label_map[ $code ] ?? $code,
			);
		}

		// Installment count options (shown only when code '61' is in payment_method).
		$raw_counts         = $settings['number_of_payments'] ?? array();
		$raw_counts         = is_array( $raw_counts ) ? $raw_counts : array( $raw_counts );
		$number_of_payments = array_values( $raw_counts );

		return array(
			'title'            => $settings['title'] ?? '',
			'description'      => $settings['description'] ?? '',
			'supports'         => $this->get_supported_features(),
			'merchantId'       => $merchant_id,
			'tokenKey'         => $token_key,
			'isTds2'           => 'yes' === ( $settings['tds2_check'] ?? 'no' ),
			'enableSaveCard'   => 'yes' === ( $settings['store_card_info'] ?? 'no' ),
			'savedCards'       => $saved_cards,
			'paymentMethods'   => $payment_methods,
			'numberOfPayments' => $number_of_payments,
		);
	}

	/**
	 * Features supported by the CC gateway in Block checkout.
	 *
	 * @return string[]
	 */
	public function get_supported_features(): array {
		return array(
			'products',
			'refunds',
			'tokenization',
			'subscriptions',
			'subscription_cancellation',
			'subscription_reactivation',
			'subscription_suspension',
			'subscription_amount_changes',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
			'subscription_date_changes',
			'multiple_subscriptions',
		);
	}
}
