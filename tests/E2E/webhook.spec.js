// @ts-check
const { test, expect } = require('@playwright/test');
const { wpCli } = require('./helpers/wp-cli');

/**
 * Webhook endpoint tests for POST /wp-json/paygent/v1/check
 *
 * Tests are split into two layers:
 *
 * Layer 1 — Security / registration (always runs, no credentials needed)
 *   Verifies the endpoint is registered, rejects wrong methods, and blocks
 *   requests from non-Paygent IPs.
 *
 * Layer 2 — Webhook simulation (requires E2E_WEBHOOK_FROM_ALLOWED_IP=true)
 *   Simulates Paygent payment notifications and verifies order status updates.
 *   This layer can run in two modes:
 *
 *   a) Local wp-env mode (default):
 *      Uses `npx wp-env run cli` to create/verify HPOS orders.
 *      Requires E2E_WEBHOOK_FROM_ALLOWED_IP=true to bypass IP check.
 *
 *   b) Demo site mode (E2E_BASE_URL=https://paygent.demo01web.info/):
 *      Uses SSH + WP-CLI on the remote server to manage orders.
 *      Set E2E_DEMO_SSH="user@host" and E2E_DEMO_WP_PATH="/path/to/wp".
 *      The test runner IP must be added to `paygent_permitted_ips` filter
 *      via a mu-plugin on the demo site (see docs/webhook-demo-testing.md).
 *
 * Environment variables:
 *   E2E_WEBHOOK_FROM_ALLOWED_IP  Set to "true" to run simulation tests
 *   E2E_BASE_URL                 Override base URL (default: http://localhost:8888)
 *   E2E_DEMO_SSH                 SSH target for demo site (e.g. "user@demo.example.com")
 *   E2E_DEMO_WP_PATH             WordPress root path on demo server
 *
 * Paygent IP allowlist (class-wc-paygent-endpoint.php):
 *   Production: 27.110.52.4
 *   Sandbox:    202.232.189.65
 */

const WEBHOOK_PATH = '/wp-json/paygent/v1/check';
const IS_DEMO_SITE = (process.env.E2E_BASE_URL || '').includes('demo01web.info');
const SIMULATION_ENABLED = process.env.E2E_WEBHOOK_FROM_ALLOWED_IP === 'true';

// ─── WP-CLI helper (local or remote) ─────────────────────────────────────────

/**
 * Run a WP-CLI command against the correct environment.
 * - Local: uses wp-env run cli
 * - Demo site: uses SSH + wp-cli with --path
 *
 * @param {string} cmd WP-CLI subcommand (without leading "wp")
 * @returns {string} stdout trimmed
 */
function siteWpCli(cmd) {
	if (IS_DEMO_SITE) {
		const ssh    = process.env.E2E_DEMO_SSH || '';
		const wpPath = process.env.E2E_DEMO_WP_PATH || '';
		if (!ssh || !wpPath) {
			throw new Error('E2E_DEMO_SSH and E2E_DEMO_WP_PATH must be set for demo site tests');
		}
		const { execSync } = require('child_process');
		return execSync(`ssh ${ssh} "wp --path=${wpPath} ${cmd}"`, {
			encoding: 'utf8',
			stdio: ['pipe', 'pipe', 'pipe'],
		}).trim();
	}
	return wpCli(cmd);
}

/**
 * Extract the last integer from WP-CLI output.
 * wp-env may prepend PHP Deprecated notices to stdout; this strips them.
 *
 * @param {string} raw Raw stdout from wpCli / siteWpCli
 * @returns {string} Numeric ID string, or ""
 */
function extractId(raw) {
	const match = raw.match(/(\d+)\s*$/);
	return match ? match[1] : '';
}

/**
 * Create a pending CS (convenience store) order via WP-CLI (HPOS-compatible).
 *
 * @param {string} tradingId  Value to store in _paygent_order_id meta
 * @returns {string} WooCommerce order ID
 */
function createTestOrder(tradingId) {
	// wp wc shop_order create uses the WooCommerce data store — HPOS-safe.
	// Note: --total is not a supported parameter; use eval to set line items if needed.
	const raw = siteWpCli(
		`wc shop_order create --status=pending --payment_method=paygent_cs --porcelain --user=1`
	);
	const orderId = extractId(raw);

	if (!orderId) {
		throw new Error(`Failed to create test order (got: "${raw}")`);
	}

	// Store the trading ID used by the webhook handler to look up the order.
	siteWpCli(
		`eval "wc_get_order(${orderId})->update_meta_data('_paygent_order_id','${tradingId}'); wc_get_order(${orderId})->save();"`
	);

	return orderId;
}

/**
 * Get order status via WP-CLI (HPOS-compatible).
 * Strips any PHP notices (e.g. Deprecated) that may appear before the status.
 *
 * @param {string} orderId
 * @returns {string} Status slug without "wc-" prefix (e.g. "processing")
 */
function getOrderStatus(orderId) {
	const raw = siteWpCli(`eval "echo wc_get_order(${orderId})->get_status();"`);
	// The status is always a lowercase slug on the last non-empty line.
	const lines = raw.split('\n').map(l => l.trim()).filter(Boolean);
	return lines[lines.length - 1] || '';
}

/**
 * Delete an order via WP-CLI (HPOS-compatible).
 *
 * @param {string} orderId
 */
function deleteOrder(orderId) {
	siteWpCli(`wc shop_order delete ${orderId} --force=true --user=1`);
}

// ─── Tests ────────────────────────────────────────────────────────────────────

test.describe('Webhook: /wp-json/paygent/v1/check', () => {

	// =========================================================================
	// Layer 1: Security & registration (always runs)
	// =========================================================================

	test('GET returns 404 or 405 — only POST is registered', async ({ request, baseURL }) => {
		const response = await request.get(`${baseURL}${WEBHOOK_PATH}`);
		expect([404, 405]).toContain(response.status());
	});

	test('POST from non-Paygent IP is rejected with 401', async ({ request, baseURL }) => {
		// When SIMULATION_ENABLED the test IP mu-plugin is installed, which adds the
		// runner's REMOTE_ADDR to the allowlist — so the rejection test cannot run.
		test.skip(SIMULATION_ENABLED, 'Skipped: mu-plugin is installed (IP allowlist bypassed). Remove paygent-test-ip.php from mu-plugins to test rejection.');

		const response = await request.post(`${baseURL}${WEBHOOK_PATH}`, {
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			data: 'payment_type=03&payment_status=20&trading_id=wc_9999999',
		});
		// permission_callback returns false → WP REST API returns 401.
		expect(response.status()).toBe(401);
	});

	test('POST with JSON body from non-Paygent IP is rejected', async ({ request, baseURL }) => {
		test.skip(SIMULATION_ENABLED, 'Skipped: mu-plugin is installed (IP allowlist bypassed). Remove paygent-test-ip.php from mu-plugins to test rejection.');

		const response = await request.post(`${baseURL}${WEBHOOK_PATH}`, {
			headers: { 'Content-Type': 'application/json' },
			data: '{}',
		});
		expect([400, 401, 403]).toContain(response.status());
	});

	test('Endpoint is registered — non-404 response confirms route exists', async ({ request, baseURL }) => {
		const response = await request.post(`${baseURL}${WEBHOOK_PATH}`, {
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			data: 'payment_type=03&payment_status=20&trading_id=wc_1',
		});
		// 404 would mean the route was never registered by the plugin.
		expect(response.status()).not.toBe(404);
	});

	// =========================================================================
	// Layer 2: Webhook simulation (requires E2E_WEBHOOK_FROM_ALLOWED_IP=true)
	//
	// For local wp-env: add the test runner IP to the paygent_permitted_ips
	// filter via a mu-plugin, then set E2E_WEBHOOK_FROM_ALLOWED_IP=true.
	//
	// For demo site: the IP 202.232.189.65 (Paygent sandbox) must POST to the
	// endpoint. Trigger from Paygent sandbox admin or use a mu-plugin to
	// temporarily whitelist the test runner IP.
	// =========================================================================

	test.describe('Webhook simulation (IP allowlisted)', () => {
		test.beforeEach(() => {
			if (!SIMULATION_ENABLED) {
				test.skip(true, [
					'Skipped: set E2E_WEBHOOK_FROM_ALLOWED_IP=true to run simulation tests.',
					IS_DEMO_SITE
						? 'Demo site: ensure runner IP is added to paygent_permitted_ips filter.'
						: 'Local: add runner IP via mu-plugin (see tests/E2E/fixtures/paygent-test-ip.php).',
				].join(' '));
			}
		});

		test('CS payment notification (payment_status=40) updates order to processing', async ({
			request,
			baseURL,
		}) => {
			test.setTimeout(30_000);
			const orderId  = createTestOrder(`wc_cs_test_${Date.now()}`);
			const tradingId = `wc_${orderId}`;

			// Update the meta to match the trading_id format the webhook handler expects.
			siteWpCli(
				`eval "wc_get_order(${orderId})->update_meta_data('_paygent_order_id',''); wc_get_order(${orderId})->save();"`
			);

			try {
				// payment_type=03 (CS), payment_status=40 (Sales Completed) → processing
				const response = await request.post(`${baseURL}${WEBHOOK_PATH}`, {
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					data: [
						`payment_type=03`,
						`payment_status=40`,
						`trading_id=${tradingId}`,
						`payment_amount=1000`,
						`payment_id=test_${orderId}`,
					].join('&'),
				});

				expect(response.status()).toBe(200);
				const body = await response.text();
				expect(body).toContain('result=0');

				// Allow a moment for the order to be saved.
				await new Promise((r) => setTimeout(r, 1000));

				const status = getOrderStatus(orderId);
				expect(['processing', 'completed']).toContain(status);
			} finally {
				deleteOrder(orderId);
			}
		});

		test('CS payment notification (payment_status=12) cancels order', async ({
			request,
			baseURL,
		}) => {
			test.setTimeout(30_000);
			const orderId  = createTestOrder(`wc_cs_cancel_${Date.now()}`);
			const tradingId = `wc_${orderId}`;

			siteWpCli(
				`eval "wc_get_order(${orderId})->update_meta_data('_paygent_order_id',''); wc_get_order(${orderId})->save();"`
			);

			try {
				// payment_status=12 (Expired payment) → cancelled
				const response = await request.post(`${baseURL}${WEBHOOK_PATH}`, {
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					data: [
						`payment_type=03`,
						`payment_status=12`,
						`trading_id=${tradingId}`,
						`payment_id=test_${orderId}`,
					].join('&'),
				});

				expect(response.status()).toBe(200);
				expect(await response.text()).toContain('result=0');

				await new Promise((r) => setTimeout(r, 1000));

				const status = getOrderStatus(orderId);
				expect(status).toBe('cancelled');
			} finally {
				deleteOrder(orderId);
			}
		});

		test('Unknown trading_id still returns result=0 (webhook always ACKs)', async ({
			request,
			baseURL,
		}) => {
			// Paygent requires result=0 even if the order is not found,
			// otherwise it retries the notification.
			const response = await request.post(`${baseURL}${WEBHOOK_PATH}`, {
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				data: 'payment_type=03&payment_status=40&trading_id=wc_999999999',
			});

			expect(response.status()).toBe(200);
			expect(await response.text()).toContain('result=0');
		});
	});
});
