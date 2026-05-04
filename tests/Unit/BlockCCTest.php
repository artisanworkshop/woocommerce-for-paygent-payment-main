<?php

namespace Paygent\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Tests for WC_Paygent_Block_CC.
 */
class BlockCCTest extends TestCase {

	private const BLOCK_DIR  = __DIR__ . '/../../includes/gateways/paygent/includes/block/';
	private const PLUGIN_URL = 'http://localhost/wp-content/plugins/paygent/';

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! defined( 'WC_PAYGENT_ABSPATH' ) ) {
			define( 'WC_PAYGENT_ABSPATH', dirname( __DIR__, 2 ) . '/' );
		}
		if ( ! defined( 'WC_PAYGENT_PLUGIN_URL' ) ) {
			define( 'WC_PAYGENT_PLUGIN_URL', self::PLUGIN_URL );
		}
		if ( ! defined( 'WC_PAYGENT_VERSION' ) ) {
			define( 'WC_PAYGENT_VERSION', '2.4.8' );
		}
	}

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_script_is' )->justReturn( true );
		Functions\when( 'wp_register_script' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );

		if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged
			eval( '
				namespace Automattic\WooCommerce\Blocks\Payments\Integrations;
				abstract class AbstractPaymentMethodType {
					protected $name;
					protected $settings = array();
					abstract public function initialize(): void;
					abstract public function is_active(): bool;
					abstract public function get_payment_method_script_handles(): array;
					abstract public function get_payment_method_data(): array;
				}
			' );
		}

		require_once self::BLOCK_DIR . 'class-abstract-wc-paygent-block-payment.php';
		require_once self::BLOCK_DIR . 'class-wc-paygent-block-cc.php';

		if ( ! class_exists( 'WC_Payment_Tokens' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged
			eval( 'class WC_Payment_Tokens { public static function get_customer_tokens($user_id, $gateway_id) { return array(); } }' );
		}
	}

	public function test_name_is_paygent_cc(): void {
		$block = new \WC_Paygent_Block_CC();
		// $name is protected; verify it via the script handle name which embeds the gateway ID.
		$handles = $block->get_payment_method_script_handles();
		$this->assertContains( 'wc-paygent-block-cc', $handles );
	}

	public function test_initialize_loads_settings(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'enabled'        => 'yes',
				'title'          => 'クレジットカード',
				'store_card_info' => 'no',
			)
		);

		$block = new \WC_Paygent_Block_CC();
		$block->initialize();
		$this->assertTrue( $block->is_active() );
	}

	public function test_is_active_false_when_disabled(): void {
		Functions\when( 'get_option' )->justReturn( array( 'enabled' => 'no' ) );

		$block = new \WC_Paygent_Block_CC();
		$block->initialize();
		$this->assertFalse( $block->is_active() );
	}

	public function test_script_handle_returned(): void {
		$block   = new \WC_Paygent_Block_CC();
		$handles = $block->get_payment_method_script_handles();
		$this->assertContains( 'wc-paygent-block-cc', $handles );
	}

	public function test_get_payment_method_data_contains_required_keys(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_option' )->alias(
			function ( $option, $default = false ) {
				if ( 'woocommerce_paygent_cc_settings' === $option ) {
					return array(
						'enabled'         => 'yes',
						'title'           => 'クレジットカード',
						'description'     => '',
						'store_card_info' => 'no',
						'tds2_check'      => 'no',
					);
				}

				return $default;
			}
		);

		$block = new \WC_Paygent_Block_CC();
		$block->initialize();
		$data = $block->get_payment_method_data();

		foreach ( array( 'title', 'description', 'supports', 'merchantId', 'tokenKey', 'isTds2', 'enableSaveCard', 'savedCards', 'paymentMethods', 'numberOfPayments' ) as $key ) {
			$this->assertArrayHasKey( $key, $data, "Missing key: $key" );
		}
	}

	public function test_saved_cards_empty_for_guest(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_option' )->justReturn( array( 'store_card_info' => 'yes' ) );

		$block = new \WC_Paygent_Block_CC();
		$block->initialize();
		$data = $block->get_payment_method_data();

		$this->assertSame( array(), $data['savedCards'] );
	}

	public function test_tds2_flag_reflects_setting(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_option' )->justReturn( array( 'tds2_check' => 'yes' ) );

		$block = new \WC_Paygent_Block_CC();
		$block->initialize();
		$data = $block->get_payment_method_data();

		$this->assertTrue( $data['isTds2'] );
	}

	public function test_tds2_false_by_default(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_option' )->justReturn( array() );

		$block = new \WC_Paygent_Block_CC();
		$block->initialize();
		$data = $block->get_payment_method_data();

		$this->assertFalse( $data['isTds2'] );
	}

	public function test_payment_methods_defaults_to_one_time(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_option' )->justReturn( array() );

		$block = new \WC_Paygent_Block_CC();
		$block->initialize();
		$data = $block->get_payment_method_data();

		$this->assertCount( 1, $data['paymentMethods'] );
		$this->assertSame( '10', $data['paymentMethods'][0]['code'] );
	}

	public function test_payment_methods_includes_installment(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_option' )->justReturn(
			array(
				'payment_method'     => array( '10', '61' ),
				'number_of_payments' => array( '3', '6', '12' ),
			)
		);

		$block = new \WC_Paygent_Block_CC();
		$block->initialize();
		$data = $block->get_payment_method_data();

		$codes = array_column( $data['paymentMethods'], 'code' );
		$this->assertContains( '61', $codes );
		$this->assertSame( array( '3', '6', '12' ), $data['numberOfPayments'] );
	}

	public function test_get_supported_features_contains_subscriptions(): void {
		$block = new \WC_Paygent_Block_CC();
		$this->assertContains( 'subscriptions', $block->get_supported_features() );
		$this->assertContains( 'tokenization', $block->get_supported_features() );
		$this->assertContains( 'refunds', $block->get_supported_features() );
	}

	public function test_merchant_id_uses_test_option_in_test_mode(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_option' )->alias(
			function ( $key ) {
				$map = array(
					'wc-paygent-testmode'     => '1',
					'wc-paygent-test-mid'     => 'TEST_MID',
					'wc-paygent-test-tokenkey' => 'TEST_TKEY',
				);
				return $map[ $key ] ?? array();
			}
		);

		$block = new \WC_Paygent_Block_CC();
		$block->initialize();
		$data = $block->get_payment_method_data();

		$this->assertSame( 'TEST_MID', $data['merchantId'] );
		$this->assertSame( 'TEST_TKEY', $data['tokenKey'] );
	}

	public function test_merchant_id_uses_live_option_in_live_mode(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_option' )->alias(
			function ( $key ) {
				$map = array(
					'wc-paygent-testmode' => '0',
					'wc-paygent-mid'      => 'LIVE_MID',
					'wc-paygent-tokenkey' => 'LIVE_TKEY',
				);
				return $map[ $key ] ?? array();
			}
		);

		$block = new \WC_Paygent_Block_CC();
		$block->initialize();
		$data = $block->get_payment_method_data();

		$this->assertSame( 'LIVE_MID', $data['merchantId'] );
		$this->assertSame( 'LIVE_TKEY', $data['tokenKey'] );
	}
}
