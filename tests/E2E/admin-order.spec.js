// @ts-check
const { test, expect } = require('@playwright/test');
const { wpCli } = require('./helpers/wp-cli');

/**
 * Admin order management tests.
 * Creates test orders via WP-CLI and verifies they are visible and correctly
 * displayed in the WooCommerce admin (HPOS).
 */

/** @type {string} Created order IDs to clean up after all tests. */
const createdOrderIds = [];

test.afterAll(async () => {
	if (createdOrderIds.length) {
		wpCli(`post delete ${createdOrderIds.join(' ')} --force`);
	}
});

/**
 * Create a WooCommerce order via WP-CLI with Paygent CC payment.
 *
 * @returns {string} Order ID
 */
function createTestOrder() {
	const result = wpCli(
		`wc shop_order create --status=pending --payment_method=paygent_cc --payment_method_title="クレジットカード (Paygent)" --porcelain --user=1`,
		{ throws: false }
	);
	const match = result.match(/\d+/);
	return match ? match[0] : '';
}

test.describe('Admin: WooCommerce order management', () => {
	test('WooCommerce orders list (HPOS) is accessible', async ({ page, baseURL }) => {
		await page.goto(`${baseURL}/wp-admin/admin.php?page=wc-orders`);
		await expect(page).toHaveURL(/wc-orders/, { timeout: 10_000 });
		// HPOS order table or classic orders table (use first() to avoid strict-mode error
		// when both .wp-list-table and its child #the-list are matched simultaneously).
		const table = page.locator('.wp-list-table, .woocommerce-orders-table, #the-list').first();
		await expect(table).toBeVisible({ timeout: 10_000 });
	});

	test('Admin can view a Paygent CC order detail', async ({ page, baseURL }) => {
		// Create via WC REST-like WP-CLI call; fall back to WP post creation.
		let orderId = createTestOrder();

		if (!orderId) {
			// Fallback: create a raw post and set meta manually.
			orderId = wpCli(
				`post create --post_type=shop_order --post_status=wc-pending --porcelain`
			).trim();
			if (orderId) {
				wpCli(`post meta update ${orderId} _payment_method paygent_cc`);
				wpCli(`post meta update ${orderId} _payment_method_title "クレジットカード (Paygent)"`);
				wpCli(`post meta update ${orderId} _order_total 1000`);
			}
		}

		if (!orderId) {
			test.skip(true, 'Could not create test order via WP-CLI');
			return;
		}
		createdOrderIds.push(orderId);

		// Navigate to HPOS order detail page.
		await page.goto(`${baseURL}/wp-admin/admin.php?page=wc-orders&action=edit&id=${orderId}`);
		await expect(page).toHaveURL(/wc-orders/, { timeout: 10_000 });

		// Order detail page should load without error.
		const heading = page.locator('#order_data, .woocommerce-order-data, h1');
		await expect(heading.first()).toBeVisible({ timeout: 10_000 });
	});

	test('Admin can see Paygent as the payment method on an order', async ({ page, baseURL }) => {
		let orderId = createTestOrder();

		if (!orderId) {
			orderId = wpCli(
				`post create --post_type=shop_order --post_status=wc-pending --porcelain`
			).trim();
			if (orderId) {
				wpCli(`post meta update ${orderId} _payment_method paygent_cc`);
				wpCli(`post meta update ${orderId} _payment_method_title "クレジットカード (Paygent)"`);
			}
		}

		if (!orderId) {
			test.skip(true, 'Could not create test order via WP-CLI');
			return;
		}
		createdOrderIds.push(orderId);

		await page.goto(`${baseURL}/wp-admin/admin.php?page=wc-orders&action=edit&id=${orderId}`);

		// Payment method should appear somewhere on the order detail.
		const pageContent = await page.content();
		const hasPaygent = pageContent.includes('paygent') || pageContent.includes('Paygent');
		expect(hasPaygent).toBeTruthy();
	});

	test('Admin can change order status to cancelled', async ({ page, baseURL }) => {
		let orderId = createTestOrder();

		if (!orderId) {
			orderId = wpCli(
				`post create --post_type=shop_order --post_status=wc-pending --porcelain`
			).trim();
		}

		if (!orderId) {
			test.skip(true, 'Could not create test order via WP-CLI');
			return;
		}
		createdOrderIds.push(orderId);

		await page.goto(`${baseURL}/wp-admin/admin.php?page=wc-orders&action=edit&id=${orderId}`);
		await page.waitForSelector('#order_status, select[name="order_status"]', { timeout: 10_000 });

		const statusSelect = page.locator('#order_status, select[name="order_status"]').first();
		await statusSelect.selectOption('wc-cancelled');

		// Save the order.
		await page.locator('button[name="save"], input[name="save_order"], .save_order').first().click();
		await page.waitForLoadState('networkidle');

		// Verify status changed.
		const updatedSelect = page.locator('#order_status, select[name="order_status"]').first();
		const selectedVal = await updatedSelect.inputValue();
		expect(selectedVal).toBe('wc-cancelled');
	});

	test('WooCommerce payment settings page lists Paygent gateways', async ({ page, baseURL }) => {
		await page.goto(`${baseURL}/wp-admin/admin.php?page=wc-settings&tab=checkout`);
		await expect(page).toHaveURL(/wc-settings/, { timeout: 10_000 });

		// The page should contain at least one reference to Paygent.
		const content = await page.content();
		expect(content.toLowerCase()).toContain('paygent');
	});
});
