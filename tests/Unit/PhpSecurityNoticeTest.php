<?php

namespace Paygent\Tests\Unit;

use ArtisanWorkshop\PhpSecurityNotice\Notice;
use Brain\Monkey\Filters;

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

	public function test_bundled_translation_is_used_only_while_the_strings_are_built(): void {
		Filters\expectAdded( 'gettext_woocommerce-for-paygent-payment-main' )
			->once()
			->with( 'wc_gateway_paygent_php_security_notice_bundled_translation', 10, 2 );
		Filters\expectRemoved( 'gettext_woocommerce-for-paygent-payment-main' )
			->once()
			->with( 'wc_gateway_paygent_php_security_notice_bundled_translation', 10 );

		wc_gateway_paygent_php_security_notice_strings();
	}
}
