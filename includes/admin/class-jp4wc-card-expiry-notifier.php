<?php
/**
 * Card Expiry Notifier
 *
 * Handles notification for credit cards that are about to expire.
 *
 * @package WooCommerce
 * @subpackage JP4WC
 * @category Admin
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Class JP4WC_Card_Expiry_Notifier
 *
 * Handles the card expiry notification functionality.
 */
class JP4WC_Card_Expiry_Notifier {
	/**
	 * Constructor.
	 *
	 * Initialize the card expiry notification functionality.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_init', array( $this, 'jp4wc_check_card_expiry' ) );
	}

	/**
	 * Initialize the card expiry notification functionality.
	 */
	public function init() {
		if ( ! wp_next_scheduled( 'jp4wc_card_expiry_check' ) ) {
			wp_schedule_event( time(), 'daily', 'jp4wc_card_expiry_check' );
		}
		add_action( 'jp4wc_card_expiry_check', array( $this, 'jp4wc_check_expiring_cards' ) );
	}

	/**
	 * Check for expiring payment cards and send notifications to users.
	 *
	 * This function retrieves users with payment tokens, checks their expiration dates,
	 * and sends appropriate notifications based on the configured notification settings.
	 */
	public function jp4wc_check_expiring_cards() {
		$settings = get_option( 'jp4wc_card_expiry_settings' );

		if ( ! $settings['enabled'] ) {
			return;
		}

		$notification_days = intval( $settings['notification_days'] );
		$reminder_days     = intval( $settings['reminder_days'] );

		$users = get_users( array( 'meta_key' => '_woocommerce_persistent_cart_1' ) );

		foreach ( $users as $user ) {
			$tokens = WC_Payment_Tokens::get_customer_tokens( $user->ID );

			foreach ( $tokens as $token ) {
				if ( $token->get_type() !== 'CC' ) {
					continue;
				}

				$expiry_month = $token->get_expiry_month();
				$expiry_year  = $token->get_expiry_year();

				if ( empty( $expiry_month ) || empty( $expiry_year ) ) {
					continue;
				}

				$expiry_date = new DateTime( $expiry_year . '-' . $expiry_month . '-01' );
				$expiry_date->modify( 'last day of this month' );
				$now = new DateTime();

				$days_until_expiry = $expiry_date->diff( $now )->days;

				// Expiration Check.
				if ( $expiry_date < $now ) {
					continue;
				}

				// Check for sending notifications.
				$notification_type = '';
				if ( $days_until_expiry <= $reminder_days ) {
					$notification_type = 'reminder';
				} elseif ( $days_until_expiry <= $notification_days ) {
					$notification_type = 'notification';
				}

				if ( $notification_type && ! $this->is_notification_sent( $user->ID, $token->get_id(), $notification_type ) ) {
					$this->send_expiry_notification( $user, $token, $notification_type, $days_until_expiry );
					$this->log_notification( $user->ID, $token->get_id(), $token->get_last4(), $expiry_month . '/' . $expiry_year, $notification_type );
				}
			}
		}
	}
}
