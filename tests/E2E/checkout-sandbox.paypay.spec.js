// @ts-check
const { test, expect } = require('@playwright/test');
const { wpCli, deleteOrders } = require('./helpers/wp-cli');

/**
 * PayPay payment E2E sandbox tests.
 *
 * Prerequisites (same Paygent sandbox credentials as CC tests):
 *   PAYGENT_TEST_MID   — Sandbox merchant ID
 *   PAYGENT_TEST_CID   — Sandbox connect ID
 *   PAYGENT_TEST_CPASS — Sandbox connect password
 *
 * PayPay test account (導入補足資料（PayPay)_直接契約.pdf §8):
 *   PAYPAY_TEST_PHONE    (default: 09081818181)
 *   PAYPAY_TEST_PASSWORD (default: PayPay8181)
 *   PAYPAY_TEST_OTP      (default: 1234)
 *
 * ⚠ Spec notes:
 *   - Use ¥1 for test payments — shared test environment, conflicts possible at same amount
 *   - Always refund on the same day after testing
 *   - Avoid Thursday 20:30 – Friday 0:00 JST (PayPay weekly maintenance)
 *
 * Run:
 *   PAYGENT_TEST_MID=xxx PAYGENT_TEST_CID=yyy PAYGENT_TEST_CPASS=zzz \
 *   npx playwright test checkout-sandbox.paypay
 */

const REQUIRED_ENV = ['PAYGENT_TEST_MID', 'PAYGENT_TEST_CID', 'PAYGENT_TEST_CPASS'];

const PAYPAY = {
	PHONE:    process.env.PAYPAY_TEST_PHONE    || '09081818181',
	PASSWORD: process.env.PAYPAY_TEST_PASSWORD || 'PayPay8181',
	OTP:      process.env.PAYPAY_TEST_OTP      || '1234',
};

/** @type {string} */
let productId = '';
/** @type {string[]} */
const createdOrderIds = [];
/** @type {Record<string, string>} */
let savedPaypaySettings = {};

// ─── helpers ─────────────────────────────────────────────────────────────────

function requireSandboxCredentials() {
	const missing = REQUIRED_ENV.filter((v) => !process.env[v]);
	if (missing.length) {
		test.skip(true, `Sandbox credentials not set: ${missing.join(', ')}`);
	}
}

function getPaypaySettings() {
	const raw = wpCli(`option get woocommerce_paygent_paypay_settings --format=json`);
	try { return JSON.parse(raw) || {}; } catch { return {}; }
}

function updatePaypaySettings(overrides) {
	const current = getPaypaySettings();
	const merged  = { ...current, ...overrides };
	const json    = JSON.stringify(merged).replace(/'/g, "'\\''");
	wpCli(`option update woocommerce_paygent_paypay_settings --format=json '${json}'`);
}

/**
 * Fill WooCommerce billing fields on the classic checkout page.
 *
 * @param {import('@playwright/test').Page} page
 */
async function fillBilling(page) {
	await page.fill('#billing_last_name',  '太郎');
	await page.fill('#billing_first_name', 'テスト');
	await page.fill('#billing_email',      'paypay-e2e@example.com');
	await page.fill('#billing_phone',      '0312345678');

	const country = page.locator('#billing_country');
	if ((await country.count()) > 0) {
		const val = await country.inputValue().catch(() => '');
		if (val !== 'JP') {
			await country.selectOption('JP');
			await page.waitForSelector('.blockUI.blockOverlay', { state: 'detached', timeout: 15_000 }).catch(() => {});
		}
	}
	await page.locator('#billing_state').selectOption('JP13').catch(() => {});
	await page.fill('#billing_postcode',   '1000001');
	await page.fill('#billing_address_1',  '千代田区1-1-1');
	await page.fill('#billing_city',       '東京都');
}

/**
 * Add the ¥1 PayPay test product to cart and navigate to checkout.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} baseURL
 */
async function goToCheckout(page, baseURL) {
	await page.goto(`${baseURL}/?add-to-cart=${productId}`, { waitUntil: 'domcontentloaded' });
	await page.goto(`${baseURL}/checkout/`, { waitUntil: 'domcontentloaded' });
	await page.waitForSelector('#customer_details', { timeout: 30_000 });
}

/**
 * Select PayPay on checkout.
 *
 * WooCommerce often shows a `.blockUI.blockOverlay` spinner after billing
 * fields are updated. We must wait for it to clear before and after clicking
 * the label, otherwise the state change is silently swallowed.
 *
 * @param {import('@playwright/test').Page} page
 */
async function selectPayPay(page) {
	// Wait for any in-progress WooCommerce AJAX overlay (e.g. from fillBilling) to clear.
	await page.waitForSelector('.blockUI.blockOverlay', { state: 'detached', timeout: 20_000 }).catch(() => {});

	const label = page.locator('label[for="payment_method_paygent_paypay"]');
	await expect(label).toBeVisible({ timeout: 10_000 });
	await label.click();

	// Wait for the order-review update overlay triggered by the method change.
	await page.waitForSelector('.blockUI.blockOverlay', { state: 'detached', timeout: 15_000 }).catch(() => {});

	// Confirm selection.
	await expect(page.locator('#payment_method_paygent_paypay')).toBeChecked({ timeout: 10_000 });
}

/**
 * Complete the PayPay login flow on the external test page.
 *
 * Flow (spec §8 ②③④):
 *   ② Enter phone + password → ログイン
 *   ③ Enter OTP              → 認証する
 *   ④ Select payment method  → 支払う
 *
 * @param {import('@playwright/test').Page} page
 */
async function completePayPayLogin(page) {
	// ② Phone + password login screen
	// Paygent's test PayPay mock uses a simple HTML form.
	await page.waitForSelector('input[type="tel"], input[name*="phone"], input[name*="id"]', { timeout: 30_000 });

	const phoneField = page.locator('input[type="tel"]').first()
		.or(page.locator('input[name*="phone"]').first())
		.or(page.locator('input[name*="id"]').first());
	await phoneField.fill(PAYPAY.PHONE);

	await page.locator('input[type="password"]').first().fill(PAYPAY.PASSWORD);

	// Click ログイン button
	await page.locator('button:has-text("ログイン"), input[value="ログイン"], a:has-text("ログイン")').first().click();

	// ③ OTP screen
	await page.waitForSelector('input[type="text"], input[name*="otp"], input[name*="one_time"]', { timeout: 30_000 });

	const otpField = page.locator('input[name*="otp"]').first()
		.or(page.locator('input[name*="one_time"]').first())
		.or(page.locator('input[type="text"]').first());
	await otpField.fill(PAYPAY.OTP);

	// Click 認証する button
	await page.locator('button:has-text("認証"), input[value*="認証"], a:has-text("認証")').first().click();

	// ④ Payment method selection + 支払う
	// The test page may auto-select a payment method; click 支払う when available.
	await page.waitForSelector('button:has-text("支払"), input[value*="支払"], a:has-text("支払")', { timeout: 30_000 });

	// Select the first available payment method if a selection is needed.
	const paymentMethodRadio = page.locator('input[type="radio"][name*="payment"]').first();
	if ((await paymentMethodRadio.count()) > 0) {
		await paymentMethodRadio.check({ force: true }).catch(() => {});
	}

	await page.locator('button:has-text("支払"), input[value*="支払"], a:has-text("支払")').first().click();
}

// ─── setup / teardown ────────────────────────────────────────────────────────

test.beforeAll(async () => {
	// Create a ¥1 product for PayPay tests (spec: use ¥1 to avoid shared-env amount conflicts).
	const existing = wpCli(
		`post list --post_type=product --name=paygent-paypay-e2e --fields=ID --format=csv`
	);
	const match = existing.match(/^(\d+)$/m);
	if (match) {
		productId = match[1];
	} else {
		wpCli(
			`post create --post_type=product --post_title="Paygent PayPay E2E (¥1)" ` +
			`--post_name=paygent-paypay-e2e --post_status=publish ` +
			`--meta_input='{"_price":"1","_regular_price":"1","_virtual":"no","_manage_stock":"no"}' --porcelain`
		);
		const idOut = wpCli(
			`post list --post_type=product --name=paygent-paypay-e2e --fields=ID --format=csv`
		);
		productId = (idOut.match(/^(\d+)$/m) || ['', ''])[1];
	}

	// Ensure PayPay is enabled.
	savedPaypaySettings = getPaypaySettings();
	updatePaypaySettings({ enabled: 'yes' });
});

test.afterAll(async () => {
	// Restore PayPay settings.
	const json = JSON.stringify(savedPaypaySettings).replace(/'/g, "'\\''");
	wpCli(`option update woocommerce_paygent_paypay_settings --format=json '${json}'`);

	// Delete ¥1 test product.
	if (productId) {
		wpCli(`post delete ${productId} --force`);
	}

	// Delete test orders (best-effort; refunds should be done manually per spec).
	deleteOrders(createdOrderIds);
});

// ═════════════════════════════════════════════════════════════════════════════
// Group A: Standard PayPay payment flow
// ¥1 → telegram 420 (申込) → PayPay login → OTP → 支払う → order on-hold
// ═════════════════════════════════════════════════════════════════════════════

test.describe('Sandbox PayPay: Standard payment flow', () => {

	test('PayPay gateway is visible on checkout', async ({ page, baseURL }) => {
		requireSandboxCredentials();

		await goToCheckout(page, baseURL);

		await expect(page.locator('#payment_method_paygent_paypay')).toBeAttached({ timeout: 10_000 });
		await expect(page.locator('label[for="payment_method_paygent_paypay"]')).toBeVisible();
	});

	test('A-1: Guest completes checkout via PayPay (telegram 420)', async ({ page, baseURL }) => {
		test.setTimeout(180_000); // External PayPay redirect flow needs extra time.
		requireSandboxCredentials();

		await goToCheckout(page, baseURL);
		await fillBilling(page);
		await selectPayPay(page);

		// Submit order — WooCommerce AJAX → redirect to order-pay → form auto-submits to PayPay.
		// The order-pay page is transient (auto-submits via window.onload), so we skip trying
		// to capture it and wait directly for the external PayPay test page.
		await page.locator('#place_order').click();

		// waitForURL with a function: resolves as soon as the URL leaves localhost.
		await page.waitForURL((url) => !url.includes('localhost:8888'), { timeout: 90_000 });

		// Screenshot the PayPay test login page for selector debugging.
		await page.screenshot({ path: 'playwright-report/paypay-login.png' });
		console.log('  PayPay test URL:', page.url());

		// Complete the PayPay login flow (phone + password → OTP → 支払う).
		await completePayPayLogin(page);

		// Wait for redirect back to WooCommerce thank-you page.
		await page.waitForURL(/order-received/, { timeout: 60_000 });

		await expect(page.locator('.woocommerce-order-overview')).toBeVisible({ timeout: 15_000 });

		// Order status should be on-hold (PayPay sets on-hold until confirmed).
		const orderText = await page.locator('.woocommerce-order').textContent();
		expect(orderText).toMatch(/on-hold|保留|受付/i);

		// Capture order ID from thank-you URL for cleanup.
		const thankYouUrl = page.url();
		const orderIdMatch = thankYouUrl.match(/order-received\/(\d+)/);
		if (orderIdMatch && !createdOrderIds.includes(orderIdMatch[1])) {
			createdOrderIds.push(orderIdMatch[1]);
		}
	});

});

// ═════════════════════════════════════════════════════════════════════════════
// Group B: PayPay + partial refund
// Requires ¥2 product — spec says use ¥2 for refund tests
// ═════════════════════════════════════════════════════════════════════════════

test.describe('Sandbox PayPay: Partial refund (telegram 421)', () => {

	/** @type {string} */
	let twoYenProductId = '';

	test.beforeAll(async () => {
		// Create ¥2 product for refund test.
		const existing = wpCli(
			`post list --post_type=product --name=paygent-paypay-e2e-refund --fields=ID --format=csv`
		);
		const match = existing.match(/^(\d+)$/m);
		if (match) {
			twoYenProductId = match[1];
		} else {
			wpCli(
				`post create --post_type=product --post_title="Paygent PayPay Refund E2E (¥2)" ` +
				`--post_name=paygent-paypay-e2e-refund --post_status=publish ` +
				`--meta_input='{"_price":"2","_regular_price":"2","_virtual":"no","_manage_stock":"no"}' --porcelain`
			);
			const idOut = wpCli(
				`post list --post_type=product --name=paygent-paypay-e2e-refund --fields=ID --format=csv`
			);
			twoYenProductId = (idOut.match(/^(\d+)$/m) || ['', ''])[1];
		}
	});

	test.afterAll(async () => {
		if (twoYenProductId) {
			wpCli(`post delete ${twoYenProductId} --force`);
		}
	});

	test('B-1: Admin processes ¥1 partial refund on a PayPay order (telegram 421)', async ({ page, baseURL }, testInfo) => {
		requireSandboxCredentials();

		// Step 1: Place ¥2 PayPay order.
		await page.goto(`${baseURL}/?add-to-cart=${twoYenProductId}`, { waitUntil: 'domcontentloaded' });
		await page.goto(`${baseURL}/checkout/`, { waitUntil: 'domcontentloaded' });
		await page.waitForSelector('#customer_details', { timeout: 30_000 });
		await fillBilling(page);
		await selectPayPay(page);

		await Promise.all([
			page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60_000 }),
			page.locator('#place_order').click(),
		]);

		await page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60_000 });
		await completePayPayLogin(page);
		await page.waitForURL(/order-received/, { timeout: 60_000 });

		const thankYouUrl = page.url();
		const orderIdMatch = thankYouUrl.match(/order-received\/(\d+)/);
		const orderId = orderIdMatch ? orderIdMatch[1] : '';
		if (orderId) createdOrderIds.push(orderId);

		testInfo.skip(!orderId, 'Could not obtain order ID from thank-you URL');

		// Step 2: Complete the order (required before PayPay refund).
		wpCli(`wc order update ${orderId} --status=completed --user=1`);

		// Step 3: Navigate to admin order page and issue ¥1 refund.
		await page.goto(`${baseURL}/wp-admin/admin.php?page=wc-orders&action=edit&id=${orderId}`, {
			waitUntil: 'domcontentloaded',
		});

		await page.locator('.refund-items').click();
		await page.waitForSelector('.refund-line-total', { state: 'visible', timeout: 10_000 });
		await page.locator('.refund-line-total').first().fill('1');
		await page.locator('.do-api-refund').click();

		await page.waitForResponse(
			(resp) => resp.url().includes('admin-ajax.php') && resp.status() === 200,
			{ timeout: 30_000 }
		);

		// Verify refund note appears in order.
		await page.reload({ waitUntil: 'domcontentloaded' });
		const notes = await page.locator('.order_notes .note_content').allTextContents();
		const hasRefund = notes.some((n) => /refund|返金|421/i.test(n));
		expect(hasRefund).toBe(true);
	});

});
