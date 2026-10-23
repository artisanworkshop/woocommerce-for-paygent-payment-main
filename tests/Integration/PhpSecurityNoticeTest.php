<?php

namespace Paygent\Tests\Integration;

/**
 * Integration tests for the strings of the shared PHP security notice (issue #44).
 *
 * A WordPress.org language pack wins over the bundled translation and has no
 * translation for new strings until they are translated on
 * translate.wordpress.org, so the notice would be shown in English on Japanese
 * sites. wc_gateway_paygent_php_security_notice_strings() takes the strings the
 * loaded translations lack from the bundled .mo, and only while it runs.
 */
class PhpSecurityNoticeTest extends TestCase {

	private const DOMAIN = 'woocommerce-for-paygent-payment-main';

	public function test_strings_missing_from_the_language_pack_use_the_bundled_translation(): void {
		$pack = trailingslashit( get_temp_dir() ) . self::DOMAIN . '-ja-' . wp_generate_password( 12, false ) . '.mo';
		$mo   = new \MO();
		$mo->add_entry(
			new \Translation_Entry(
				array(
					'singular'     => 'Close',
					'translations' => array( 'Close (language pack)' ),
				)
			)
		);
		$this->assertTrue( $mo->export_to_file( $pack ) );

		$controller      = \WP_Translation_Controller::get_instance();
		$previous_locale = $controller->get_locale();
		$japanese        = static function () {
			return 'ja';
		};
		add_filter( 'pre_determine_locale', $japanese );
		unload_textdomain( self::DOMAIN, true );
		try {
			$this->assertTrue( load_textdomain( self::DOMAIN, $pack, 'ja' ) );

			$strings = wc_gateway_paygent_php_security_notice_strings();

			$this->assertSame( 'Close (language pack)', $strings['close'], 'The language pack wins where it has a translation.' );
			$this->assertSame( 'PHP の重大な脆弱性: 今すぐ更新してください', $strings['title'] );
			$this->assertSame( '今後表示しない', $strings['dismiss'] );
			$this->assertSame( 'Update PHP now: critical security vulnerabilities', __( 'Update PHP now: critical security vulnerabilities', self::DOMAIN ), 'Other calls are left to the loaded translations.' );
		} finally {
			unload_textdomain( self::DOMAIN, true );
			remove_filter( 'pre_determine_locale', $japanese );
			$controller->set_locale( $previous_locale );
			wp_delete_file( $pack );
		}
	}

	public function test_plugin_registers_its_copy_when_wordpress_loads_it(): void {
		$class_file = realpath( dirname( __DIR__, 2 ) . '/includes/artws-php-security-notice/class-notice.php' );
		$callbacks  = array();
		foreach ( $GLOBALS['artws_php_security_notice_registry'] ?? array() as $entry ) {
			if ( realpath( $entry['class_file'] ) === $class_file ) {
				$callbacks[] = $entry['strings_callback'];
			}
		}

		$this->assertSame( array( 'wc_gateway_paygent_php_security_notice_strings' ), $callbacks );
	}
}
