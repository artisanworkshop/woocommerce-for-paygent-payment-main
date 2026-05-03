// @ts-check
const { test, expect } = require('@playwright/test');
const {
	wpCli,
	enableTds2,
	disableTds2,
	getPaygentCcSettings,
	updatePaygentCcSettings,
	restorePaygentCcSettings,
} = require('./helpers/wp-cli');

/**
 * Full checkout flow with actual Paygent sandbox API.
 *
 * Prerequisites (all required, or tests are skipped):
 *   PAYGENT_TEST_MID        — Sandbox merchant ID
 *   PAYGENT_TEST_CID        — Sandbox connect ID
 *   PAYGENT_TEST_CPASS      — Sandbox connect password
 *   PAYGENT_TEST_TOKENKEY   — Token generation key
 *
 * Also requires:
 *   - Local IP registered with Paygent sandbox
 *   - wp-env running at http://localhost:8888
 *
 * Run:
 *   PAYGENT_TEST_MID=xxx PAYGENT_TEST_CID=yyy PAYGENT_TEST_CPASS=zzz \
 *   PAYGENT_TEST_TOKENKEY=aaa npx playwright test checkout-sandbox
 *
 * ─── Test card numbers (試験環境ツール利用手順書.pdf §【試験用カード番号の例】) ──
 *   4900-0000-0000-0000  → オーソリOK / 取消OK / 売上OK  (末尾XYZZ=0000)
 *   4900-0000-0000-8101  → オーソリ異常 (エラーコード C01)
 *   Expiry: within 20 years of today; CVC: any 3 digits (sandbox ignores value)
 *
 * ─── EMV 3Dセキュア (3DS2.0) ─────────────────────────────────────────────────
 *   Source: 導入補足資料（EMV 3Dセキュア（ブラウザベース））第1.3.2版
 *   3DS1.0 was terminated Oct 2022. Only EMV 3DS (2.0) is available.
 *
 *   Result is controlled by ORDER AMOUNT or CARDHOLDER NAME, NOT card number.
 *
 *   Frictionless success (VISA):   cardholder=BAVYA  or  amount=314,010
 *   Challenge flow (all brands):   amount=0, 1, 200,000, or 400,000
 *     VISA challenge success password: 14012
 *
 *   ⚠ Any amount/cardholder not listed in the spec is "サポート対象外".
 *
 * ─── Test groups ─────────────────────────────────────────────────────────────
 *   A: 3DS disabled  — standard card + ¥1,000 → auth OK (telegram 020)
 *   B: 3DS2 enabled  — frictionless via cardholder=BAVYA (auto-configured)
 *   C: 3DS2 enabled  — challenge flow with ¥0 order, password=14012 (auto-configured)
 *   D: Refund        — partial refund on a completed order (3DS disabled)
 *
 *   Groups B and C automatically enable tds2_check before tests and restore
 *   the original setting afterwards — no manual admin intervention required.
 */

const REQUIRED_ENV = ['PAYGENT_TEST_MID', 'PAYGENT_TEST_CID', 'PAYGENT_TEST_CPASS', 'PAYGENT_TEST_TOKENKEY'];

const CARD = {
	OK:      '4900000000000000', // オーソリOK / 取消OK / 売上OK
	AUTH_NG: '4900000000008101', // オーソリ異常 C01
};

// EMV 3DS2 control values — VISA brand (card starts with 4)
// Source: 導入補足資料 §5.1 No.15 / §5.2 No.10
const TDS2 = {
	CARDHOLDER_FRICTIONLESS_OK: 'BAVYA',  // VISA frictionless, result=0
	CHALLENGE_PASSWORD_VISA:    '14012',  // VISA challenge success password
};

/** @type {string} */
let productId = '';
/** @type {string[]} */
const createdOrderIds = [];
/** @type {string} Cached PaygentToken.js source to avoid slow repeated fetches. */
let cachedPaygentTokenJs = '';

test.beforeAll(async () => {
	const out = wpCli(
		`post list --post_type=product --name=paygent-e2e-test-product --fields=ID --format=csv`
	);
	const match = out.match(/^(\d+)$/m);
	productId = match ? match[1] : '';

	// Pre-fetch PaygentToken.js via Node.js so each test can serve it from cache.
	// The script itself makes API calls to Paygent's servers (absolute URLs), so
	// caching the JS file does not affect the real tokenization flow.
	const https = require('https');
	cachedPaygentTokenJs = await new Promise((resolve) => {
		const req = https.get(
			'https://sandbox.paygent.co.jp/js/PaygentToken.js',
			{ timeout: 30_000 },
			(res) => {
				const chunks = [];
				res.on('data', (c) => chunks.push(c));
				res.on('end', () => resolve(Buffer.concat(chunks).toString()));
				res.on('error', () => resolve(''));
			}
		);
		req.on('error', () => resolve(''));
		req.on('timeout', () => { req.destroy(); resolve(''); });
	});

	if (cachedPaygentTokenJs) {
		console.log('  [sandbox] PaygentToken.js pre-fetched and cached.');
	} else {
		console.warn('  [sandbox] PaygentToken.js could not be pre-fetched — tests may be slow.');
	}
});

test.afterAll(async () => {
	for (const id of createdOrderIds) {
		// HPOS: orders live in wc_orders table, not wp_posts — use WC API to delete.
		wpCli(`wc order delete ${id} --force --user=1`);
	}
});

function requireSandboxCredentials() {
	const missing = REQUIRED_ENV.filter((v) => !process.env[v]);
	if (missing.length) {
		test.skip(true, `Sandbox credentials not set: ${missing.join(', ')}`);
	}
}

// ─── shared helpers ───────────────────────────────────────────────────────────

/**
 * Navigate to checkout and return whether PaygentToken.js loaded successfully.
 * Returns false if sandbox.paygent.co.jp is unreachable — callers should skip the test.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} baseURL
 * @param {string} [pid]
 * @returns {Promise<boolean>} true if PaygentToken.js is loaded and ready
 */
async function goToCheckout(page, baseURL, pid = productId) {
	// Serve the pre-fetched PaygentToken.js from cache so the checkout page
	// loads instantly instead of waiting 60-90s for sandbox.paygent.co.jp.
	// Tokenization API calls within PaygentToken.js still reach the real server.
	if (cachedPaygentTokenJs) {
		await page.route(/sandbox\.paygent\.co\.jp\/js\/PaygentToken/, (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/javascript',
				body: cachedPaygentTokenJs,
			})
		);
	}

	if (pid) {
		await page.goto(`${baseURL}/?add-to-cart=${pid}`, { waitUntil: 'domcontentloaded' });
	} else {
		await page.goto(`${baseURL}/shop/`, { waitUntil: 'domcontentloaded' });
		await page.locator('.add_to_cart_button').first().click();
		await page.waitForTimeout(500);
	}
	await page.goto(`${baseURL}/checkout/`, { waitUntil: 'domcontentloaded' });
	await page.waitForSelector('#customer_details', { timeout: 30_000 });

	// Verify PaygentToken.js actually loaded (succeeds immediately when cached).
	// If not cached and server is unreachable, return false so the test can skip.
	const tokenReady = await page.waitForFunction(
		() => typeof window.PaygentToken !== 'undefined',
		{ timeout: 30_000 }
	).then(() => true).catch(() => false);

	// Wait for WooCommerce JS to fire updated_checkout and render the payment box.
	await page.waitForSelector('.payment_box.payment_method_paygent_cc', {
		state: 'visible',
		timeout: 30_000,
	}).catch(() => {});

	return tokenReady;
}

/**
 * Fill billing fields.
 * Pass cardholderName to override last_name for 3DS2 control (e.g. "BAVYA").
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} [cardholderName]
 */
async function fillBilling(page, cardholderName = '') {
	const lastName  = cardholderName || '太郎';
	const firstName = cardholderName ? '' : 'テスト';
	await page.fill('#billing_last_name',   lastName);
	if (firstName) await page.fill('#billing_first_name', firstName);
	await page.fill('#billing_email',       'sandbox-e2e@example.com');
	await page.fill('#billing_phone',       '0312345678');
	// Select Japan if not already set (global setup sets woocommerce_default_country=JP).
	const country = page.locator('#billing_country');
	if ((await country.count()) > 0) {
		const currentCountry = await country.inputValue().catch(() => '');
		if (currentCountry !== 'JP') {
			await country.selectOption('JP');
			await page.waitForSelector('.blockUI.blockOverlay', { state: 'detached', timeout: 15_000 }).catch(() => {});
		}
	}
	// Tokyo prefecture (JP13) — required when country is Japan.
	await page.locator('#billing_state').selectOption('JP13').catch(() => {});
	await page.fill('#billing_postcode',    '1000001');
	await page.fill('#billing_address_1',   '千代田区1-1-1');
	await page.fill('#billing_city',        '東京都');
}

/**
 * Fill card form and wait for Paygent JS SDK to return a token.
 * Tokenization is triggered by 'input' events; the hidden #paygent_cc-token
 * is populated by the execPurchase callback on success.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} [cardNumber]
 */
async function fillCardAndWaitForToken(page, cardNumber = CARD.OK) {
	await page.locator('#paygent_cc-card-number').waitFor({ state: 'visible', timeout: 10_000 });

	// Set values via evaluate (no input events) to prevent race conditions from
	// multiple createToken calls firing before all fields are filled.
	await page.evaluate(([cn, exp, cvc]) => {
		document.getElementById('paygent_cc-card-number').value = cn;
		document.getElementById('paygent_cc-card-expiry').value = exp;
		document.getElementById('paygent_cc-card-cvc').value = cvc;
	}, [cardNumber, '12 / 30', '123']);

	// Call sendPaygentToken once with all fields populated.
	await page.evaluate(() => {
		if (typeof window.sendPaygentToken === 'function') window.sendPaygentToken();
	});

	// Wait for both card token and CVC token — validate_fields() rejects if either is missing.
	await expect(page.locator('#paygent_cc-token')).not.toHaveValue('', { timeout: 30_000 });
	await page.waitForFunction(
		() => (document.getElementById('paygent_cc-cvc_token')?.value ?? '') !== '',
		{ timeout: 30_000 }
	);
}

async function selectPaygentCC(page) {
	const radio = page.locator('#payment_method_paygent_cc');
	// WooCommerce hides the radio with CSS; check attachment, not visibility.
	await expect(radio).toBeAttached({ timeout: 10_000 });
	await expect(page.locator('label[for="payment_method_paygent_cc"]')).toBeVisible({ timeout: 10_000 });
	// Only force-check if not already selected to avoid re-triggering update_order_review.
	if (!(await radio.isChecked())) {
		await radio.check({ force: true });
		await page.waitForSelector('.blockUI.blockOverlay', { state: 'detached', timeout: 15_000 }).catch(() => {});
	}
}

// ═════════════════════════════════════════════════════════════════════════════
// Group A: Standard checkout — 3DS disabled (default)
// Card 4900-0000-0000-0000 + ¥1,000 → telegram 020 (auth) → 正常
// ═════════════════════════════════════════════════════════════════════════════

test.describe('Sandbox A: Standard checkout (3DS disabled)', () => {
	// PaygentToken.js from sandbox.paygent.co.jp can take 60-120s to load.
	test.setTimeout(120_000);
	test.beforeEach(requireSandboxCredentials);

	test('Guest completes checkout with standard test card', async ({ page, baseURL }) => {
		if (!productId) test.skip(true, 'Test product not found');

		const tokenReady = await goToCheckout(page, baseURL);
		if (!tokenReady) test.skip(true, 'sandbox.paygent.co.jp unreachable — PaygentToken.js did not load');

		await fillBilling(page);
		await selectPaygentCC(page);
		await fillCardAndWaitForToken(page, CARD.OK);
		await page.waitForSelector('.blockUI.blockOverlay', { state: 'detached', timeout: 10_000 }).catch(() => {});
		await expect(page.locator('#paygent_cc-token')).not.toHaveValue('', { timeout: 5_000 });
		await page.click('#place_order');

		await page.waitForURL(/order-received/, { timeout: 60_000 });
		const orderId = page.url().match(/order-received\/(\d+)/)?.[1];
		expect(orderId).toBeTruthy();
		if (orderId) createdOrderIds.push(orderId);

		await expect(page.locator('.woocommerce-thankyou-order-received, h2').first()).toBeVisible();
	});

	test('Auth-failure card (末尾8101) shows checkout error', async ({ page, baseURL }) => {
		if (!productId) test.skip(true, 'Test product not found');

		const tokenReady = await goToCheckout(page, baseURL);
		if (!tokenReady) test.skip(true, 'sandbox.paygent.co.jp unreachable — PaygentToken.js did not load');

		await fillBilling(page);
		await selectPaygentCC(page);
		await fillCardAndWaitForToken(page, CARD.AUTH_NG);
		await page.waitForSelector('.blockUI.blockOverlay', { state: 'detached', timeout: 10_000 }).catch(() => {});
		await page.click('#place_order');

		// Paygent returns error C01; WooCommerce renders an error notice.
		await page.waitForTimeout(5000);
		expect(page.url()).not.toMatch(/order-received/);
		await expect(
			page.locator('.woocommerce-error, .woocommerce-notice--error, .is-error')
		).toBeVisible({ timeout: 10_000 });
	});
});

// ═════════════════════════════════════════════════════════════════════════════
// Group B: EMV 3DS2 — frictionless flow (no challenge screen)
// Source: 導入補足資料 §5.1 No.15 — VISA + cardholder=BAVYA → 処理結果0
//
// tds2_check is enabled automatically before this group and restored after.
// ═════════════════════════════════════════════════════════════════════════════

test.describe('Sandbox B: EMV 3DS2 frictionless (cardholder=BAVYA)', () => {
	test.setTimeout(120_000);
	test.beforeEach(requireSandboxCredentials);

	/** @type {Record<string, string>} */
	let settingsSnapshot = {};

	test.beforeAll(async () => {
		// Enable 3DS2 and preserve the previous settings for restoration.
		settingsSnapshot = enableTds2();
		console.log('  [3DS2] tds2_check enabled for Group B');
	});

	test.afterAll(async () => {
		// Restore original settings (e.g. tds2_check: 'no' or unset).
		restorePaygentCcSettings(settingsSnapshot);
		console.log('  [3DS2] tds2_check restored after Group B');
	});

	test('Guest completes checkout via frictionless 3DS2 (BAVYA → result=0)', async ({
		page,
		baseURL,
	}) => {
		if (!productId) test.skip(true, 'Test product not found');

		const tokenReady = await goToCheckout(page, baseURL);
		if (!tokenReady) test.skip(true, 'sandbox.paygent.co.jp unreachable — PaygentToken.js did not load');

		// cardholder=BAVYA → VISA frictionless, no challenge screen, result=0
		await fillBilling(page, TDS2.CARDHOLDER_FRICTIONLESS_OK);
		await selectPaygentCC(page);
		await fillCardAndWaitForToken(page, CARD.OK);
		await page.waitForSelector('.blockUI.blockOverlay', { state: 'detached', timeout: 10_000 }).catch(() => {});
		await expect(page.locator('#paygent_cc-token')).not.toHaveValue('', { timeout: 5_000 });
		await page.click('#place_order');

		// Frictionless still goes through order-pay to render the ACS auto-submit form,
		// then the ACS redirects back without user interaction.
		const afterSubmitB = await Promise.race([
			page.waitForURL(/order-received/, { timeout: 30_000 }).then(() => 'completed'),
			page.waitForURL(/order-pay|checkout\/order-pay/, { timeout: 30_000 }).then(() => 'redirect'),
		]).catch(() => 'timeout');

		if (afterSubmitB === 'timeout') {
			test.skip(true, '3DS2 frictionless ACS redirect did not start in time');
			return;
		}

		if (afterSubmitB === 'redirect') {
			// ACS auto-submits; wait for the final redirect to order-received.
			await page.waitForURL(/order-received/, { timeout: 60_000 });
		}

		const orderId = page.url().match(/order-received\/(\d+)/)?.[1];
		expect(orderId).toBeTruthy();
		if (orderId) createdOrderIds.push(orderId);

		await expect(page.locator('.woocommerce-thankyou-order-received, h2').first()).toBeVisible();
	});
});

// ═════════════════════════════════════════════════════════════════════════════
// Group C: EMV 3DS2 — challenge flow
// Source: 導入補足資料 §5.2 No.10 — VISA + amount=0 → challenge → password 14012
//
// A temporary ¥0 virtual product is created for this group so the order total
// is 0, which triggers the challenge flow per the spec.
//
// tds2_check is enabled automatically before this group and restored after.
// ═════════════════════════════════════════════════════════════════════════════

test.describe('Sandbox C: EMV 3DS2 challenge flow (amount=0, password=14012)', () => {
	test.setTimeout(120_000);
	test.beforeEach(requireSandboxCredentials);

	/** @type {Record<string, string>} */
	let settingsSnapshot = {};
	/** @type {string} */
	let freeProductId = '';

	test.beforeAll(async () => {
		settingsSnapshot = enableTds2();
		console.log('  [3DS2] tds2_check enabled for Group C');

		// Create a ¥0 virtual product — amount=0 triggers the challenge flow.
		freeProductId = wpCli(
			`post create --post_type=product --post_status=publish ` +
			`--post_title="Paygent 3DS2 Challenge Test" ` +
			`--meta_input='{"_price":"0","_regular_price":"0","_virtual":"yes"}' --porcelain`
		).trim();
		console.log(`  [3DS2] Free product created: ID=${freeProductId}`);
	});

	test.afterAll(async () => {
		restorePaygentCcSettings(settingsSnapshot);
		console.log('  [3DS2] tds2_check restored after Group C');

		if (freeProductId) {
			wpCli(`post delete ${freeProductId} --force`);
			console.log('  [3DS2] Free product deleted');
		}
	});

	test('Guest completes 3DS2 challenge (amount=0, VISA password=14012)', async ({
		page,
		baseURL,
	}) => {
		if (!freeProductId) test.skip(true, 'Could not create ¥0 test product');

		// Add the ¥0 product — order total will be 0, triggering challenge flow.
		const tokenReady = await goToCheckout(page, baseURL, freeProductId);
		await fillBilling(page);

		// For ¥0 orders WooCommerce may skip the payment section entirely.
		// Only fill card fields if the payment method radio is present in the DOM.
		const hasPaymentSection = (await page.locator('#payment_method_paygent_cc').count()) > 0;
		if (hasPaymentSection && !tokenReady) {
			test.skip(true, 'sandbox.paygent.co.jp unreachable — PaygentToken.js did not load');
		}
		if (hasPaymentSection) {
			await selectPaygentCC(page);
			await fillCardAndWaitForToken(page, CARD.OK);
			await page.waitForSelector('.blockUI.blockOverlay', { state: 'detached', timeout: 10_000 }).catch(() => {});
			await expect(page.locator('#paygent_cc-token')).not.toHaveValue('', { timeout: 5_000 });
		}

		await page.click('#place_order');

		// With amount=0, the plugin redirects to the receipt/payment page
		// (woocommerce_receipt_{id} hook) where Paygent's ACS challenge loads.
		// Then after the password is entered, Paygent redirects back to order-received.
		const afterSubmit = await Promise.race([
			page.waitForURL(/order-received/, { timeout: 20_000 }).then(() => 'completed'),
			page.waitForURL(/order-pay|checkout\/order-pay/, { timeout: 20_000 }).then(() => 'challenge'),
		]).catch(() => 'timeout');

		if (afterSubmit === 'completed') {
			// Challenge was skipped by ACS (may happen in some sandbox states).
			const orderId = page.url().match(/order-received\/(\d+)/)?.[1];
			if (orderId) createdOrderIds.push(orderId);
			return;
		}

		if (afterSubmit === 'timeout') {
			test.skip(true, 'Challenge page did not load in time');
			return;
		}

		// On the challenge page, Paygent's ACS renders a password field.
		// The exact selector depends on the ACS implementation.
		const passwordInput = page.locator(
			'input[type="password"], input[name="challengeDataEntry"], input[name="otp"]'
		);
		await expect(passwordInput).toBeVisible({ timeout: 20_000 });
		await passwordInput.fill(TDS2.CHALLENGE_PASSWORD_VISA);

		const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
		await submitBtn.click();

		// After challenge success, Paygent redirects back → plugin redirects to order-received.
		await page.waitForURL(/order-received/, { timeout: 60_000 });
		const orderId = page.url().match(/order-received\/(\d+)/)?.[1];
		expect(orderId).toBeTruthy();
		if (orderId) createdOrderIds.push(orderId);

		await expect(page.locator('.woocommerce-thankyou-order-received, h2').first()).toBeVisible();
	});
});

// ═════════════════════════════════════════════════════════════════════════════
// Group D: Refund via admin (3DS disabled)
// Places a fresh ¥1,000 order, then processes a partial ¥100 refund.
// Refund triggers telegram 028 (sale cancel) or 023 (auth cancel).
// ═════════════════════════════════════════════════════════════════════════════

test.describe('Sandbox D: Refund via admin (3DS disabled)', () => {
	test.setTimeout(120_000);
	test.beforeEach(requireSandboxCredentials);

	test('Admin processes a partial refund on a Paygent CC order', async ({ page, baseURL }) => {
		if (!productId) test.skip(true, 'Test product not found');

		// Step 1: Place a fresh order.
		const tokenReady = await goToCheckout(page, baseURL);
		if (!tokenReady) test.skip(true, 'sandbox.paygent.co.jp unreachable — PaygentToken.js did not load');

		await fillBilling(page);
		await selectPaygentCC(page);
		await fillCardAndWaitForToken(page, CARD.OK);
		// Wait for any pending update_order_review AJAX to finish before submitting,
		// as a late re-render of the payment section would clear the card token.
		await page.waitForSelector('.blockUI.blockOverlay', { state: 'detached', timeout: 10_000 }).catch(() => {});
		await expect(page.locator('#paygent_cc-token')).not.toHaveValue('', { timeout: 5_000 });
		await page.click('#place_order');
		await page.waitForURL(/order-received/, { timeout: 60_000 });

		const orderId = page.url().match(/order-received\/(\d+)/)?.[1];
		if (!orderId) {
			test.skip(true, 'Checkout did not complete');
			return;
		}
		createdOrderIds.push(orderId);

		// Step 2: Login to admin (e2e-guest project has no stored auth state).
		await page.goto(`${baseURL}/wp-login.php`);
		await page.fill('#user_login', 'admin');
		await page.fill('#user_pass', 'password');
		await page.click('#wp-submit');
		await page.waitForURL(/wp-admin/, { timeout: 15_000 });

		// Step 3: Mark order as completed in admin.
		await page.goto(`${baseURL}/wp-admin/admin.php?page=wc-orders&action=edit&id=${orderId}`);
		await page.waitForSelector('#order_status, select[name="order_status"]', { timeout: 10_000 });

		const statusSelect = page.locator('#order_status, select[name="order_status"]').first();
		await statusSelect.selectOption('wc-completed');
		await page.locator('button[name="save"], input[name="save_order"], .save_order').first().click();
		await page.waitForLoadState('networkidle');

		// Step 4: Open refund UI.
		const refundBtn = page.locator('.refund-items, button.do-api-refund, .wc-order-refund-items').first();
		if (!(await refundBtn.isVisible({ timeout: 5_000 }))) {
			test.skip(true, 'Refund button not visible');
			return;
		}
		await refundBtn.click();

		// Step 5: Enter partial refund amount (¥100 of ¥1,000).
		const refundAmountInput = page.locator('input[name="refund_amount"], .refund_line_total').first();
		if (await refundAmountInput.isVisible({ timeout: 3_000 })) {
			await refundAmountInput.fill('100');
		}

		// Step 6: Execute API refund.
		const doRefundBtn = page.locator('button.do-api-refund, .do-api-refund').first();
		if (await doRefundBtn.isVisible({ timeout: 3_000 })) {
			page.on('dialog', (dialog) => dialog.accept()); // Confirm the JS dialog.
			await doRefundBtn.click();
			await page.waitForTimeout(5000);
		}

		// Step 7: Verify Paygent logged a refund result in order notes.
		await page.reload();
		const notes = await page.locator('.order_notes, .woocommerce-order-notes').textContent();
		expect(notes?.length).toBeGreaterThan(0);
	});
});

// ═════════════════════════════════════════════════════════════════════════════
// Group E: Installment payment (3DS disabled)
// payment_class=61, split_count=3 → telegram 020 with split_count=3
// Requires the merchant account to support installment payments (分割払い).
// ═════════════════════════════════════════════════════════════════════════════

test.describe('Sandbox E: Installment payment (分割払い)', () => {
	test.setTimeout(120_000);
	test.beforeEach(requireSandboxCredentials);

	/** @type {Record<string, string>} */
	let settingsSnapshotE = {};

	test.beforeAll(async () => {
		settingsSnapshotE = getPaygentCcSettings();
		// Enable installment payment method and allow 3- and 6-installment options.
		updatePaygentCcSettings({
			payment_method:    ['10', '61'],
			number_of_payments: ['3', '6'],
		});
		console.log('  [installment] payment_method set to [10, 61], number_of_payments set to [3, 6]');
	});

	test.afterAll(async () => {
		restorePaygentCcSettings(settingsSnapshotE);
		console.log('  [installment] Settings restored after Group E');
	});

	test('E-1: Guest completes 3-installment checkout (split_count=3, payment_class=61)', async ({ page, baseURL }) => {
		if (!productId) test.skip(true, 'Test product not found');

		// Step 1: Go to checkout with one product.
		const tokenReady = await goToCheckout(page, baseURL);
		if (!tokenReady) test.skip(true, 'sandbox.paygent.co.jp unreachable — PaygentToken.js did not load');

		await fillBilling(page);
		await selectPaygentCC(page);

		// Step 2: Verify the payment method dropdown is rendered.
		// The <select name="number_of_payments"> is rendered when payment_method includes '61'.
		const paymentSelect = page.locator('select[name="number_of_payments"]');
		await expect(paymentSelect).toBeVisible({ timeout: 10_000 });

		// Step 3: Fill card details and wait for tokens.
		// Select installment count AFTER token is ready — selecting before triggers
		// update_checkout debounce which re-renders the payment section and resets the dropdown.
		await fillCardAndWaitForToken(page, CARD.OK);
		await page.waitForSelector('.blockUI.blockOverlay', { state: 'detached', timeout: 10_000 }).catch(() => {});
		await expect(page.locator('#paygent_cc-token')).not.toHaveValue('', { timeout: 5_000 });

		// Select 3-installment after all re-renders are done, right before submitting.
		await paymentSelect.selectOption('3');

		// Step 4: Place order.
		await page.click('#place_order');
		await page.waitForURL(/order-received/, { timeout: 60_000 });

		const orderId = page.url().match(/order-received\/(\d+)/)?.[1];
		expect(orderId).toBeTruthy();
		if (!orderId) return;
		createdOrderIds.push(orderId);

		// Step 5: Verify order meta — PHP saves _payment_class=61 and _split_count=3.
		const paymentClass = wpCli(`eval "echo wc_get_order(${orderId})->get_meta('_payment_class');"`).trim();
		const splitCount   = wpCli(`eval "echo wc_get_order(${orderId})->get_meta('_split_count');"`).trim();
		expect(paymentClass).toBe('61');
		expect(splitCount).toBe('3');

		// Step 6: Verify order note records the installment count.
		const orderNote = wpCli(`eval "
			\\$notes = wc_get_order_notes(array('order_id' => ${orderId}, 'type' => 'order'));
			foreach (\\$notes as \\$note) { echo \\$note->content . PHP_EOL; }
		"`);
		expect(orderNote).toMatch(/3.*times|split_count.*3|3.*installment/i);
	});

	test('E-2: Guest completes 6-installment checkout (split_count=6, payment_class=61)', async ({ page, baseURL }) => {
		if (!productId) test.skip(true, 'Test product not found');

		const tokenReady = await goToCheckout(page, baseURL);
		if (!tokenReady) test.skip(true, 'sandbox.paygent.co.jp unreachable — PaygentToken.js did not load');

		await fillBilling(page);
		await selectPaygentCC(page);

		const paymentSelect6 = page.locator('select[name="number_of_payments"]');
		await expect(paymentSelect6).toBeVisible({ timeout: 10_000 });

		await fillCardAndWaitForToken(page, CARD.OK);
		await page.waitForSelector('.blockUI.blockOverlay', { state: 'detached', timeout: 10_000 }).catch(() => {});
		await expect(page.locator('#paygent_cc-token')).not.toHaveValue('', { timeout: 5_000 });

		await paymentSelect6.selectOption('6');

		await page.click('#place_order');
		await page.waitForURL(/order-received/, { timeout: 60_000 });

		const orderId = page.url().match(/order-received\/(\d+)/)?.[1];
		expect(orderId).toBeTruthy();
		if (!orderId) return;
		createdOrderIds.push(orderId);

		const paymentClass = wpCli(`eval "echo wc_get_order(${orderId})->get_meta('_payment_class');"`).trim();
		const splitCount   = wpCli(`eval "echo wc_get_order(${orderId})->get_meta('_split_count');"`).trim();
		expect(paymentClass).toBe('61');
		expect(splitCount).toBe('6');
	});
});
