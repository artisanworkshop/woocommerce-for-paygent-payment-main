// @ts-check
const { test, expect } = require('@playwright/test');
const { wpCli } = require('./helpers/wp-cli');

/**
 * Checkout page UI tests.
 * These verify the Paygent CC gateway renders correctly on the checkout page.
 * Supports both WooCommerce Block checkout (WC 8.3+) and classic shortcode checkout.
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

test.beforeEach(async () => {
	// Clear cart sessions AND persistent cart user meta before each test.
	// Without this, each add-to-cart call increments the persistent cart stored
	// in usermeta (_woocommerce_persistent_cart_N), which WooCommerce restores
	// for logged-in users even after the session table row is deleted.
	// Note: wpCli() prepends "wp" automatically — do NOT include "wp " in the cmd.
	wpCli(
		`eval "global \\$wpdb; \\$wpdb->query('DELETE FROM ' . \\$wpdb->prefix . 'woocommerce_sessions');"`,
		{ throws: false }
	);
	wpCli(
		`eval "global \\$wpdb; \\$wpdb->delete(\\$wpdb->usermeta, array('meta_key'=>'_woocommerce_persistent_cart_1'));"`,
		{ throws: false }
	);
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
 * Detect whether the page uses Block or classic shortcode checkout.
 * Returns 'block' | 'classic'.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<'block'|'classic'>}
 */
async function detectCheckoutType(page) {
	const isBlock = await page.locator(
		'.wc-block-checkout__form, .wp-block-woocommerce-checkout, .wc-block-checkout'
	).first().isVisible({ timeout: 2_000 }).catch(() => false);
	return isBlock ? 'block' : 'classic';
}

/**
 * Add the test product to the cart and navigate to checkout.
 * Works with both Block checkout (WC 8.3+) and classic shortcode checkout.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string|undefined}                baseURL
 */
async function goToCheckout(page, baseURL) {
	if (!baseURL) throw new Error('baseURL fixture is not configured in playwright.config.js');
	await mockPaygentTokenJs(page);

	if (productId) {
		// Add the test product (¥1,000) to the cart, ensuring total > 0 so
		// WooCommerce shows payment methods (needs_payment() requires total > 0).
		await page.goto(`${baseURL}/?add-to-cart=${productId}&quantity=1`, { waitUntil: 'domcontentloaded' });
	} else {
		await page.goto(`${baseURL}/shop/`, { waitUntil: 'domcontentloaded' });
		await page.locator('.add_to_cart_button').first().click();
		await page.waitForTimeout(500);
	}
	await page.goto(`${baseURL}/checkout/`, { waitUntil: 'domcontentloaded' });

	// Wait for either Block checkout or classic shortcode checkout to render.
	await page.waitForSelector(
		'#customer_details, .wc-block-checkout__form, .wc-block-checkout, .wp-block-woocommerce-checkout',
		{ timeout: 15_000 }
	);
}

test.describe('Checkout: Paygent CC payment method', () => {
	test('Paygent CC payment method is visible on checkout', async ({ page, baseURL }) => {
		await goToCheckout(page, baseURL);
		const checkoutType = await detectCheckoutType(page);

		if (checkoutType === 'block') {
			// Block checkout: payment method radio rendered by WooCommerce Blocks.
			// The radio ID pattern is "radio-control-wc-payment-method-options-paygent_cc".
			const radio = page.locator(
				'input[id*="paygent_cc"], input[value="paygent_cc"]'
			).first();
			await expect(radio).toBeAttached({ timeout: 10_000 });
			// The payment method label should be visible.
			const label = page.locator(
				'label[for*="paygent_cc"], .wc-block-components-payment-method-label'
			).first();
			await expect(label).toBeVisible({ timeout: 10_000 });
		} else {
			// Classic shortcode checkout.
			await expect(page.locator('#payment_method_paygent_cc')).toBeAttached({ timeout: 10_000 });
			await expect(page.locator('label[for="payment_method_paygent_cc"]')).toBeVisible({ timeout: 10_000 });
		}
	});

	test('Paygent CC form renders card input fields', async ({ page, baseURL }) => {
		await goToCheckout(page, baseURL);
		const checkoutType = await detectCheckoutType(page);

		if (checkoutType === 'block') {
			// Block checkout: our Block component renders these IDs.
			await expect(page.locator('#paygent-cc-number')).toBeVisible({ timeout: 10_000 });
			await expect(page.locator('#paygent-cc-expiry')).toBeVisible();
			await expect(page.locator('#paygent-cc-cvc')).toBeVisible();
		} else {
			// Classic shortcode checkout: WC_Payment_Gateway_CC::form() fields.
			await expect(page.locator('#paygent_cc-card-number')).toBeVisible({ timeout: 10_000 });
			await expect(page.locator('#paygent_cc-card-expiry')).toBeVisible();
			await expect(page.locator('#paygent_cc-card-cvc')).toBeVisible();
		}
	});

	test('Place order button is present and clickable', async ({ page, baseURL }) => {
		await goToCheckout(page, baseURL);
		const checkoutType = await detectCheckoutType(page);

		if (checkoutType === 'block') {
			// Block checkout place-order button.
			const btn = page.locator(
				'.wc-block-components-checkout-place-order-button, button.wc-block-components-button--type-checkout'
			).first();
			await expect(btn).toBeVisible({ timeout: 10_000 });
			const btnText = await btn.textContent();
			expect(btnText?.trim().length).toBeGreaterThan(0);
		} else {
			const btn = page.locator('#place_order');
			await expect(btn).toBeVisible({ timeout: 10_000 });
			const btnText = await btn.textContent();
			expect(btnText?.trim().length).toBeGreaterThan(0);
		}
	});

	test('Billing information is present on the checkout page', async ({ page, baseURL }) => {
		await goToCheckout(page, baseURL);
		const checkoutType = await detectCheckoutType(page);

		if (checkoutType === 'block') {
			// Block checkout shows billing address as a summary or an editable form.
			// Verify the billing/contact section is rendered.
			const billingSection = page.locator(
				'.wc-block-checkout__billing-fields, .wc-block-components-address-form, .wc-block-checkout__contact-fields, [data-block-name="woocommerce/checkout-billing-address-block"]'
			).first();
			await expect(billingSection).toBeAttached({ timeout: 10_000 });
		} else {
			// Classic shortcode: individual billing field inputs.
			await page.fill('#billing_last_name',  '太郎');
			await page.fill('#billing_first_name', 'テスト');
			await page.fill('#billing_email',      'e2e-test@example.com');
			await page.fill('#billing_phone',      '0312345678');
			await page.fill('#billing_address_1',  '千代田区1-1-1');
			await page.fill('#billing_city',       '東京都');
			await page.fill('#billing_postcode',   '1000001');
			await expect(page.locator('#billing_last_name')).toHaveValue('太郎');
			await expect(page.locator('#billing_email')).toHaveValue('e2e-test@example.com');
		}
	});

	test('Submitting without card fields does not proceed to order-received', async ({ page, baseURL }) => {
		await goToCheckout(page, baseURL);
		const checkoutType = await detectCheckoutType(page);

		if (checkoutType === 'block') {
			// Click place order without filling in card fields.
			const btn = page.locator(
				'.wc-block-components-checkout-place-order-button, button.wc-block-components-button--type-checkout'
			).first();
			await expect(btn).toBeVisible({ timeout: 10_000 });
			await btn.click();
			await page.waitForTimeout(2_000);
			// Should NOT navigate to order-received.
			expect(page.url()).not.toMatch(/order-received/);
		} else {
			await page.locator('#payment_method_paygent_cc').check({ force: true });
			await page.waitForSelector('.blockUI.blockOverlay', { state: 'detached', timeout: 15_000 })
				.catch(() => {});
			await page.click('#place_order');
			await page.waitForTimeout(2_000);
			expect(page.url()).not.toMatch(/order-received/);
		}
	});

	test('Paygent CC Block payment form is present in DOM', async ({ page, baseURL }) => {
		await goToCheckout(page, baseURL);
		const checkoutType = await detectCheckoutType(page);

		if (checkoutType === 'block') {
			// Our Block renders a container div and card number field.
			await expect(page.locator('.wc-paygent-cc-form')).toBeAttached({ timeout: 10_000 });
			await expect(page.locator('#paygent-cc-number')).toBeAttached();
		} else {
			// Classic shortcode: hidden token fields rendered server-side.
			await expect(page.locator('#paygent_cc-token')).toBeAttached({ timeout: 10_000 });
			await expect(page.locator('#paygent_cc-valid_until')).toBeAttached();
		}
	});
});
