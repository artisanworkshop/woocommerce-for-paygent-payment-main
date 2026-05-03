<?php

namespace Paygent\Tests\Integration\Sandbox;

/**
 * Sandbox API integration tests for convenience store payment (コンビニ決済).
 *
 * Covers telegram kinds:
 *   030 — CS payment request (コンビニ申込)
 *   033 — CS cancel (コンビニ取消)
 *
 * Uses CVSタイプ=01 (Seven-Eleven / Lawson) which always returns normal in sandbox.
 *
 * @group sandbox
 */
class CsSandboxApiTest extends SandboxApiTestCase {

	/**
	 * Build base send_data for a convenience store payment request (030).
	 */
	protected function cs_send_data( string $trading_id, int $amount = 500 ): array {
		// CVSタイプ01 = コンビニ接続タイプA (SevenEleven / Lawson), always succeeds in sandbox.
		return array(
			'trading_id'          => $trading_id,
			'payment_amount'      => (string) $amount,
			'cvs_type'            => '01',
			'cvs_company_cd'      => '00C016', // Seven-Eleven sandbox code
			'last_name'           => mb_convert_encoding( 'テスト', 'SJIS', 'UTF-8' ),
			'first_name'          => mb_convert_encoding( '太郎', 'SJIS', 'UTF-8' ),
			'last_name_kana'      => mb_convert_encoding( 'テスト', 'SJIS', 'UTF-8' ),
			'first_name_kana'     => mb_convert_encoding( 'タロウ', 'SJIS', 'UTF-8' ),
			'phone_no'            => '09012345678',
			'limit_second'        => '259200', // 3 days in seconds.
		);
	}

	// -------------------------------------------------------------------------
	// 030 — CS payment request
	// -------------------------------------------------------------------------

	public function test_cs_payment_request_succeeds(): void {
		$trading_id = $this->make_trading_id( 'cs01' );
		$send_data  = $this->cs_send_data( $trading_id, 500 );

		$response = $this->call_api( '030', $send_data );

		$this->assertPaygentSuccess( $response, 'CS payment request (030) should succeed' );
		$this->assertNotEmpty( $response['result_array'], '030 should return result rows' );
	}

	public function test_cs_payment_request_returns_receipt_no(): void {
		$trading_id = $this->make_trading_id( 'cs02' );
		$send_data  = $this->cs_send_data( $trading_id, 1000 );

		$response = $this->call_api( '030', $send_data );

		$this->assertPaygentSuccess( $response );
		// Sandbox should return some payment reference in result_array.
		$this->assertNotEmpty( $response['result_array'][0] ?? array() );
	}

	// -------------------------------------------------------------------------
	// 033 — CS cancel
	// -------------------------------------------------------------------------

	public function test_cs_cancel_after_payment_request_succeeds(): void {
		$trading_id = $this->make_trading_id( 'cscancel01' );

		// First, create a CS payment request.
		$request = $this->call_api( '030', $this->cs_send_data( $trading_id, 500 ) );
		$this->assertPaygentSuccess( $request, 'CS payment request step for cancel test' );

		$payment_id = $request['result_array'][0]['payment_id'] ?? '';

		// Cancel the CS payment.
		$cancel = $this->call_api(
			'033',
			array( 'trading_id' => $trading_id ),
			$payment_id
		);

		$this->assertPaygentSuccess( $cancel, 'CS cancel (033) should succeed' );
	}
}
