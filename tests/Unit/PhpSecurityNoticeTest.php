<?php

namespace Paygent\Tests\Unit;

use ArtisanWorkshop\PhpSecurityNotice\Notice;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Tests how this plugin bundles the PHP security notice shared with other
 * Artisan Workshop plugins (includes/artws-php-security-notice/, issue #44).
 *
 * Every plugin registers its own copy from its main file, and only the newest
 * registered copy is loaded at plugins_loaded 5, with the strings of the plugin
 * that registered it. So this plugin must register its own copy with that
 * copy's version, and its strings callback must return every string.
 */
class PhpSecurityNoticeTest extends TestCase {

	private const NOTICE_DIR = __DIR__ . '/../../includes/artws-php-security-notice';

	protected function setUp(): void {
		parent::setUp();

		// The main file registers the copy as it loads, like WordPress loading the plugin.
		require_once dirname( __DIR__, 2 ) . '/woocommerce-for-paygent-payment-main.php';
		require_once self::NOTICE_DIR . '/class-notice.php';
	}

	public function test_plugin_registers_its_copy(): void {
		$class_file = realpath( self::NOTICE_DIR . '/class-notice.php' );
		$registered = array();
		foreach ( $GLOBALS['artws_php_security_notice_registry'] as $entry ) {
			if ( realpath( $entry['class_file'] ) === $class_file ) {
				$registered[] = $entry;
			}
		}

		$this->assertCount( 1, $registered );
		$this->assertSame( $class_file, realpath( ( new \ReflectionClass( Notice::class ) )->getFileName() ), 'The Notice class under test is this plugin\'s copy.' );
		$this->assertSame( Notice::VERSION, $registered[0]['version'], 'Register the version of the bundled copy.' );
		$this->assertSame( 'wc_gateway_paygent_php_security_notice_strings', $registered[0]['strings_callback'] );
	}

	public function test_plugin_passes_every_string(): void {
		$strings = wc_gateway_paygent_php_security_notice_strings();

		$this->assertCount( 9, Notice::STRING_KEYS );
		foreach ( Notice::STRING_KEYS as $key ) {
			$this->assertArrayHasKey( $key, $strings );
			$this->assertIsString( $strings[ $key ] );
			$this->assertNotSame( '', $strings[ $key ], $key );
		}
	}

	/**
	 * The filter is gettext, not gettext_{$domain}, which WordPress 5.0 to 5.4 do not have.
	 */
	public function test_bundled_translation_is_used_only_while_the_strings_are_built(): void {
		Filters\expectAdded( 'gettext' )
			->once()
			->with( 'wc_gateway_paygent_php_security_notice_bundled_translation', 10, 3 );
		Filters\expectRemoved( 'gettext' )
			->once()
			->with( 'wc_gateway_paygent_php_security_notice_bundled_translation', 10 );

		wc_gateway_paygent_php_security_notice_strings();
	}

	/**
	 * Before WordPress 6.5 there is no WP_Translation_File, so the bundled .mo is read with MO.
	 */
	public function test_bundled_translation_is_read_with_mo_without_wp_translation_file(): void {
		$this->assertFalse( class_exists( 'WP_Translation_File', false ), 'This test needs a process without WP_Translation_File.' );
		$this->define_mo_stub();
		Functions\when( 'determine_locale' )->justReturn( 'ja' );

		$this->assertSame( '閉じる', wc_gateway_paygent_php_security_notice_bundled_translation( 'Close', 'Close', 'woocommerce-for-paygent-payment-main' ) );
		$this->assertSame( 'Learn more', wc_gateway_paygent_php_security_notice_bundled_translation( 'Learn more', 'Learn more', 'woocommerce-for-paygent-payment-main' ), 'A string the bundled file lacks stays as it is.' );
		$this->assertSame(
			array( realpath( dirname( __DIR__, 2 ) . '/i18n/woocommerce-for-paygent-payment-main-ja.mo' ) ),
			array_map( 'realpath', \MO::$imported ),
			'The bundled Japanese file is read once and kept.'
		);
	}

	public function test_bundled_translation_leaves_other_strings_alone(): void {
		$this->define_mo_stub();
		$imported = \MO::$imported;

		// Japanese has a bundled translation of "Close", so these would change without the checks.
		Functions\when( 'determine_locale' )->justReturn( 'ja' );
		$this->assertSame( 'Close', wc_gateway_paygent_php_security_notice_bundled_translation( 'Close', 'Close', 'other-domain' ), 'Other text domains pass through.' );
		$this->assertSame( 'Closed', wc_gateway_paygent_php_security_notice_bundled_translation( 'Closed', 'Close', 'woocommerce-for-paygent-payment-main' ), 'Loaded translations win.' );

		Functions\when( 'determine_locale' )->justReturn( 'xx_XX' );
		$this->assertSame( 'Close', wc_gateway_paygent_php_security_notice_bundled_translation( 'Close', 'Close', 'woocommerce-for-paygent-payment-main' ), 'A locale without a bundled file passes through.' );
		$this->assertSame( $imported, \MO::$imported, 'No file is read for them.' );
	}

	/**
	 * Defines a minimal MO (WordPress pomo) that records the files it reads.
	 */
	private function define_mo_stub(): void {
		if ( class_exists( 'MO', false ) ) {
			return;
		}
		// phpcs:ignore Squiz.PHP.Eval.Discouraged
		eval(
			'class MO {
				public static $imported = array();
				private $entries = array();
				public function import_from_file( $filename ) {
					self::$imported[] = $filename;
					$this->entries    = array( "Close" => "閉じる" );
					return true;
				}
				public function translate( $singular, $context = null ) {
					return isset( $this->entries[ $singular ] ) ? $this->entries[ $singular ] : $singular;
				}
			}'
		);
	}
}
