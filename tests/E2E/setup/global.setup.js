// @ts-check
const { chromium } = require('@playwright/test');
const { execSync } = require('child_process');
const path = require('path');
const fs = require('fs');

/**
 * Global setup: runs once before all E2E tests.
 *
 * 1. Configures WooCommerce (currency, tax, etc.) via WP-CLI
 * 2. Enables Paygent CC gateway in test mode
 * 3. Creates a test product if it doesn't exist
 * 4. Authenticates admin and saves session to tests/E2E/.auth/admin.json
 */
async function globalSetup() {
	console.log('\n🔧 Running global E2E setup...');

	// -------------------------------------------------------------------------
	// Step 1: Configure via WP-CLI
	// -------------------------------------------------------------------------
	const wpEnv = (cmd) => {
		try {
			const result = execSync(`npx wp-env run cli ${cmd}`, {
				cwd: path.resolve(__dirname, '../../..'),
				encoding: 'utf8',
				stdio: ['pipe', 'pipe', 'pipe'],
			});
			return result.trim();
		} catch (err) {
			console.warn(`WP-CLI warning: ${cmd}\n${err.message}`);
			return '';
		}
	};

	// Enable pretty permalinks so /shop/, /checkout/, /wp-json/ all resolve.
	console.log('  → Setting permalink structure...');
	wpEnv(`wp rewrite structure '/%postname%/' --hard`);
	wpEnv(`wp rewrite flush --hard`);

	// WooCommerce settings.
	console.log('  → Setting WooCommerce options...');
	wpEnv(`wp option update woocommerce_currency JPY`);
	wpEnv(`wp option update woocommerce_currency_pos left`);
	wpEnv(`wp option update woocommerce_price_thousand_sep ,`);
	wpEnv(`wp option update woocommerce_price_decimal_sep .`);
	wpEnv(`wp option update woocommerce_price_num_decimals 0`);
	wpEnv(`wp option update woocommerce_calc_taxes no`);
	wpEnv(`wp option update woocommerce_default_country JP`);
	wpEnv(`wp option update woocommerce_currency JPY`);
	wpEnv(`wp option update woocommerce_enable_guest_checkout yes`);
	wpEnv(`wp option update woocommerce_enable_checkout_login_reminder yes`);
	wpEnv(`wp option update woocommerce_coming_soon no`);

	// Enable Paygent CC gateway option (required for gateway to register).
	wpEnv(`wp option update wc-paygent-cc yes`);

	// Set Paygent to test mode.
	wpEnv(`wp option update wc-paygent-testmode 1`);

	// Set test mode credentials from env vars (if provided).
	const testMid      = process.env.PAYGENT_TEST_MID      || '';
	const testCid      = process.env.PAYGENT_TEST_CID      || '';
	const testCpass    = process.env.PAYGENT_TEST_CPASS    || '';
	const testTokenKey = process.env.PAYGENT_TEST_TOKENKEY || '';
	if (testMid)      wpEnv(`wp option update wc-paygent-test-mid "${testMid}"`);
	if (testCid)      wpEnv(`wp option update wc-paygent-test-cid "${testCid}"`);
	if (testCpass)    wpEnv(`wp option update wc-paygent-test-cpass "${testCpass}"`);
	if (testTokenKey) wpEnv(`wp option update wc-paygent-test-tokenkey "${testTokenKey}"`);

	// Enable the Paygent CC gateway in WooCommerce settings.
	// IMPORTANT: Multi-key JSON with commas causes bash brace-expansion inside
	// the wp-env Docker container ({a,b} → split into separate arguments).
	// Overwrite the option with a single-key JSON (no commas), then use
	// wp option patch insert to add further keys individually.
	// Overwriting first ensures the subsequent inserts always see a fresh array.
	wpEnv(`wp option update woocommerce_paygent_cc_settings --format=json '{"enabled":"yes"}'`);
	wpEnv(`wp option patch insert woocommerce_paygent_cc_settings paymentaction sale`);
	wpEnv(`wp option patch insert woocommerce_paygent_cc_settings testmode yes`);

	// Enable HPOS (High Performance Order Storage). admin-order E2E tests
	// navigate to /wp-admin/admin.php?page=wc-orders which only works with
	// HPOS enabled. Without it, WooCommerce redirects to edit.php?post_type=shop_order.
	// WC 8.x uses feature key "custom_order_tables"; set all known option names
	// so the correct one is picked up regardless of WC version.
	wpEnv(`wp option update woocommerce_feature_custom_order_tables_enabled yes`);
	wpEnv(`wp option update woocommerce_feature_hpos_enabled yes`);
	wpEnv(`wp option update woocommerce_custom_orders_table_enabled yes`);

	// Ensure the WooCommerce checkout page uses the classic shortcode form.
	// In WooCommerce 8.3+, the default checkout page uses Block checkout;
	// the functional E2E tests (checkout.spec.js) expect shortcode elements
	// (#customer_details, .payment_method_paygent_cc etc.).
	// Use wp eval to avoid shell glob-expansion of brackets in post_content.
	const checkoutPageId = wpEnv(`wp option get woocommerce_checkout_page_id`).trim();
	if (checkoutPageId.match(/^\d+$/)) {
		wpEnv(`wp eval "wp_update_post(array('ID'=>${checkoutPageId},'post_content'=>'[woocommerce_checkout]'));"`);
		console.log('  → Checkout page set to shortcode form for E2E tests.');
	}

	// Flush object cache so option changes take effect immediately.
	wpEnv(`wp cache flush`);

	// Prevent Japanized for WooCommerce (JP4WC) from redirecting to the Paidy
	// onboarding wizard. JP4WC sets paidy_do_activation_redirect=true on first
	// install and redirects when there are recent orders. Delete it unconditionally
	// so the redirect never fires during tests (even after wp-env clean/restart).
	wpEnv(`wp option delete paidy_do_activation_redirect`);

	// -------------------------------------------------------------------------
	// Step 1b: Create CA bundle for Paygent B2B module (sandbox SSL verification)
	//
	// The PHP B2B module reads CA_FILE_PATH = wp-content/uploads/wc-paygent/curl-ca-bundle.crt.
	// In a fresh wp-env container this directory and file do not exist, causing
	// CURLOPT_CAINFO to point at a missing file and failing every B2B API call.
	// Copy the system CA bundle from the container into the expected path.
	// -------------------------------------------------------------------------
	console.log('  → Ensuring Paygent CA bundle exists...');
	try {
		execSync(
			`npx wp-env run wordpress -- bash -c ` +
			`"mkdir -p /var/www/html/wp-content/uploads/wc-paygent && ` +
			`[ -f /var/www/html/wp-content/uploads/wc-paygent/curl-ca-bundle.crt ] || ` +
			`cp /etc/ssl/certs/ca-certificates.crt /var/www/html/wp-content/uploads/wc-paygent/curl-ca-bundle.crt"`,
			{
				cwd: path.resolve(__dirname, '../../..'),
				encoding: 'utf8',
				stdio: ['pipe', 'pipe', 'pipe'],
			}
		);
		console.log('  → CA bundle ready.');
	} catch (err) {
		console.warn('  ⚠ Could not copy CA bundle:', err instanceof Error ? err.message : String(err));
	}

	// -------------------------------------------------------------------------
	// Step 2: Create a test product if it doesn't exist
	// -------------------------------------------------------------------------
	console.log('  → Ensuring test product exists...');
	const existing = wpEnv(
		`wp post list --post_type=product --name=paygent-e2e-test-product --fields=ID --format=csv`
	);
	if (!existing || existing.includes('ID') && !existing.match(/^\d+$/m)) {
		// Use `wc product create` so WooCommerce hooks fire and wc_product_meta_lookup
		// is populated. A missing lookup entry causes WooCommerce to treat price as ¥0.
		const newId = wpEnv(
			`wp wc product create --name="Paygent E2E Test Product" --slug=paygent-e2e-test-product --status=publish --regular_price=1000 --virtual=false --user=1 --porcelain`
		);
		if (!newId || !newId.match(/^\d+$/)) {
			// Fallback for environments without wc-cli support.
			wpEnv(
				`wp post create --post_type=product --post_title="Paygent E2E Test Product" --post_name=paygent-e2e-test-product --post_status=publish --meta_input='{"_price":"1000","_regular_price":"1000","_virtual":"no","_manage_stock":"no"}'`
			);
			wpEnv(`wp wc tool run regenerate_product_lookup_tables --user=1`);
		}
		console.log('  → Test product created.');
	} else {
		// Rebuild lookup table in case the existing product was created via raw post create.
		wpEnv(`wp wc tool run regenerate_product_lookup_tables --user=1`);
		console.log('  → Test product already exists.');
	}

	// -------------------------------------------------------------------------
	// Step 3: Authenticate admin and save session state
	// -------------------------------------------------------------------------
	console.log('  → Authenticating admin...');
	const authDir = path.resolve(__dirname, '../.auth');
	if (!fs.existsSync(authDir)) {
		fs.mkdirSync(authDir, { recursive: true });
	}

	const baseURL = process.env.E2E_BASE_URL || 'http://localhost:8888';
	const browser = await chromium.launch();
	const context = await browser.newContext();
	const page    = await context.newPage();

	await page.goto(`${baseURL}/wp-login.php`);
	await page.fill('#user_login', 'admin');
	await page.fill('#user_pass', 'password');
	await page.click('#wp-submit');
	await page.waitForURL(`${baseURL}/wp-admin/**`);

	// -------------------------------------------------------------------------
	// Step 4: Dismiss any first-run admin redirects (e.g. Paidy onboarding)
	// -------------------------------------------------------------------------
	// The JP4WC Paidy plugin redirects to its onboarding page on first admin
	// visit to WooCommerce order pages. Visiting the page once dismisses it.
	console.log('  → Dismissing first-run admin redirects...');
	try {
		await page.goto(`${baseURL}/wp-admin/admin.php?page=wc-admin&path=%2Fpaidy-on-boarding`, {
			waitUntil: 'domcontentloaded',
			timeout: 15_000,
		});
		// Click the Finish/Dismiss button if present.
		const finishBtn = page.locator('button:has-text("Finish setup"), .woocommerce-layout__header-close, a:has-text("Back to Paidy settings")');
		if (await finishBtn.first().isVisible({ timeout: 3_000 }).catch(() => false)) {
			await finishBtn.first().click();
		}
	} catch {
		// Non-fatal: onboarding page may not exist in all environments.
	}

	// Also visit the orders list to trigger and dismiss any other redirects.
	try {
		await page.goto(`${baseURL}/wp-admin/admin.php?page=wc-orders`, {
			waitUntil: 'domcontentloaded',
			timeout: 15_000,
		});
	} catch {
		// Non-fatal.
	}

	await context.storageState({ path: path.resolve(authDir, 'admin.json') });

	// -------------------------------------------------------------------------
	// Step 5: Create E2E member user (customer role) for stored-card tests
	// -------------------------------------------------------------------------
	console.log('  → Ensuring E2E member user exists...');
	const memberId = wpEnv(`wp user get paygent-e2e-member --field=ID`);
	if (!memberId.match(/^\d+$/)) {
		wpEnv(
			`wp user create paygent-e2e-member paygent-e2e-member@example.com ` +
			`--role=customer --user_pass=member-e2e-pass-1`
		);
		console.log('  → Member user created.');
	} else {
		console.log('  → Member user already exists.');
	}

	// -------------------------------------------------------------------------
	// Step 6: Authenticate member and save session
	// -------------------------------------------------------------------------
	console.log('  → Authenticating member...');
	const memberContext = await browser.newContext();
	const memberPage    = await memberContext.newPage();

	await memberPage.goto(`${baseURL}/wp-login.php`);
	await memberPage.fill('#user_login', 'paygent-e2e-member');
	await memberPage.fill('#user_pass', 'member-e2e-pass-1');
	await memberPage.click('#wp-submit');
	// Customers redirect to /my-account/ after login, not /wp-admin/.
	await memberPage.waitForURL((url) => !url.href.includes('wp-login'), { timeout: 15_000 });

	await memberContext.storageState({ path: path.resolve(authDir, 'member.json') });
	await memberContext.close();

	await browser.close();

	console.log('  → Admin + member sessions saved.');
	console.log('✅ Global setup complete.\n');
}

module.exports = globalSetup;
