<?php
/**
 * MU-Plugin: Bypass the Paygent webhook IP check for E2E tests.
 *
 * Adds the current request's REMOTE_ADDR to the allowed IP list at runtime,
 * so the webhook endpoint accepts test requests regardless of what IP the
 * test runner appears to come from (Docker bridge, Cloudflare WARP, CI runner, etc.).
 *
 * Installation (local wp-env):
 *
 *   npx wp-env run cli sh -c \
 *     "mkdir -p /var/www/html/wp-content/mu-plugins && \
 *      cp /var/www/html/wp-content/plugins/woocommerce-for-paygent-payment-main/tests/E2E/fixtures/paygent-test-ip.php \
 *         /var/www/html/wp-content/mu-plugins/paygent-test-ip.php"
 *
 * Then set E2E_WEBHOOK_FROM_ALLOWED_IP=true before running webhook.spec.js.
 *
 * Removal after testing:
 *
 *   npx wp-env run cli rm /var/www/html/wp-content/mu-plugins/paygent-test-ip.php
 *
 * WARNING: This file allows ANY IP to hit the webhook endpoint.
 *          Never leave it in place on a production or staging server.
 */

add_filter(
	'paygent_permitted_ips',
	static function ( array $ips ): array {
		// Add the actual remote IP of the current request.
		// This handles Docker bridge IPs, Cloudflare WARP, CI runner IPs, etc.
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ips[] = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return $ips;
	}
);
