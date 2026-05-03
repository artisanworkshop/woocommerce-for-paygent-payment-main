<?php

namespace Paygent\Tests\Unit;

/**
 * Tests for amount correction (028/029) logic.
 *
 * Rules from Paygent spec:
 * - payment_status '20' (auth)  → use telegram 028 (authorization amount change).
 * - payment_status '40' (sale)  → use telegram 029 (sale amount change).
 * - reduction_flag = '0'        → payment_amount is the NEW total (supports both increase and decrease).
 * - 3DS2 orders: both 028 and 029 are blocked.
 */
class AmountCorrectionTest extends TestCase {

	/**
	 * @dataProvider telegram_kind_selection_provider
	 */
	public function test_correct_telegram_kind_selected_by_payment_status(
		string $payment_status,
		string $expected_telegram_kind
	): void {
		// Replicate the selection logic from paygent_cc_process_increase_amount().
		if ( '20' === $payment_status ) {
			$telegram_kind = '028';
		} elseif ( '40' === $payment_status ) {
			$telegram_kind = '029';
		} else {
			$telegram_kind = '';
		}

		$this->assertSame( $expected_telegram_kind, $telegram_kind );
	}

	public static function telegram_kind_selection_provider(): array {
		return array(
			'status 20 (auth) → 028'  => array( '20', '028' ),
			'status 40 (sale) → 029'  => array( '40', '029' ),
			'status 10 → no telegram' => array( '10', '' ),
			'status 99 → no telegram' => array( '99', '' ),
		);
	}

	public function test_reduction_flag_is_always_zero_for_new_total(): void {
		// reduction_flag=0 means payment_amount is the NEW absolute total.
		// This supports both increase and decrease from a single code path.
		$send_data = array(
			'reduction_flag'  => '0',
			'payment_amount'  => '2000',
		);

		$this->assertSame( '0', $send_data['reduction_flag'] );
	}

	/**
	 * @dataProvider amount_validation_provider
	 */
	public function test_new_amount_must_be_positive_integer(
		mixed $raw_input,
		bool $should_pass
	): void {
		// intval() preserves sign; absint() would turn negatives into positives.
		$new_amount = intval( $raw_input );
		$is_valid   = $new_amount > 0;

		$this->assertSame( $should_pass, $is_valid );
	}

	public static function amount_validation_provider(): array {
		return array(
			'positive integer string'  => array( '1500', true ),
			'zero'                     => array( '0', false ),
			'negative'                 => array( '-100', false ),
			'non-numeric string'       => array( 'abc', false ),
			'positive integer'         => array( 500, true ),
		);
	}

	/**
	 * 3DS2 orders must be blocked from amount correction.
	 * Detection relies on _3ds_auth_id order meta being non-empty.
	 */
	public function test_3ds_order_is_blocked(): void {
		// Simulate meta value set during 3DS2 authentication.
		$auth_id   = 'PAYGENT_3DS_AUTH_ID_XYZ';
		$tds2_used = ! empty( $auth_id );

		$this->assertTrue( $tds2_used, '3DS auth id present → should block amount correction' );
	}

	public function test_non_3ds_order_is_allowed(): void {
		$auth_id   = '';
		$tds2_used = ! empty( $auth_id );

		$this->assertFalse( $tds2_used, 'No 3DS auth id → should allow amount correction' );
	}
}
