<?php

namespace Paygent\Tests\Integration\Sandbox;

/**
 * Sandbox API integration tests for credit card payment flows.
 *
 * Covers the following telegram kinds:
 *   020 — CC auth (オーソリ申込)
 *   023 — Auth cancel (オーソリ取消)
 *   022 — Sale/capture (売上)
 *   028 — Sale cancel (売上取消)
 *   094 — Inquiry (照会)
 *
 * All tests are skipped when sandbox credentials are absent.
 * See SandboxApiTestCase for credential configuration.
 *
 * @group sandbox
 */
class CcSandboxApiTest extends SandboxApiTestCase {

	// -------------------------------------------------------------------------
	// 020 — Auth (オーソリ)
	// -------------------------------------------------------------------------

	public function test_cc_auth_succeeds_with_test_card(): void {
		$trading_id = $this->make_trading_id( 'auth01' );
		$send_data  = $this->cc_auth_send_data( $trading_id, 100 );

		$response = $this->call_api( '020', $send_data );

		$this->assertPaygentSuccess( $response, 'CC auth (020) should succeed with test card' );
		$this->assertArrayHasKey( 'result_array', $response );
		$this->assertNotEmpty( $response['result_array'] );
		$this->assertArrayHasKey( 'payment_id', $response['result_array'][0] );
		$this->assertNotEmpty( $response['result_array'][0]['payment_id'] );
	}

	public function test_cc_auth_returns_payment_id(): void {
		$trading_id = $this->make_trading_id( 'auth02' );
		$send_data  = $this->cc_auth_send_data( $trading_id, 500 );

		$response  = $this->call_api( '020', $send_data );

		$this->assertPaygentSuccess( $response );
		$payment_id = $response['result_array'][0]['payment_id'] ?? '';
		$this->assertNotEmpty( $payment_id, 'payment_id must be returned after successful auth' );
	}

	public function test_cc_auth_with_duplicate_trading_id_fails(): void {
		$trading_id = $this->make_trading_id( 'dup01' );
		$send_data  = $this->cc_auth_send_data( $trading_id, 100 );

		// First auth — should succeed.
		$first = $this->call_api( '020', $send_data );
		$this->assertPaygentSuccess( $first, 'First auth should succeed' );

		// Second auth with same trading_id — should fail (duplicate).
		$second = $this->call_api( '020', $send_data );
		$this->assertPaygentError( $second, 'Duplicate trading_id should return an error' );

		// Cleanup: cancel the first auth.
		$payment_id = $first['result_array'][0]['payment_id'] ?? '';
		if ( $payment_id ) {
			$this->call_api( '023', array( 'trading_id' => $trading_id ), $payment_id );
		}
	}

	// -------------------------------------------------------------------------
	// 023 — Auth cancel (オーソリ取消)
	// -------------------------------------------------------------------------

	public function test_cc_auth_cancel_succeeds(): void {
		$trading_id = $this->make_trading_id( 'cancel01' );

		// Authorize first.
		$auth = $this->call_api( '020', $this->cc_auth_send_data( $trading_id, 100 ) );
		$this->assertPaygentSuccess( $auth, 'Auth step must succeed before cancel' );

		$payment_id = $auth['result_array'][0]['payment_id'];

		// Cancel auth.
		$cancel = $this->call_api( '023', array( 'trading_id' => $trading_id ), $payment_id );

		$this->assertPaygentSuccess( $cancel, 'Auth cancel (023) should succeed' );
	}

	public function test_cc_auth_cancel_on_unknown_payment_id_fails(): void {
		$cancel = $this->call_api(
			'023',
			array( 'trading_id' => 'nonexistent_xyz99' ),
			'INVALID_PAYMENT_ID'
		);

		$this->assertPaygentError( $cancel, 'Cancel with unknown payment_id should fail' );
	}

	// -------------------------------------------------------------------------
	// 022 — Sale / capture (売上)
	// -------------------------------------------------------------------------

	public function test_cc_auth_then_capture_succeeds(): void {
		$trading_id = $this->make_trading_id( 'cap01' );

		// Authorize.
		$auth = $this->call_api( '020', $this->cc_auth_send_data( $trading_id, 200 ) );
		$this->assertPaygentSuccess( $auth, 'Auth step must succeed before capture' );

		$payment_id = $auth['result_array'][0]['payment_id'];

		// Capture (売上).
		$capture = $this->call_api(
			'022',
			array(
				'trading_id'     => $trading_id,
				'payment_amount' => '200',
			),
			$payment_id
		);

		$this->assertPaygentSuccess( $capture, 'Capture (022) should succeed after auth' );
	}

	// -------------------------------------------------------------------------
	// 028 — Sale cancel (売上取消)
	// -------------------------------------------------------------------------

	public function test_cc_full_cycle_auth_capture_then_cancel(): void {
		$trading_id = $this->make_trading_id( 'full01' );
		$amount     = 300;

		// 1. Auth (020).
		$auth = $this->call_api( '020', $this->cc_auth_send_data( $trading_id, $amount ) );
		$this->assertPaygentSuccess( $auth, 'Auth (020)' );
		$payment_id = $auth['result_array'][0]['payment_id'];

		// 2. Capture (022).
		$capture = $this->call_api(
			'022',
			array( 'trading_id' => $trading_id, 'payment_amount' => (string) $amount ),
			$payment_id
		);
		$this->assertPaygentSuccess( $capture, 'Capture (022)' );

		// 3. Sale cancel (028).
		$cancel = $this->call_api(
			'028',
			array( 'trading_id' => $trading_id ),
			$payment_id
		);
		$this->assertPaygentSuccess( $cancel, 'Sale cancel (028)' );
	}

	// -------------------------------------------------------------------------
	// 094 — Inquiry (照会)
	// -------------------------------------------------------------------------

	public function test_inquiry_on_completed_auth_returns_payment_info(): void {
		$trading_id = $this->make_trading_id( 'inq01' );

		// Authorize to create a transaction.
		$auth = $this->call_api( '020', $this->cc_auth_send_data( $trading_id, 100 ) );
		$this->assertPaygentSuccess( $auth, 'Auth step for inquiry test' );
		$payment_id = $auth['result_array'][0]['payment_id'];

		// Inquire about the transaction.
		$inquiry = $this->call_api(
			'094',
			array( 'trading_id' => $trading_id ),
			$payment_id
		);

		$this->assertPaygentSuccess( $inquiry, 'Inquiry (094) on known transaction' );
		$this->assertNotEmpty( $inquiry['result_array'], '094 should return result rows' );

		// Cleanup.
		$this->call_api( '023', array( 'trading_id' => $trading_id ), $payment_id );
	}

	public function test_inquiry_on_unknown_trading_id_returns_error(): void {
		$inquiry = $this->call_api(
			'094',
			array( 'trading_id' => 'phpunit_no_such_trading_id_xyz' ),
			'INVALID'
		);

		$this->assertPaygentError( $inquiry, 'Inquiry on unknown trading_id should return error' );
	}

	// -------------------------------------------------------------------------
	// Amount boundary
	// -------------------------------------------------------------------------

	public function test_cc_auth_with_minimum_amount_1_yen(): void {
		$trading_id = $this->make_trading_id( 'min01' );
		$send_data  = $this->cc_auth_send_data( $trading_id, 1 );

		$response = $this->call_api( '020', $send_data );

		$this->assertPaygentSuccess( $response, '1 JPY auth should succeed' );

		// Cleanup.
		$payment_id = $response['result_array'][0]['payment_id'] ?? '';
		if ( $payment_id ) {
			$this->call_api( '023', array( 'trading_id' => $trading_id ), $payment_id );
		}
	}

	public function test_cc_auth_with_large_amount(): void {
		$trading_id = $this->make_trading_id( 'large01' );
		$send_data  = $this->cc_auth_send_data( $trading_id, 999999 );

		$response = $this->call_api( '020', $send_data );

		$this->assertPaygentSuccess( $response, '999,999 JPY auth should succeed in sandbox' );

		// Cleanup.
		$payment_id = $response['result_array'][0]['payment_id'] ?? '';
		if ( $payment_id ) {
			$this->call_api( '023', array( 'trading_id' => $trading_id ), $payment_id );
		}
	}
}
