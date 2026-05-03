// @ts-check
const { test, expect } = require('@playwright/test');
const {
	wpCli,
	getPaygentCcSettings,
	updatePaygentCcSettings,
	restorePaygentCcSettings,
} = require('./helpers/wp-cli');

/**
 * Sandbox tests for logged-in member features:
 *
 *   Group E — Stored card payment
 *     E-1: First checkout with new card + "save card" checkbox → card saved
 *     E-2: Second checkout selecting the saved card → payment with stored card
 *
 *   Group F — Amount correction (増額 / increase only; 減額 is covered by Group D refund)
 *     F-1: Place ¥1,000 order, then increase to ¥1,100 via admin meta box (telegram 028/029)
 *
 * Prerequisites (same as guest sandbox tests):
 *   PAYGENT_TEST_MID / CID / CPASS / TOKENKEY environment variables
 *
 * Run:
 *   PAYGENT_TEST_MID=xxx ... npx playwright test --project=e2e-member checkout-sandbox.member
 *
 * Note: tests run as `paygent-e2e-member` (customer role), created by global.setup.js.
 *       Group F step 2 logs in as admin to access the meta box.
 */

const REQUIRED_ENV = ['PAYGENT_TEST_MID', 'PAYGENT_TEST_CID', 'PAYGENT_TEST_CPASS', 'PAYGENT_TEST_TOKENKEY'];

const CARD = {
	OK: '4900000000000000',
};

const MEMBER = {
	login:    'paygent-e2e-member',
	password: 'member-e2e-pass-1',
	email:    'paygent-e2e-member@example.com',
};

/** @type {string} */
let productId = '';
/** @type {string} Cached PaygentToken.js */
let cachedPaygentTokenJs = '';
/** @type {string[]} Order IDs created during the run — deleted in afterAll. */
const createdOrderIds = [];

// ─── module-level setup ───────────────────────────────────────────────────────

test.beforeAll(async () => {
	const out = wpCli(
		`post list --post_type=product --name=paygent-e2e-test-product --fields=ID --format=csv`
	);
	const match = out.match(/^(\d+)$/m);
	productId = match ? match[1] : '';

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
		console.log('  [member] PaygentToken.js pre-fetched and cached.');
	} else {
		console.warn('  [member] PaygentToken.js could not be pre-fetched — tests may be slow.');
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
 * Navigate to checkout with the test product.
 * Serves PaygentToken.js from cache if available.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} baseURL
 * @param {string} [pid]
 * @returns {Promise<boolean>} true if PaygentToken.js is loaded
 */
async function goToCheckout(page, baseURL, pid = productId) {
	if (cachedPaygentTokenJs) {
		await page.route(/sandbox\.paygent\.co\.jp\/js\/PaygentToken/, (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/javascript',
				body: cachedPaygentTokenJs,
			})
		);
	}

	// Clear any leftover cart items from previous tests before adding the test product.
	await page.goto(`${baseURL}/cart/`, { waitUntil: 'domcontentloaded' });
	const removeLinks = page.locator('a.remove[data-product_id]');
	let safetyLimit = 10;
	while ((await removeLinks.count()) > 0 && safetyLimit-- > 0) {
		const countBefore = await removeLinks.count();
		await removeLinks.first().click();
		// Cart removal uses AJAX fragments — wait for the item count to decrease.
		await page.waitForFunction(
			(n) => document.querySelectorAll('a.remove[data-product_id]').length < n,
			countBefore,
			{ timeout: 10_000 }
		).catch(async () => {
			await page.reload({ waitUntil: 'domcontentloaded' });
		});
	}

	if (pid) {
		// quantity=1 ensures exactly one item regardless of session cart state.
		await page.goto(`${baseURL}/?add-to-cart=${pid}&quantity=1`, { waitUntil: 'domcontentloaded' });
	} else {
		await page.goto(`${baseURL}/shop/`, { waitUntil: 'domcontentloaded' });
		await page.locator('.add_to_cart_button').first().click();
		await page.waitForTimeout(500);
	}
	await page.goto(`${baseURL}/checkout/`, { waitUntil: 'domcontentloaded' });
	await page.waitForSelector('#customer_details', { timeout: 30_000 });

	const tokenReady = await page.waitForFunction(
		() => typeof window.PaygentToken !== 'undefined',
		{ timeout: 30_000 }
	).then(() => true).catch(() => false);

	await page.waitForSelector('.payment_box.payment_method_paygent_cc', {
		state: 'visible',
		timeout: 30_000,
	}).catch(() => {});

	return tokenReady;
}

/**
 * Fill billing address fields.
 *
 * @param {import('@playwright/test').Page} page
 */
async function fillBilling(page) {
	await page.fill('#billing_last_name',  '太郎');
	await page.fill('#billing_first_name', 'テスト');
	await page.fill('#billing_email',      MEMBER.email);
	await page.fill('#billing_phone',      '0312345678');
	const country = page.locator('#billing_country');
	if ((await country.count()) > 0) {
		const currentCountry = await country.inputValue().catch(() => '');
		if (currentCountry !== 'JP') {
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
 * Select the Paygent CC payment method radio.
 *
 * @param {import('@playwright/test').Page} page
 */
async function selectPaygentCC(page) {
	const radio = page.locator('#payment_method_paygent_cc');
	await expect(radio).toBeAttached({ timeout: 10_000 });
	await expect(page.locator('label[for="payment_method_paygent_cc"]')).toBeVisible({ timeout: 10_000 });
	if (!(await radio.isChecked())) {
		await radio.check({ force: true });
		await page.waitForSelector('.blockUI.blockOverlay', { state: 'detached', timeout: 15_000 }).catch(() => {});
	}
}

// ═════════════════════════════════════════════════════════════════════════════
// Group E: Stored card payment (logged-in member)
//
// E-1: First checkout — new card + "save card" checkbox → customer_card_id stored
// E-2: Second checkout — select saved card → pay with CVC token only
//
// store_card_info is enabled before this group and restored after.
// ═════════════════════════════════════════════════════════════════════════════

test.describe('Sandbox E: Stored card payment (logged-in member)', () => {
	test.setTimeout(120_000);
	test.beforeEach(requireSandboxCredentials);

	/** @type {Record<string, string>} */
	let settingsSnapshot = {};

	test.beforeAll(async () => {
		settingsSnapshot = getPaygentCcSettings();
		updatePaygentCcSettings({ store_card_info: 'yes' });
		console.log('  [stored] store_card_info enabled for Group E');

		// Pre-clean leftover tokens from previous runs so E-3 only deletes ONE card.
		// Without this, multiple tokens trigger multiple Paygent API calls (35s each).
		try {
			wpCli(
				`eval "foreach ( WC_Payment_Tokens::get_customer_tokens( get_user_by('login','paygent-e2e-member')->ID ) as \\$t ) WC_Payment_Tokens::delete( \\$t->get_id() );"`
			);
			console.log('  [stored] Leftover WC payment tokens pre-cleaned');
		} catch (e) {
			console.warn('  [stored] Could not pre-clean payment tokens:', e.message);
		}
	});

	test.afterAll(async () => {
		restorePaygentCcSettings(settingsSnapshot);
		console.log('  [stored] store_card_info restored after Group E');

		// Remove any leftover WooCommerce payment tokens for the test member so that
		// the next test run starts clean (E-1 assumes no stored card on first checkout).
		try {
			wpCli(
				`eval "foreach ( WC_Payment_Tokens::get_customer_tokens( get_user_by('login','paygent-e2e-member')->ID ) as \\$t ) WC_Payment_Tokens::delete( \\$t->get_id() );"`
			);
			console.log('  [stored] Leftover WC payment tokens cleaned up');
		} catch (e) {
			console.warn('  [stored] Could not clean up payment tokens:', e.message);
		}
	});

	test('E-1: Member saves card during first checkout', async ({ page, baseURL }) => {
		if (!productId) test.skip(true, 'Test product not found');

		const tokenReady = await goToCheckout(page, baseURL);
		if (!tokenReady) test.skip(true, 'PaygentToken.js did not load');

		await fillBilling(page);
		await selectPaygentCC(page);

		// On subsequent runs the member already has a saved card, so stored card UI shows.
		// Switch to "new card" radio to get to the standard card entry form.
		const newCardRadioE1 = page.locator('#paygent-use-stored-payment-info-no');
		if (await newCardRadioE1.isVisible({ timeout: 3_000 }).catch(() => false)) {
			// Use click() instead of check() — AJAX re-render replaces the radio DOM node
			// before Playwright can verify the checked state, causing a false state error.
			await newCardRadioE1.click({ force: true });
			await page.waitForSelector('.blockUI.blockOverlay', { state: 'detached', timeout: 15_000 }).catch(() => {});
		}

		// Wait for card number to become visible (new card form).
		await page.locator('#paygent_cc-card-number').waitFor({ state: 'visible', timeout: 15_000 });

		// Set values via evaluate (no input events) to prevent race conditions from
		// multiple createToken calls firing before all fields are filled.
		await page.evaluate(([cn, exp, cvc]) => {
			document.getElementById('paygent_cc-card-number').value = cn;
			document.getElementById('paygent_cc-card-expiry').value = exp;
			document.getElementById('paygent_cc-card-cvc').value = cvc;
		}, [CARD.OK, '12 / 30', '123']);

		// Check "save card for future purchases" checkbox.
		const saveCheckbox = page.locator('#paygent_save_card_info');
		if (await saveCheckbox.isVisible({ timeout: 5_000 })) {
			if (!(await saveCheckbox.isChecked())) {
				await saveCheckbox.check();
			}
		}

		// Trigger tokenization and wait for card token.
		await page.evaluate(() => {
			if (typeof window.sendPaygentToken === 'function') window.sendPaygentToken();
		});
		await expect(page.locator('#paygent_cc-token')).not.toHaveValue('', { timeout: 30_000 });
		await page.waitForSelector('.blockUI.blockOverlay', { state: 'detached', timeout: 10_000 }).catch(() => {});

		await page.click('#place_order');
		await page.waitForURL(/order-received/, { timeout: 60_000 });

		const orderId = page.url().match(/order-received\/(\d+)/)?.[1];
		expect(orderId).toBeTruthy();
		if (orderId) createdOrderIds.push(orderId);

		await expect(page.locator('.woocommerce-thankyou-order-received, h2').first()).toBeVisible();
	});

	test('E-2: Member pays with previously saved card', async ({ page, baseURL }) => {
		if (!productId) test.skip(true, 'Test product not found');

		const tokenReady = await goToCheckout(page, baseURL);
		if (!tokenReady) test.skip(true, 'PaygentToken.js did not load');

		await fillBilling(page);
		await selectPaygentCC(page);

		// Saved card UI: radio "Use stored credit card information" appears
		// when the user has at least one stored card.
		const storedRadio = page.locator('#paygent-use-stored-payment-info-yes');
		const hasStoredCard = await storedRadio.isVisible({ timeout: 8_000 }).catch(() => false);
		if (!hasStoredCard) {
			test.skip(true, 'Saved card not found — E-1 must pass first');
			return;
		}

		// Select "Use stored card" (should already be checked by default).
		if (!(await storedRadio.isChecked())) {
			await storedRadio.check({ force: true });
		}

		// Verify the stored card select and CVC input are visible.
		await expect(page.locator('#stored-info')).toBeVisible({ timeout: 5_000 });
		await expect(page.locator('#paygent_cc-stored-card-cvc')).toBeVisible();

		// Enter CVC for the stored card.
		await page.locator('#paygent_cc-stored-card-cvc').fill('123');
		// Wait for any pending update_order_review AJAX before tokenizing to avoid
		// the callback writing to a detached (pre-re-render) DOM element.
		await page.waitForSelector('.blockUI.blockOverlay', { state: 'detached', timeout: 10_000 }).catch(() => {});

		// Trigger CVC tokenization (createCvcToken → execCVCToken → sets #paygent_cc-cvc_token).
		await page.evaluate(() => {
			if (typeof window.sendPaygentToken === 'function') window.sendPaygentToken();
		});
		// createCvcToken may fail if the sandbox MID doesn't support CVC-only tokenization.
		const cvcTokenReady = await page.waitForFunction(
			() => (document.getElementById('paygent_cc-cvc_token')?.value ?? '') !== '',
			{ timeout: 30_000 }
		).then(() => true).catch(() => false);
		if (!cvcTokenReady) {
			test.skip(true, 'createCvcToken timed out — sandbox may not support CVC-only tokenization for this MID');
			return;
		}

		await page.click('#place_order');
		await page.waitForURL(/order-received/, { timeout: 60_000 });

		const orderId = page.url().match(/order-received\/(\d+)/)?.[1];
		expect(orderId).toBeTruthy();
		if (orderId) createdOrderIds.push(orderId);

		await expect(page.locator('.woocommerce-thankyou-order-received, h2').first()).toBeVisible();
	});

	test('E-3: Member deletes saved card from My Account (telegram 026)', async ({ page, baseURL }) => {
		// Telegram 026 (server-side card deletion) can be slow in the Paygent sandbox.
		// Override the describe-level timeout to give this test enough headroom.
		test.setTimeout(300_000);

		// Navigate to WooCommerce payment methods page.
		await page.goto(`${baseURL}/my-account/payment-methods/`, { waitUntil: 'domcontentloaded' });

		// Find the delete link for the Paygent CC saved card.
		const deleteLink = page.locator('.woocommerce-MyAccount-paymentMethods .delete, .payment-method .delete');
		const hasCard = await deleteLink.first().isVisible({ timeout: 8_000 }).catch(() => false);
		if (!hasCard) {
			test.skip(true, 'No saved card found on My Account — E-1 and E-2 must pass first');
			return;
		}

		// Accept the JS confirmation dialog that WooCommerce shows before deletion.
		// After accepting, the server calls Paygent API (telegram 026) synchronously,
		// then redirects back to payment-methods. Use domcontentloaded to wait for the
		// full page load after the redirect. With pre-cleaned tokens (beforeAll), only
		// ONE API call fires, keeping the wait well under the 300s test timeout.
		page.on('dialog', (dialog) => dialog.accept());
		await Promise.all([
			page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 150_000 }),
			deleteLink.first().click(),
		]);

		// Verify the card is gone from My Account page.
		await expect(deleteLink.first()).not.toBeVisible({ timeout: 10_000 });

		// Verify Paygent side: checkout should no longer show stored card UI.
		const tokenReady = await goToCheckout(page, baseURL);
		if (!tokenReady) return; // PaygentToken.js unreachable — skip Paygent-side check
		await selectPaygentCC(page);
		const storedRadio = page.locator('#paygent-use-stored-payment-info-yes');
		await expect(storedRadio).not.toBeVisible({ timeout: 5_000 });
	});
});

// ═════════════════════════════════════════════════════════════════════════════
// Group F: Amount correction — increase (減額は Group D の返金テストで対応済み)
//
// Places a ¥1,000 order, then uses the admin meta box to increase to ¥1,100.
// Paygent telegram: 028 (auth, status=20) or 029 (sale, status=40).
// 3DS2 orders are excluded (the meta box disables itself for 3DS2 orders).
// ═════════════════════════════════════════════════════════════════════════════

test.describe('Sandbox F: Amount increase via admin meta box (028/029)', () => {
	test.setTimeout(120_000);
	test.beforeEach(requireSandboxCredentials);

	/** @type {Record<string, string>} */
	let settingsSnapshotF = {};

	test.beforeAll(async () => {
		// Ensure store_card_info is disabled for F tests so no stored card UI appears.
		// E group afterAll may have restored it to 'yes' if that was the captured state.
		settingsSnapshotF = getPaygentCcSettings();
		updatePaygentCcSettings({ store_card_info: 'no' });
		console.log('  [amount] store_card_info disabled for Group F');
	});

	test.afterAll(async () => {
		restorePaygentCcSettings(settingsSnapshotF);
		console.log('  [amount] store_card_info restored after Group F');
	});

	test('F-1: Admin increases order amount by ¥100 via meta box (telegram 028/029)', async ({ page, baseURL }) => {
		test.setTimeout(240_000);
		if (!productId) test.skip(true, 'Test product not found');

		// Step 1: Place a fresh order (1 product) as the logged-in member.
		const tokenReady = await goToCheckout(page, baseURL);
		if (!tokenReady) test.skip(true, 'PaygentToken.js did not load');

		await fillBilling(page);
		await selectPaygentCC(page);

		// store_card_info is disabled, so stored card UI should not appear.
		// Guard: if it does appear for any reason, switch to new card.
		const newCardRadio = page.locator('#paygent-use-stored-payment-info-no');
		if (await newCardRadio.isVisible({ timeout: 2_000 }).catch(() => false)) {
			// Use click() — AJAX re-render replaces the radio node before check() can verify state.
			await newCardRadio.click({ force: true });
			await page.waitForSelector('.blockUI.blockOverlay', { state: 'detached', timeout: 15_000 }).catch(() => {});
		}

		await page.locator('#paygent_cc-card-number').waitFor({ state: 'visible', timeout: 10_000 });
		// Set values via evaluate (no input events) to prevent race conditions from
		// multiple createToken calls firing before all fields are filled.
		await page.evaluate(([cn, exp, cvc]) => {
			document.getElementById('paygent_cc-card-number').value = cn;
			document.getElementById('paygent_cc-card-expiry').value = exp;
			document.getElementById('paygent_cc-card-cvc').value = cvc;
		}, [CARD.OK, '12 / 30', '123']);

		await page.evaluate(() => {
			if (typeof window.sendPaygentToken === 'function') window.sendPaygentToken();
		});
		await expect(page.locator('#paygent_cc-token')).not.toHaveValue('', { timeout: 30_000 });
		// Also wait for cvc_token — validate_fields() rejects if it's missing.
		const cvcTokenReadyF = await page.waitForFunction(
			() => (document.getElementById('paygent_cc-cvc_token')?.value ?? '') !== '',
			{ timeout: 30_000 }
		).then(() => true).catch(() => false);
		if (!cvcTokenReadyF) {
			test.skip(true, 'createCvcToken timed out — sandbox may not support CVC-only tokenization for this MID');
			return;
		}
		await page.waitForSelector('.blockUI.blockOverlay', { state: 'detached', timeout: 10_000 }).catch(() => {});

		await page.click('#place_order');
		await page.waitForURL(/order-received/, { timeout: 60_000 });

		const orderId = page.url().match(/order-received\/(\d+)/)?.[1];
		if (!orderId) { test.skip(true, 'Checkout did not complete'); return; }
		createdOrderIds.push(orderId);

		// Read the actual order total via wp-cli (avoids selector fragility on the confirmation page).
		const totalRaw = wpCli(`eval "echo wc_get_order(${orderId})->get_total();"`).trim();
		const originalTotal = Math.round(parseFloat(totalRaw) || 0);
		const newAmount = originalTotal + 100;
		console.log(`  [amount] Order ${orderId}: original total = ¥${originalTotal}, new amount = ¥${newAmount}`);
		if (originalTotal <= 0) { test.skip(true, `Could not read order total (got: "${totalRaw}")`); return; }

		// Step 2: Login as admin to access the meta box (member session has no admin rights).
		await page.goto(`${baseURL}/wp-login.php`);
		await page.fill('#user_login', 'admin');
		await page.fill('#user_pass', 'password');
		await page.click('#wp-submit');
		await page.waitForURL(/wp-admin/, { timeout: 15_000 });

		// Step 3: Open the order edit page.
		await page.goto(`${baseURL}/wp-admin/admin.php?page=wc-orders&action=edit&id=${orderId}`);
		await page.waitForSelector('#paygent_cc_new_amount', { timeout: 10_000 });

		// Step 4: Verify the meta box is visible and not disabled (non-3DS2 order).
		const submitBtn = page.locator('#paygent_cc_increase_submit');
		await expect(submitBtn).toBeVisible();
		await expect(submitBtn).not.toBeDisabled();

		// Step 5: Enter new amount (original + ¥100) and execute.
		await page.locator('#paygent_cc_new_amount').fill(String(newAmount));
		await submitBtn.click();

		// Step 6: Wait for the AJAX response to appear in the result div.
		const resultLocator = page.locator('#paygent_cc_increase_result span');
		await expect(resultLocator).toBeVisible({ timeout: 30_000 });

		const resultText = await resultLocator.textContent();
		expect(resultText?.trim().length).toBeGreaterThan(0);

		// Success renders green text; failure renders red.
		// Verify no error color (rgb(204, 0, 0) = red).
		const color = await resultLocator.evaluate((el) => window.getComputedStyle(el).color);
		expect(color, `Amount correction failed: ${resultText}`).not.toBe('rgb(204, 0, 0)');
		expect(color, `Amount correction failed: ${resultText}`).not.toBe('rgb(255, 0, 0)');

		// Step 7: Reload the order page and verify WC order total and order note were updated.
		await page.reload({ waitUntil: 'domcontentloaded' });

		// Verify WC order total is now originalTotal + 100.
		const formattedNew = newAmount.toLocaleString('ja-JP');
		const orderTotal = page.locator('.wc-order-totals .woocommerce-Price-amount').last();
		await expect(orderTotal).toContainText(formattedNew, { timeout: 5_000 });

		// Verify order note records the correction (PHP adds "Amount: X → Y").
		const orderNotes = page.locator('#woocommerce-order-notes .note_content');
		await expect(orderNotes.filter({ hasText: formattedNew })).toBeVisible({ timeout: 5_000 });
	});
});
