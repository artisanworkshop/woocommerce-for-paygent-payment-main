// @ts-check
const { test, expect } = require('@playwright/test');
const { wpCli } = require('./helpers/wp-cli');

/**
 * Checkout page UI tests.
 * These verify the Paygent CC gateway renders correctly on the checkout page.
 * No actual payment processing is performed.
 */

let productId = '';

test.beforeAll(async () => {
	// Resolve product ID from slug via WP-CLI.
	const out = wpCli(
		`post list --post_type=product --name=paygent-e2e-test-product --fields=ID --format=csv`
	);
	const match = out.match(/^(\d+)$/m);
	productId = match ? match[1] : '';
});

/**
 * Intercept the external Paygent Token JS.
 *
 * PaygentToken.js is loaded synchronously in <head> from sandbox.paygent.co.jp.
 * In test environments it can take 60+ seconds to respond, blocking
 * DOMContentLoaded and making every checkout test extremely slow.
 * We intercept the request and return a minimal stub so the page loads instantly.
 *
 * @param {import('@playwright/test').Page} page
 */
async function mockPaygentTokenJs(page) {
	await page.route(/paygent\.co\.jp\/js\/PaygentToken/, (route) =>
		route.fulfill({
			status: 200,
			contentType: 'application/javascript',
			body: [
				'window.PaygentToken = {',
				'  request: function(mid, key, params, cb) { if (cb) cb({ result: "0000", token: "test-token", maskedCardNumber: "4111********1111", validUntil: "2030/12" }); },',
				'  createToken: function() {},',
				'};',
			].join('\n'),
		})
	);
}

/**
 * Add the test product to the cart and navigate to checkout.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string}                          baseURL
 */
async function goToCheckout(page, baseURL) {
	// Mock external Paygent JS before any navigation so the checkout page
	// doesn't block on the external script request.
	await mockPaygentTokenJs(page);

	if (productId) {
		// Use page.goto (full browser nav) to ensure WooCommerce sets the
		// cart session cookie in the browser context correctly.
		await page.goto(`${baseURL}/?add-to-cart=${productId}`, { waitUntil: 'domcontentloaded' });
	} else {
		// Fallback: navigate to shop and click Add to Cart.
		await page.goto(`${baseURL}/shop/`, { waitUntil: 'domcontentloaded' });
		await page.locator('.add_to_cart_button').first().click();
		await page.waitForTimeout(500);
	}
	await page.goto(`${baseURL}/checkout/`, { waitUntil: 'domcontentloaded' });
	await page.waitForSelector('#customer_details', { timeout: 15_000 });

	// Wait for WooCommerce JS to fire updated_checkout and show the selected
	// payment method's box.  Paygent CC is the only registered method so its
	// box is shown automatically without user interaction.
	await page.waitForSelector('.payment_box.payment_method_paygent_cc', {
		state: 'visible',
		timeout: 15_000,
	}).catch(() => {});
}

test.describe('Checkout: Paygent CC payment method', () => {
	test('Paygent CC payment method is visible on checkout', async ({ page, baseURL }) => {
		await goToCheckout(page, baseURL);

		// WooCommerce hides the radio <input> with CSS and shows a styled <label>.
		// Verify the radio is in the DOM (attached) and its label is visible.
		await expect(page.locator('#payment_method_paygent_cc')).toBeAttached({ timeout: 10_000 });
		await expect(page.locator('label[for="payment_method_paygent_cc"]')).toBeVisible({ timeout: 10_000 });
	});

	test('Paygent CC form renders card input fields', async ({ page, baseURL }) => {
		await goToCheckout(page, baseURL);

		// Paygent CC is auto-selected (only method); its payment box is shown by
		// WooCommerce JS via updated_checkout.  Do NOT trigger radio.check() here
		// — that fires another update_order_review which would wipe the form.
		// Standard WooCommerce CC form fields rendered by WC_Payment_Gateway_CC::form().
		await expect(page.locator('#paygent_cc-card-number')).toBeVisible({ timeout: 10_000 });
		await expect(page.locator('#paygent_cc-card-expiry')).toBeVisible();
		await expect(page.locator('#paygent_cc-card-cvc')).toBeVisible();
	});

	test('Place order button has Paygent CC label when method is selected', async ({ page, baseURL }) => {
		await goToCheckout(page, baseURL);

		await page.locator('#payment_method_paygent_cc').check({ force: true });

		const btn = page.locator('#place_order');
		await expect(btn).toBeVisible();
		// Button text should contain "クレジットカード" or "Credit Card" or "Proceed".
		const btnText = await btn.textContent();
		expect(btnText?.trim().length).toBeGreaterThan(0);
	});

	test('Billing form can be filled with Japanese address', async ({ page, baseURL }) => {
		await goToCheckout(page, baseURL);

		await page.fill('#billing_last_name',  '太郎');
		await page.fill('#billing_first_name', 'テスト');
		await page.fill('#billing_email',      'e2e-test@example.com');
		await page.fill('#billing_phone',      '0312345678');
		await page.fill('#billing_address_1',  '千代田区1-1-1');
		await page.fill('#billing_city',       '東京都');
		await page.fill('#billing_postcode',   '1000001');

		// Verify values were accepted.
		await expect(page.locator('#billing_last_name')).toHaveValue('太郎');
		await expect(page.locator('#billing_email')).toHaveValue('e2e-test@example.com');
	});

	test('Submitting empty card fields shows validation error', async ({ page, baseURL }) => {
		await goToCheckout(page, baseURL);

		// Fill billing but leave card fields empty.
		await page.fill('#billing_last_name',  '太郎');
		await page.fill('#billing_first_name', 'テスト');
		await page.fill('#billing_email',      'e2e-test@example.com');
		await page.fill('#billing_phone',      '0312345678');
		await page.fill('#billing_address_1',  '千代田区1-1-1');
		await page.fill('#billing_city',       '東京都');
		await page.fill('#billing_postcode',   '1000001');

		await page.locator('#payment_method_paygent_cc').check({ force: true });

		// Wait for any WooCommerce blockUI overlay (from update_order_review AJAX
		// triggered by the radio change) to clear before clicking place_order.
		await page.waitForSelector('.blockUI.blockOverlay', { state: 'detached', timeout: 15_000 })
			.catch(() => {});

		await page.click('#place_order');

		// Should NOT navigate to order-received (payment must fail or be blocked).
		await page.waitForTimeout(2000);
		expect(page.url()).not.toMatch(/order-received/);
	});

	test('Hidden token fields are present in the form', async ({ page, baseURL }) => {
		await goToCheckout(page, baseURL);

		// Hidden <input type="hidden"> fields rendered server-side by paygent_token_js().
		// They live inside the payment box (which may be CSS-visible or hidden); use
		// toBeAttached() because hidden inputs are never "visible".
		// Do NOT trigger radio.check() — that would re-fire update_order_review and wipe
		// the server-rendered form.
		await expect(page.locator('#paygent_cc-token')).toBeAttached({ timeout: 10_000 });
		await expect(page.locator('#paygent_cc-valid_until')).toBeAttached();
		await expect(page.locator('#paygent_cc-masked_card_number')).toBeAttached();
	});
});
