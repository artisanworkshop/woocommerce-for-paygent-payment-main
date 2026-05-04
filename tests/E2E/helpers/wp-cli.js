// @ts-check
const { execSync } = require('child_process');
const path = require('path');

const ROOT = path.resolve(__dirname, '../../..');

/**
 * Run a WP-CLI command inside the wp-env CLI container.
 *
 * @param {string} cmd  WP-CLI command (without leading "wp")
 * @param {object} [opts]
 * @param {boolean} [opts.throws]  Throw on non-zero exit (default: false)
 * @returns {string} stdout trimmed
 */
function wpCli(cmd, { throws = false } = {}) {
	try {
		return execSync(`npx wp-env run cli wp ${cmd}`, {
			cwd: ROOT,
			encoding: 'utf8',
			stdio: ['pipe', 'pipe', 'pipe'],
		}).trim();
	} catch (err) {
		if (throws) throw err;
		return '';
	}
}

/**
 * Create or return the ID of the E2E test product.
 *
 * @returns {string} Product post ID
 */
function ensureTestProduct() {
	const existing = wpCli(
		`post list --post_type=product --name=paygent-e2e-test-product --fields=ID --format=csv`
	);
	const match = existing.match(/^(\d+)$/m);
	if (match) return match[1];

	wpCli(
		`post create --post_type=product --post_title="Paygent E2E Test Product" --post_name=paygent-e2e-test-product --post_status=publish --meta_input='{"_price":"1000","_regular_price":"1000","_virtual":"no","_manage_stock":"no"}' --porcelain`
	);
	const id = wpCli(
		`post list --post_type=product --name=paygent-e2e-test-product --fields=ID --format=csv`
	);
	return (id.match(/^(\d+)$/m) || ['', ''])[1];
}

/**
 * Delete all orders created during a test run.
 *
 * @param {string[]} orderIds
 */
function deleteOrders(orderIds) {
	if (!orderIds.length) return;
	wpCli(`post delete ${orderIds.join(' ')} --force`);
}

/**
 * Read the current woocommerce_paygent_cc_settings option as an object.
 *
 * @returns {Record<string, string>}
 */
function getPaygentCcSettings() {
	const raw = wpCli(`option get woocommerce_paygent_cc_settings --format=json`);
	try {
		return JSON.parse(raw) || {};
	} catch {
		return {};
	}
}

/**
 * Merge partial settings into woocommerce_paygent_cc_settings and save.
 * Preserves all existing keys; only the provided keys are updated.
 *
 * @param {Record<string, string>} overrides
 */
function updatePaygentCcSettings(overrides) {
	const current  = getPaygentCcSettings();
	const merged   = { ...current, ...overrides };
	const json     = JSON.stringify(merged).replace(/'/g, "'\\''"); // escape single quotes for shell
	wpCli(`option update woocommerce_paygent_cc_settings --format=json '${json}'`);
}

/**
 * Enable EMV 3D Secure (3DS2) for the Paygent CC gateway.
 * Saves existing settings and returns them so the caller can restore later.
 *
 * @returns {Record<string, string>} Previous settings snapshot
 */
function enableTds2() {
	const previous = getPaygentCcSettings();
	updatePaygentCcSettings({ tds2_check: 'yes' });
	return previous;
}

/**
 * Disable EMV 3D Secure (3DS2) for the Paygent CC gateway.
 */
function disableTds2() {
	updatePaygentCcSettings({ tds2_check: 'no' });
}

/**
 * Restore a previously saved settings snapshot (from enableTds2 return value).
 *
 * @param {Record<string, string>} snapshot
 */
function restorePaygentCcSettings(snapshot) {
	const json = JSON.stringify(snapshot).replace(/'/g, "'\\''");
	wpCli(`option update woocommerce_paygent_cc_settings --format=json '${json}'`);
}

/**
 * Create (or find) a Block checkout page and set it as the WooCommerce checkout page.
 * Returns { pageId, blockCheckoutUrl, originalCheckoutPageId } for later restoration.
 *
 * The Block checkout content is the minimal Gutenberg block comment required by
 * WooCommerce to render its Checkout block and all sub-blocks.
 *
 * @param {string} baseURL  e.g. 'http://localhost:8888'
 * @returns {{ pageId: string, blockCheckoutUrl: string, originalCheckoutPageId: string }}
 */
function setupBlockCheckoutPage( baseURL ) {
	// Remember the original checkout page ID.
	const originalCheckoutPageId = wpCli( `option get woocommerce_checkout_page_id` ).trim();

	// Reuse existing page to avoid accumulation across runs.
	const existing = wpCli(
		`post list --post_type=page --name=paygent-block-checkout-e2e --fields=ID --format=csv`
	);
	let pageId = ( existing.match( /^(\d+)$/m ) || [] )[1] || '';

	if ( ! pageId ) {
		const blockContent = [
			'<!-- wp:woocommerce/checkout {"align":"wide"} -->',
			'<div class="wp-block-woocommerce-checkout alignwide is-loading">',
			'<!-- wp:woocommerce/checkout-fields-block -->',
			'<div class="wp-block-woocommerce-checkout-fields-block">',
			'<!-- wp:woocommerce/checkout-express-payment-block --><div class="wp-block-woocommerce-checkout-express-payment-block"></div><!-- /wp:woocommerce/checkout-express-payment-block -->',
			'<!-- wp:woocommerce/checkout-contact-information-block --><div class="wp-block-woocommerce-checkout-contact-information-block"></div><!-- /wp:woocommerce/checkout-contact-information-block -->',
			'<!-- wp:woocommerce/checkout-shipping-address-block --><div class="wp-block-woocommerce-checkout-shipping-address-block"></div><!-- /wp:woocommerce/checkout-shipping-address-block -->',
			'<!-- wp:woocommerce/checkout-billing-address-block --><div class="wp-block-woocommerce-checkout-billing-address-block"></div><!-- /wp:woocommerce/checkout-billing-address-block -->',
			'<!-- wp:woocommerce/checkout-shipping-methods-block --><div class="wp-block-woocommerce-checkout-shipping-methods-block"></div><!-- /wp:woocommerce/checkout-shipping-methods-block -->',
			'<!-- wp:woocommerce/checkout-payment-block --><div class="wp-block-woocommerce-checkout-payment-block"></div><!-- /wp:woocommerce/checkout-payment-block -->',
			'<!-- wp:woocommerce/checkout-order-note-block --><div class="wp-block-woocommerce-checkout-order-note-block"></div><!-- /wp:woocommerce/checkout-order-note-block -->',
			'<!-- wp:woocommerce/checkout-actions-block --><div class="wp-block-woocommerce-checkout-actions-block"></div><!-- /wp:woocommerce/checkout-actions-block -->',
			'</div>',
			'<!-- /wp:woocommerce/checkout-fields-block -->',
			'<!-- wp:woocommerce/checkout-totals-block -->',
			'<div class="wp-block-woocommerce-checkout-totals-block">',
			'<!-- wp:woocommerce/checkout-order-summary-block --><div class="wp-block-woocommerce-checkout-order-summary-block"></div><!-- /wp:woocommerce/checkout-order-summary-block -->',
			'</div>',
			'<!-- /wp:woocommerce/checkout-totals-block -->',
			'</div>',
			'<!-- /wp:woocommerce/checkout -->',
		].join( '' );

		// Escape single quotes for the shell command.
		const escapedContent = blockContent.replace( /'/g, "'\\''" );
		pageId = wpCli(
			`post create --post_type=page --post_status=publish ` +
			`--post_title="Block Checkout (E2E)" --post_name=paygent-block-checkout-e2e ` +
			`--post_content='${ escapedContent }' --porcelain`
		).trim();
	}

	// Point WooCommerce checkout at the new Block checkout page.
	wpCli( `option update woocommerce_checkout_page_id ${ pageId }` );

	const blockCheckoutUrl = `${ baseURL.replace( /\/$/, '' ) }/paygent-block-checkout-e2e/`;
	return { pageId, blockCheckoutUrl, originalCheckoutPageId };
}

/**
 * Restore the WooCommerce checkout page to its original ID and delete the
 * Block checkout test page.
 *
 * @param {string} originalCheckoutPageId
 * @param {string} pageId
 */
function teardownBlockCheckoutPage( originalCheckoutPageId, pageId ) {
	if ( originalCheckoutPageId ) {
		wpCli( `option update woocommerce_checkout_page_id ${ originalCheckoutPageId }` );
	}
	if ( pageId ) {
		wpCli( `post delete ${ pageId } --force` );
	}
}

module.exports = {
	wpCli,
	ensureTestProduct,
	deleteOrders,
	getPaygentCcSettings,
	updatePaygentCcSettings,
	enableTds2,
	disableTds2,
	restorePaygentCcSettings,
	setupBlockCheckoutPage,
	teardownBlockCheckoutPage,
};
