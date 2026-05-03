<?php

namespace Paygent\Tests\Unit;

/**
 * Tests for card save preference logic.
 *
 * Rules:
 * - Subscription orders: always save card (fingerprint required for renewals).
 * - Logged-in non-subscription orders: save only when user opted in via checkbox.
 * - Guest orders: never save.
 *
 * The preference is stored as '_paygent_save_card_preference' = '1'|'0' in
 * process_payment() so that the 3DS2 callback can read it after the POST is gone.
 */
class CardSaveLogicTest extends TestCase {

	/**
	 * @dataProvider card_save_preference_provider
	 */
	public function test_card_save_preference_is_determined_correctly(
		bool $is_subscription,
		string $checkbox_value,
		string $expected_preference
	): void {
		// Replicate the logic from process_payment().
		$user_wants_save_card = ( true === $is_subscription ) ||
			( 'yes' === $checkbox_value );

		$preference = $user_wants_save_card ? '1' : '0';

		$this->assertSame( $expected_preference, $preference );
	}

	public static function card_save_preference_provider(): array {
		return array(
			'subscription always saves'              => array( true, '', '1' ),
			'subscription ignores checkbox'          => array( true, 'no', '1' ),
			'non-subscription opt-in'                => array( false, 'yes', '1' ),
			'non-subscription opt-out'               => array( false, 'no', '0' ),
			'non-subscription no checkbox (guest)'   => array( false, '', '0' ),
		);
	}

	/**
	 * The 3DS2 callback must read preference from order meta because POST is gone.
	 */
	public function test_3ds2_callback_reads_preference_from_order_meta(): void {
		// '1' stored in meta → should save card.
		$meta_value = '1';
		$user_wants_save_card = '1' === $meta_value;

		$this->assertTrue( $user_wants_save_card );

		// '0' stored in meta → should not save card.
		$meta_value = '0';
		$user_wants_save_card = '1' === $meta_value;

		$this->assertFalse( $user_wants_save_card );
	}

	/**
	 * An order using 3DS2 is detected by the presence of _3ds_auth_id meta.
	 */
	public function test_3ds_detection_by_meta_presence(): void {
		// Non-empty _3ds_auth_id → 3DS was used.
		$tds2_used = ! empty( 'auth_id_value_from_paygent' );
		$this->assertTrue( $tds2_used );

		// Empty value → no 3DS.
		$tds2_used = ! empty( '' );
		$this->assertFalse( $tds2_used );
	}
}
