<?php

namespace Paygent\Tests\Integration\Sandbox;

use Paygent\Tests\Integration\TestCase;

/**
 * Base class for Paygent sandbox API integration tests.
 *
 * Requires sandbox credentials set as WordPress options OR as environment
 * variables. All tests are skipped when credentials are absent.
 *
 * Required credentials (via wp-env tests-cli env vars or WP options):
 *   PAYGENT_TEST_MID   / wc-paygent-test-mid    — Merchant ID
 *   PAYGENT_TEST_CID   / wc-paygent-test-cid    — Connect ID
 *   PAYGENT_TEST_CPASS / wc-paygent-test-cpass  — Connect Password
 *
 * Optional:
 *   PAYGENT_TEST_HASH_CHECK=1  — Use hash-secured endpoint (skips client cert)
 *   PAYGENT_TEST_HASH_CODE     — Hash code for hash-check mode
 *   PAYGENT_TEST_PREFIX        — Order ID prefix (default: "test_")
 *
 * Usage (inside wp-env tests-cli):
 *   PAYGENT_TEST_MID=xxx PAYGENT_TEST_CID=yyy PAYGENT_TEST_CPASS=zzz \
 *   php vendor/bin/phpunit -c phpunit-integration.xml.dist --filter Sandbox
 */
abstract class SandboxApiTestCase extends TestCase {

	/** Sandbox test card number — always returns normal auth result. */
	const CARD_NUMBER_OK = '4900000000000000';

	/** Test card that fails auth (last 4 digits control result). */
	const CARD_NUMBER_AUTH_NG = '4900000000001000';

	/** Card valid term in YYYYMM format (5 years from now). */
	protected string $card_valid_term;

	/** Unique order prefix for this test run. */
	protected string $trading_id_prefix;

	/** @var WC_Gateway_Paygent_Request */
	protected $paygent_request;

	public function setUp(): void {
		parent::setUp();

		$this->require_sandbox_credentials();
		$this->configure_sandbox_options();

		$this->card_valid_term    = date( 'Ym', strtotime( '+5 years' ) );
		$this->trading_id_prefix  = 'phpunit_' . substr( uniqid(), -6 ) . '_';

		// Instantiate the request class (reads credentials from WP options).
		require_once WC_PAYGENT_PLUGIN_PATH . 'includes/gateways/paygent/includes/class-wc-gateway-paygent-request.php';
		$this->paygent_request = new \WC_Gateway_Paygent_Request();
	}

	public function tearDown(): void {
		$this->cleanup_sandbox_options();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Credential resolution
	// -------------------------------------------------------------------------

	/**
	 * Skip the test if sandbox credentials are not available.
	 */
	protected function require_sandbox_credentials(): void {
		$mid   = $this->resolve_credential( 'PAYGENT_TEST_MID', 'wc-paygent-test-mid' );
		$cid   = $this->resolve_credential( 'PAYGENT_TEST_CID', 'wc-paygent-test-cid' );
		$cpass = $this->resolve_credential( 'PAYGENT_TEST_CPASS', 'wc-paygent-test-cpass' );

		if ( ! $mid || ! $cid || ! $cpass ) {
			$this->markTestSkipped(
				'Sandbox credentials not configured. Set PAYGENT_TEST_MID, ' .
				'PAYGENT_TEST_CID, PAYGENT_TEST_CPASS as environment variables ' .
				'or WordPress options.'
			);
		}
	}

	/**
	 * Resolve a credential from env var or WordPress option.
	 */
	protected function resolve_credential( string $env_var, string $wp_option ): string {
		$from_env = getenv( $env_var );
		if ( $from_env ) {
			return $from_env;
		}
		return (string) get_option( $wp_option, '' );
	}

	/**
	 * Write sandbox credentials from env vars to WordPress options so that
	 * WC_Gateway_Paygent_Request picks them up via get_option().
	 */
	protected function configure_sandbox_options(): void {
		$mid   = $this->resolve_credential( 'PAYGENT_TEST_MID', 'wc-paygent-test-mid' );
		$cid   = $this->resolve_credential( 'PAYGENT_TEST_CID', 'wc-paygent-test-cid' );
		$cpass = $this->resolve_credential( 'PAYGENT_TEST_CPASS', 'wc-paygent-test-cpass' );

		update_option( 'wc-paygent-test-mid', $mid );
		update_option( 'wc-paygent-test-cid', $cid );
		update_option( 'wc-paygent-test-cpass', $cpass );
		update_option( 'wc-paygent-testmode', '1' );

		// Hash-check mode (skips client cert; uses /s/ URLs).
		$hash_check = getenv( 'PAYGENT_TEST_HASH_CHECK' ) ?: get_option( 'wc-paygent-hash_check', '0' );
		update_option( 'wc-paygent-hash_check', $hash_check );

		if ( '1' === $hash_check ) {
			$hash_code = $this->resolve_credential( 'PAYGENT_TEST_HASH_CODE', 'wc-paygent-test-hash_code' );
			if ( $hash_code ) {
				update_option( 'wc-paygent-test-hash_code', $hash_code );
			}
		}

		$prefix = getenv( 'PAYGENT_TEST_PREFIX' ) ?: 'test_';
		update_option( 'wc-paygent-prefix_order', $prefix );
	}

	protected function cleanup_sandbox_options(): void {
		// Leave credentials in place (they may have been set before the test);
		// only remove the fields we explicitly set.
		delete_option( 'wc-paygent-testmode' );
		delete_option( 'wc-paygent-prefix_order' );
	}

	// -------------------------------------------------------------------------
	// Request helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a unique trading_id for this test.
	 */
	protected function make_trading_id( string $suffix = '' ): string {
		return $this->trading_id_prefix . $suffix;
	}

	/**
	 * Build base send_data for a CC auth (telegram_kind 020).
	 */
	protected function cc_auth_send_data( string $trading_id, int $amount = 100 ): array {
		return array(
			'trading_id'     => $trading_id,
			'payment_id'     => '',
			'payment_amount' => (string) $amount,
			'card_number'    => self::CARD_NUMBER_OK,
			'card_valid_term' => $this->card_valid_term,
			'payment_class'  => '10', // 一括払い
		);
	}

	/**
	 * Assert a Paygent API response is successful.
	 */
	protected function assertPaygentSuccess( array $response, string $message = '' ): void {
		$detail = $message ? $message . ' — ' : '';
		if ( '0' !== $response['result'] ) {
			$detail .= sprintf(
				'Paygent API returned result=%s, responseCode=%s, responseDetail=%s',
				$response['result'],
				$response['responseCode'] ?? '',
				isset( $response['responseDetail'] )
					? mb_convert_encoding( $response['responseDetail'], 'UTF-8', 'SJIS' )
					: ''
			);
		}
		$this->assertSame( '0', $response['result'], $detail );
	}

	/**
	 * Assert a Paygent API response returned an error.
	 */
	protected function assertPaygentError( array $response, string $message = '' ): void {
		$this->assertNotSame( '0', $response['result'], $message ?: 'Expected API error but got success.' );
	}

	/**
	 * Call the Paygent API and return the result array.
	 *
	 * @param string $telegram_kind e.g. '020', '023', '094'
	 * @param array  $send_data     Request parameters.
	 * @param string $payment_id    Payment ID from a previous response (for cancel/capture).
	 */
	protected function call_api( string $telegram_kind, array $send_data, string $payment_id = '' ): array {
		if ( $payment_id ) {
			$send_data['payment_id'] = $payment_id;
		}
		return $this->paygent_request->send_paygent_request(
			'1',   // test_mode
			null,  // no WC_Order needed for direct API calls
			$telegram_kind,
			$send_data,
			'no'   // debug off
		);
	}
}
