<?php

namespace Paygent\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Tests for WC_Gateway_Paygent_Request::make_hash_data()
 *
 * The hash is SHA-256 of concatenated values + hash_code:
 *   sha256( value1 . value2 . ... . hash_code )
 */
class HashCalculationTest extends TestCase {

	/** @var \WC_Gateway_Paygent_Request */
	private $request;

	protected function setUp(): void {
		parent::setUp();

		// Stub WordPress option functions required by the constructor.
		Functions\when( 'get_option' )->justReturn( '' );

		require_once dirname( __DIR__, 2 ) . '/includes/gateways/paygent/includes/class-wc-gateway-paygent-request.php';

		$this->request = new \WC_Gateway_Paygent_Request();
	}

	public function test_empty_data_with_hash_code(): void {
		$result = $this->request->make_hash_data( array(), 'secret' );

		$this->assertSame( hash( 'sha256', 'secret' ), $result );
	}

	public function test_single_value_concatenated_before_hash_code(): void {
		$hash_data = array( 'merchant_id' => '12345' );
		$result    = $this->request->make_hash_data( $hash_data, 'HASHCODE' );

		$this->assertSame( hash( 'sha256', '12345HASHCODE' ), $result );
	}

	public function test_multiple_values_concatenated_in_order(): void {
		$hash_data = array(
			'merchant_id'    => '12345',
			'telegram_kind'  => '020',
			'payment_amount' => '1000',
			'request_date'   => '20260418120000',
		);
		$result    = $this->request->make_hash_data( $hash_data, 'HASHCODE' );

		$expected = hash( 'sha256', '12345020100020260418120000HASHCODE' );
		$this->assertSame( $expected, $result );
	}

	public function test_hash_is_64_character_hex_string(): void {
		$result = $this->request->make_hash_data( array( 'val' => 'test' ), 'code' );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $result );
	}

	public function test_different_hash_codes_produce_different_results(): void {
		$hash_data = array( 'payment_id' => '999' );

		$result1 = $this->request->make_hash_data( $hash_data, 'code_a' );
		$result2 = $this->request->make_hash_data( $hash_data, 'code_b' );

		$this->assertNotSame( $result1, $result2 );
	}

	public function test_null_value_is_skipped(): void {
		$hash_data = array(
			'payment_id'     => '999',
			'optional_field' => null,
			'request_date'   => '20260418',
		);
		$result    = $this->request->make_hash_data( $hash_data, 'CODE' );

		// null is skipped: concatenation is '999' . '20260418' . 'CODE'
		$expected = hash( 'sha256', '99920260418CODE' );
		$this->assertSame( $expected, $result );
	}
}
