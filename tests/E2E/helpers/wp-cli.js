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

module.exports = {
	wpCli,
	ensureTestProduct,
	deleteOrders,
	getPaygentCcSettings,
	updatePaygentCcSettings,
	enableTds2,
	disableTds2,
	restorePaygentCcSettings,
};
