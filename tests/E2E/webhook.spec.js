// @ts-check
const { test, expect } = require('@playwright/test');
const { wpCli } = require('./helpers/wp-cli');

/**
 * Webhook endpoint security and behavior tests.
 *
 * The Paygent webhook at POST /wp-json/paygent/v1/check enforces IP allowlisting:
 *   - Allowed: 27.110.52.4 (production), 202.232.189.65 (sandbox)
 *   - Others: 401 (unauthenticated) or 403 (forbidden)
 *
 * These tests verify:
 *   1. Unauthorized requests are rejected
 *   2. The endpoint exists and responds
 *   3. A CS-payment webhook with a valid order updates order status (requires local IP allowlist)
 */

const WEBHOOK_PATH = '/wp-json/paygent/v1/check';

test.describe('Webhook: /wp-json/paygent/v1/check', () => {
	test('GET request returns 405 Method Not Allowed', async ({ request, baseURL }) => {
		const response = await request.get(`${baseURL}${WEBHOOK_PATH}`);
		// REST API returns 404 for unregistered routes; 405 for wrong method.
		expect([404, 405]).toContain(response.status());
	});

	test('POST with no auth from non-Paygent IP is rejected (401 or 403)', async ({ request, baseURL }) => {
		const response = await request.post(`${baseURL}${WEBHOOK_PATH}`, {
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			data: 'payment_type=03&payment_status=20&trading_id=wc_9999999',
		});
		// WP REST API returns 401 when unauthenticated; the permission callback may
		// return 403 for non-allowlisted IPs, but 401 fires first.
		expect([401, 403]).toContain(response.status());
	});

	test('POST with invalid body and no auth is rejected', async ({ request, baseURL }) => {
		const response = await request.post(`${baseURL}${WEBHOOK_PATH}`, {
			headers: { 'Content-Type': 'application/json' },
			data: '{}',
		});
		expect([400, 401, 403]).toContain(response.status());
	});

	test('Webhook endpoint is registered (returns structured error, not 404)', async ({ request, baseURL }) => {
		const response = await request.post(`${baseURL}${WEBHOOK_PATH}`, {
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			data: 'payment_type=03&payment_status=20&trading_id=wc_1',
		});
		// 404 would mean the route was never registered.
		expect(response.status()).not.toBe(404);
	});

	// ---------------------------------------------------------------------------
	// Webhook simulation: requires the test runner IP to be in the allowlist.
	// Set E2E_WEBHOOK_FROM_ALLOWED_IP=true to run this test.
	// ---------------------------------------------------------------------------
	test('CS payment webhook updates order status to processing (local IP allowlisted)', async ({
		request,
		baseURL,
	}) => {
		if (!process.env.E2E_WEBHOOK_FROM_ALLOWED_IP) {
			test.skip(true, 'Set E2E_WEBHOOK_FROM_ALLOWED_IP=true to run webhook simulation');
			return;
		}

		// Create a test order with paygent_cs payment.
		const orderId = wpCli(
			`post create --post_type=shop_order --post_status=wc-pending --porcelain`
		).trim();
		if (!orderId) {
			test.skip(true, 'Could not create test order');
			return;
		}
		wpCli(`post meta update ${orderId} _payment_method paygent_cs`);
		wpCli(`post meta update ${orderId} _payment_method_title "コンビニ (Paygent)"`);
		wpCli(`post meta update ${orderId} _order_total 1000`);

		const tradingId = `wc_${orderId}`;

		try {
			// Simulate a CS payment completion notification from Paygent.
			// payment_status=20 means "Settled" (入金確認) for convenience store.
			const response = await request.post(`${baseURL}${WEBHOOK_PATH}`, {
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				data: [
					`payment_type=03`,
					`payment_status=20`,
					`trading_id=${tradingId}`,
					`payment_amount=1000`,
					`payment_id=test_payment_id_${orderId}`,
				].join('&'),
			});

			// Webhook handler echoes "result=0" on success.
			expect(response.status()).toBe(200);
			const body = await response.text();
			expect(body).toContain('result=0');

			// Verify order status was updated.
			await new Promise((r) => setTimeout(r, 1000));
			const statusOut = wpCli(`post get ${orderId} --field=post_status`);
			expect(['wc-processing', 'wc-completed']).toContain(statusOut.trim());
		} finally {
			wpCli(`post delete ${orderId} --force`);
		}
	});
});
