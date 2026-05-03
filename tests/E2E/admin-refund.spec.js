// @ts-check
const { test, expect } = require('@playwright/test');
const { wpCli } = require('./helpers/wp-cli');

/**
 * Admin refund UI tests.
 *
 * Verifies that the WooCommerce admin order detail page correctly renders the
 * Paygent refund UI for processing orders.  No real API calls are made; the
 * "Refund manually" path is used so tests remain stable without sandbox
 * credentials.
 *
 * Run:
 *   npx playwright test tests/E2E/admin-refund.spec.js --project=e2e
 */

/** @type {string[]} Order IDs created during this run — cleaned up in afterAll. */
const createdOrderIds = [];

test.afterAll(() => {
	for (const id of createdOrderIds) {
		wpCli(`wc shop_order delete ${id} --force=true --user=1`);
	}
});

/**
 * Create a processing-status CC order with a line item via WP-CLI.
 *
 * The refund button only appears when the order contains at least one item,
 * so we add the shared E2E test product to the order.
 *
 * @returns {string} Order ID, or "" on failure.
 */
function createProcessingOrder() {
	// Resolve the E2E test product ID.
	const productRaw = wpCli(
		'post list --post_type=product --name=paygent-e2e-test-product --fields=ID --format=csv',
		{ throws: false }
	);
	const productMatch = productRaw.match(/(\d+)/);
	const productId = productMatch ? productMatch[1] : '';

	if (!productId) return '';

	// Create a processing order with the product as a line item.
	// PHP variables must be escaped as \$ so the shell does not expand them.
	const raw = wpCli(
		`eval "\\$order = wc_create_order(['status'=>'processing','payment_method'=>'paygent_cc']); ` +
		`\\$p = wc_get_product(${productId}); ` +
		`if (\\$p) { \\$order->add_product(\\$p, 1); } ` +
		`\\$order->calculate_totals(); ` +
		`\\$order->save(); ` +
		`echo \\$order->get_id();"`,
		{ throws: false }
	);
	const match = raw.match(/(\d+)\s*$/);
	return match ? match[1] : '';
}

// ─────────────────────────────────────────────────────────────────────────────

test.describe('Admin: Refund UI (Paygent CC)', () => {

	test('Processing order shows the Refund button', async ({ page, baseURL }) => {
		const orderId = createProcessingOrder();
		if (!orderId) { test.skip(true, 'WP-CLI order creation failed'); return; }
		createdOrderIds.push(orderId);

		await page.goto(`${baseURL}/wp-admin/admin.php?page=wc-orders&action=edit&id=${orderId}`);

		// WooCommerce renders a "Refund" button when the order has at least one line item.
		const refundBtn = page.locator('button.refund-items');
		await expect(refundBtn).toBeVisible({ timeout: 10_000 });
	});

	test('Clicking Refund button reveals the refund amount input', async ({ page, baseURL }) => {
		const orderId = createProcessingOrder();
		if (!orderId) { test.skip(true, 'WP-CLI order creation failed'); return; }
		createdOrderIds.push(orderId);

		await page.goto(`${baseURL}/wp-admin/admin.php?page=wc-orders&action=edit&id=${orderId}`);

		await page.locator('button.refund-items').click();

		// After clicking, the refund section expands and a total-refund amount input appears.
		await expect(page.locator('#refund_amount')).toBeVisible({ timeout: 5_000 });
	});

	test('Refund section shows manual-refund button', async ({ page, baseURL }) => {
		const orderId = createProcessingOrder();
		if (!orderId) { test.skip(true, 'WP-CLI order creation failed'); return; }
		createdOrderIds.push(orderId);

		await page.goto(`${baseURL}/wp-admin/admin.php?page=wc-orders&action=edit&id=${orderId}`);
		await page.locator('button.refund-items').click();

		// "Refund ¥X manually" button — no API call, always present regardless of
		// whether the gateway supports programmatic refunds.
		await expect(page.locator('button.do-manual-refund')).toBeVisible({ timeout: 5_000 });
	});

	test('Manual refund completes without PHP fatal error', async ({ page, baseURL }) => {
		test.setTimeout(30_000);

		const orderId = createProcessingOrder();
		if (!orderId) { test.skip(true, 'WP-CLI order creation failed'); return; }
		createdOrderIds.push(orderId);

		await page.goto(`${baseURL}/wp-admin/admin.php?page=wc-orders&action=edit&id=${orderId}`);

		// Expand refund UI.
		await page.locator('button.refund-items').click();
		await expect(page.locator('#refund_amount')).toBeVisible({ timeout: 5_000 });

		// Click "Refund manually" — this path does not call the Paygent API.
		page.on('dialog', d => d.accept());
		await page.locator('button.do-manual-refund').click();

		// If a WooCommerce backbone modal confirmation appears, accept it.
		const confirmBtn = page.locator('.wc-backbone-modal-main .wc-backbone-modal-action').first();
		if (await confirmBtn.isVisible({ timeout: 2_000 }).catch(() => false)) {
			await confirmBtn.click();
		}

		await page.waitForTimeout(2_000);

		// The page must not show a PHP fatal error or white screen.
		const body = await page.content();
		expect(body).not.toContain('Fatal error');
		expect(body).not.toContain('wp-die');
	});

	test('Cancelling pending order changes status to cancelled', async ({ page, baseURL }) => {
		// Create a *pending* order (CS — cancel before payment = status change, no refund API).
		const raw = wpCli(
			'wc shop_order create --status=pending --payment_method=paygent_cs --porcelain --user=1',
			{ throws: false }
		);
		const match = raw.match(/(\d+)\s*$/);
		const orderId = match ? match[1] : '';
		if (!orderId) { test.skip(true, 'WP-CLI order creation failed'); return; }
		createdOrderIds.push(orderId);

		await page.goto(`${baseURL}/wp-admin/admin.php?page=wc-orders&action=edit&id=${orderId}`);

		// Change status via the status select.
		const statusSelect = page.locator('#order_status, select[name="order_status"]').first();
		await expect(statusSelect).toBeVisible({ timeout: 10_000 });
		await statusSelect.selectOption('wc-cancelled');

		await page.locator('button[name="save"], input[name="save_order"], .save_order').first().click();
		await page.waitForLoadState('networkidle');

		const updated = page.locator('#order_status, select[name="order_status"]').first();
		expect(await updated.inputValue()).toBe('wc-cancelled');
	});
});
