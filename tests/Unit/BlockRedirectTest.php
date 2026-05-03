<?php

namespace Paygent\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Tests for WC_Paygent_Block_Redirect and Abstract_WC_Paygent_Block_Payment.
 */
class BlockRedirectTest extends TestCase {

	private const BLOCK_DIR    = __DIR__ . '/../../includes/gateways/paygent/includes/block/';
	private const PLUGIN_URL   = 'http://localhost/wp-content/plugins/paygent/';
	private const ICON_BASE    = self::PLUGIN_URL . 'assets/images/';

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

		// wp_script_is returns true so the script registration branch is skipped.
		Functions\when( 'wp_script_is' )->justReturn( true );
		Functions\when( 'wp_register_script' )->justReturn( null );

		// AbstractPaymentMethodType stub — minimal interface the plugin relies on.
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

		if ( ! class_exists( 'Abstract_WC_Paygent_Block_Payment' ) ) {
			require_once self::BLOCK_DIR . 'class-abstract-wc-paygent-block-payment.php';
		}
		if ( ! class_exists( 'WC_Paygent_Block_Redirect' ) ) {
			require_once self::BLOCK_DIR . 'class-wc-paygent-block-redirect.php';
		}
	}

	// -----------------------------------------------------------------------
	// Abstract_WC_Paygent_Block_Payment — initialize / is_active
	// -----------------------------------------------------------------------

	public function test_initialize_loads_settings_and_is_active_true(): void {
		Functions\when( 'get_option' )
			->justReturn( array( 'enabled' => 'yes', 'title' => 'ATM払い' ) );

		$gateway = new \WC_Paygent_Block_Redirect( 'paygent_atm' );
		$gateway->initialize();

		$this->assertTrue( $gateway->is_active() );
	}

	public function test_is_active_false_when_disabled(): void {
		Functions\when( 'get_option' )->justReturn( array( 'enabled' => 'no' ) );

		$gateway = new \WC_Paygent_Block_Redirect( 'paygent_atm' );
		$gateway->initialize();

		$this->assertFalse( $gateway->is_active() );
	}

	public function test_is_active_false_when_settings_empty(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$gateway = new \WC_Paygent_Block_Redirect( 'paygent_atm' );
		$gateway->initialize();

		$this->assertFalse( $gateway->is_active() );
	}

	// -----------------------------------------------------------------------
	// WC_Paygent_Block_Redirect — get_payment_method_data with icon
	// -----------------------------------------------------------------------

	public function test_get_payment_method_data_includes_icon_url(): void {
		Functions\when( 'get_option' )
			->justReturn( array( 'enabled' => 'yes', 'title' => 'ATM払い', 'description' => '振込後に確定' ) );

		$gateway = new \WC_Paygent_Block_Redirect( 'paygent_atm', array( 'products', 'refunds' ) );
		$gateway->initialize();
		$data = $gateway->get_payment_method_data();

		$this->assertSame( 'ATM払い', $data['title'] );
		$this->assertSame( '振込後に確定', $data['description'] );
		$this->assertSame( array( 'products', 'refunds' ), $data['supports'] );
		$this->assertSame( self::ICON_BASE . 'atm_logo.svg', $data['icon_url'] );
	}

	/**
	 * @dataProvider icon_map_provider
	 */
	public function test_each_gateway_gets_correct_icon( string $gateway_id, string $expected_icon ): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$gateway = new \WC_Paygent_Block_Redirect( $gateway_id );
		$data    = $gateway->get_payment_method_data();

		$this->assertSame( self::ICON_BASE . $expected_icon, $data['icon_url'] );
	}

	public function icon_map_provider(): array {
		return array(
			'ATM'         => array( 'paygent_atm', 'atm_logo.svg' ),
			'Bank Net'    => array( 'paygent_bn', 'bank_net_logo.svg' ),
			'PayPay'      => array( 'paygent_paypay', 'paypay_logo.svg' ),
			'Rakuten Pay' => array( 'paygent_rakutenpay', 'rakuten_pay_logo.svg' ),
		);
	}

	public function test_unknown_gateway_has_no_icon_url(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$gateway = new \WC_Paygent_Block_Redirect( 'paygent_unknown' );
		$data    = $gateway->get_payment_method_data();

		$this->assertArrayNotHasKey( 'icon_url', $data );
	}

	// -----------------------------------------------------------------------
	// WC_Paygent_Block_Redirect — supported features
	// -----------------------------------------------------------------------

	public function test_default_supported_features_is_products(): void {
		$gateway = new \WC_Paygent_Block_Redirect( 'paygent_bn' );

		$this->assertSame( array( 'products' ), $gateway->get_supported_features() );
	}

	public function test_redirect_features_override_default(): void {
		$gateway = new \WC_Paygent_Block_Redirect( 'paygent_paypay', array( 'products', 'refunds' ) );

		$this->assertSame( array( 'products', 'refunds' ), $gateway->get_supported_features() );
	}

	// -----------------------------------------------------------------------
	// WC_Paygent_Block_Redirect — script handles
	// -----------------------------------------------------------------------

	public function test_get_payment_method_script_handles_returns_shared_handle(): void {
		$gateway = new \WC_Paygent_Block_Redirect( 'paygent_atm' );
		$handles = $gateway->get_payment_method_script_handles();

		$this->assertSame( array( 'wc-paygent-block-redirect' ), $handles );
	}

	public function test_all_redirect_gateways_return_same_script_handle(): void {
		$atm    = new \WC_Paygent_Block_Redirect( 'paygent_atm' );
		$bn     = new \WC_Paygent_Block_Redirect( 'paygent_bn' );
		$paypay = new \WC_Paygent_Block_Redirect( 'paygent_paypay' );
		$rp     = new \WC_Paygent_Block_Redirect( 'paygent_rakutenpay' );

		$expected = array( 'wc-paygent-block-redirect' );
		$this->assertSame( $expected, $atm->get_payment_method_script_handles() );
		$this->assertSame( $expected, $bn->get_payment_method_script_handles() );
		$this->assertSame( $expected, $paypay->get_payment_method_script_handles() );
		$this->assertSame( $expected, $rp->get_payment_method_script_handles() );
	}
}
