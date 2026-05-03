<?php

namespace Paygent\Tests\Unit;

/**
 * Static analysis tests for HPOS (High Performance Order Storage) compliance.
 *
 * Scans plugin source files to detect direct WordPress post meta calls on order
 * data. WooCommerce HPOS requires all order meta to go through the WC_Order API:
 *   - GOOD: $order->get_meta('_key') / $order->update_meta_data('_key', $val)
 *   - BAD:  get_post_meta($order_id, '_key', true)
 *
 * Any violation here means the plugin will break when HPOS is enabled.
 */
class HposComplianceTest extends TestCase {

	/** Source directories to scan (relative to plugin root). */
	private const SCAN_DIRS = array(
		'includes/gateways/paygent',
		'includes/admin',
	);

	/** Files known to have legacy post meta calls that need fixing (tracked separately). */
	private const KNOWN_VIOLATIONS = array(
		'includes/gateways/paygent/class-wc-gateway-paygent-atm.php',
	);

	/**
	 * @return list<array{string, string}>  [file_relative_path, matched_line]
	 */
	private function find_post_meta_calls(): array {
		$plugin_root = dirname( __DIR__, 2 );
		$violations  = array();
		$pattern     = '/\b(get_post_meta|update_post_meta|delete_post_meta|add_post_meta)\s*\(/';

		foreach ( self::SCAN_DIRS as $dir ) {
			$full_dir = $plugin_root . '/' . $dir;
			if ( ! is_dir( $full_dir ) ) {
				continue;
			}

			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $full_dir, \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( 'php' !== $file->getExtension() ) {
					continue;
				}

				$relative = str_replace( $plugin_root . '/', '', $file->getPathname() );

				// Skip known violations — they're tracked separately.
				if ( in_array( $relative, self::KNOWN_VIOLATIONS, true ) ) {
					continue;
				}

				$lines = file( $file->getPathname() );
				foreach ( $lines as $line_no => $line ) {
					if ( preg_match( $pattern, $line ) ) {
						$violations[] = array( $relative, ( $line_no + 1 ) . ': ' . trim( $line ) );
					}
				}
			}
		}

		return $violations;
	}

	public function test_no_direct_post_meta_calls_on_order_data(): void {
		$violations = $this->find_post_meta_calls();

		if ( ! empty( $violations ) ) {
			$messages = array_map(
				fn( $v ) => "  {$v[0]}:{$v[1]}",
				$violations
			);
			$this->fail(
				"HPOS violation — use \$order->get_meta() / update_meta_data() instead:\n" .
				implode( "\n", $messages )
			);
		}

		$this->assertTrue( true );
	}

	public function test_known_violations_file_still_exists(): void {
		// Ensures KNOWN_VIOLATIONS stays accurate — if the file is fixed, remove it from the list.
		$plugin_root = dirname( __DIR__, 2 );
		foreach ( self::KNOWN_VIOLATIONS as $path ) {
			$this->assertFileExists(
				$plugin_root . '/' . $path,
				"Known violation file not found — remove it from KNOWN_VIOLATIONS: {$path}"
			);
		}
	}

	public function test_gateway_files_use_order_get_meta_api(): void {
		$plugin_root = dirname( __DIR__, 2 );
		$gateway_dir = $plugin_root . '/includes/gateways/paygent';
		$found       = false;

		foreach ( new \DirectoryIterator( $gateway_dir ) as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			$content = file_get_contents( $file->getPathname() );
			if ( str_contains( $content, '->get_meta(' ) || str_contains( $content, '->update_meta_data(' ) ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'Gateway files should use $order->get_meta() / update_meta_data()' );
	}
}
