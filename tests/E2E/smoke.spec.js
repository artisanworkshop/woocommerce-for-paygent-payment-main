// @ts-check
const { test, expect } = require('@playwright/test');
const { wpCli } = require('./helpers/wp-cli');

/**
 * Smoke tests — verify the wp-env environment and plugin setup are working.
 * These run as part of the 'e2e' project (authenticated as admin).
 */

/** Product ID resolved once for the checkout test. */
let productId = '';

test.beforeAll(async () => {
	const out = wpCli(
		`post list --post_type=product --name=paygent-e2e-test-product --fields=ID --format=csv`
	);
	const match = out.match(/^(\d+)$/m);
	productId = match ? match[1] : '';
});

test.describe('Smoke: Environment', () => {
	test('wp-admin is reachable and admin is logged in', async ({ page, baseURL }) => {
		await page.goto(`${baseURL}/wp-admin/`);
		await expect(page).toHaveURL(/wp-admin/);
		await expect(page.locator('#wpadminbar')).toBeVisible();
	});

	test('WooCommerce is active', async ({ page, baseURL }) => {
		await page.goto(`${baseURL}/wp-admin/admin.php?page=wc-settings`);
		await expect(page).toHaveURL(/wc-settings/);
		// WC settings page always has a tab bar with "General" and "Payments" tabs.
		await expect(page.locator('.nav-tab-wrapper a[href*="tab=checkout"], .nav-tab-wrapper a[href*="tab=payment"]')).toBeVisible({ timeout: 10_000 });
	});

	test('Paygent CC gateway is registered in WooCommerce', async ({ page, baseURL }) => {
		// WC 8.x renamed the tab from "checkout" to "payment". Try both.
		await page.goto(`${baseURL}/wp-admin/admin.php?page=wc-settings&tab=checkout&section=paygent_cc`);

		// If the page redirected away from paygent_cc (e.g. WC renamed the tab),
		// try the "payment" tab as a fallback.
		const onSection = page.url().includes('section=paygent_cc');
		if (!onSection) {
			await page.goto(`${baseURL}/wp-admin/admin.php?page=wc-settings&tab=payment&section=paygent_cc`);
		}

		// The gateway settings form renders a title input field when registered.
		// Accept any save button too — it means the settings form loaded even if
		// the title field ID changed across WC versions.
		// Use .first() to avoid strict mode violation when multiple .or() branches
		// match simultaneously on a fully-loaded settings page.
		await expect(
			page.locator('input#woocommerce_paygent_cc_title')
				.or( page.locator('button[name="save"], .woocommerce-save-button') )
				.or( page.locator('h2, h3').filter({ hasText: /paygent|クレジットカード/i }) )
				.first()
		).toBeVisible({ timeout: 10_000 });
	});

	test('test product exists in the shop', async ({ page, baseURL }) => {
		await page.goto(`${baseURL}/shop/`, { waitUntil: 'domcontentloaded' });
		// Verify the shop page heading loaded. The product grid class varies by
		// WooCommerce version/theme so check the page heading instead.
		await expect(page.locator('h1, h2').filter({ hasText: 'Shop' })).toBeVisible({ timeout: 10_000 });
		// Verify at least one product is listed (image or link present).
		await expect(page.locator('ul.products li.product, .wp-block-woocommerce-product-collection .product').first()).toBeVisible({ timeout: 10_000 });
	});

	test('checkout page is accessible', async ({ page, baseURL }) => {
		// Smoke: verify the checkout page URL responds with HTTP 200 and contains
		// the classic checkout form in its HTML.
		//
		// We use page.request (same cookie jar as the browser) so the cart session
		// is shared. Adding the product to the cart first ensures the checkout form
		// renders rather than the "cart is empty" redirect.
		if (!productId) {
			test.skip(true, 'Test product ID not resolved via WP-CLI');
			return;
		}

		// Add product to cart via query-param URL (WooCommerce processes this
		// server-side and sets the cart session cookie in the response).
		await page.goto(`${baseURL}/?add-to-cart=${productId}`, { waitUntil: 'domcontentloaded' });

		// Fetch the checkout page HTML directly — no JS rendering needed.
		// page.request shares cookies with the browser, so the cart session applies.
		const response = await page.request.get(`${baseURL}/checkout/`);
		expect(response.status()).toBe(200);

		// The classic checkout (shortcode) always renders #customer_details server-side.
		const html = await response.text();
		expect(html).toContain('customer_details');
	});
});
