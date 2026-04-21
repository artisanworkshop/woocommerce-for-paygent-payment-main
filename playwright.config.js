// @ts-check
const { defineConfig, devices } = require('@playwright/test');

/**
 * Playwright configuration for Paygent for WooCommerce E2E tests.
 *
 * Targets the wp-env development environment at http://localhost:8888.
 *
 * Run:
 *   npx playwright test               # all E2E tests
 *   npx playwright test --headed      # with browser visible
 *   npx playwright test --debug       # step-by-step debug
 *   npx playwright test tests/E2E/checkout.spec.js
 *
 * Setup (first time):
 *   npm run e2e:setup                 # configure WooCommerce + Paygent in wp-env
 */

module.exports = defineConfig({
	testDir: './tests/E2E',
	testMatch: '**/*.spec.js',

	/* Runs once before all tests: WP-CLI config + admin auth session. */
	globalSetup: './tests/E2E/setup/global.setup.js',

	/* Maximum time one test can run. */
	timeout: 120_000,

	/* Maximum time for the whole test run. */
	globalTimeout: 600_000,

	/* Retry failed tests once in CI. */
	retries: process.env.CI ? 1 : 0,

	/* Workers: 1 to avoid WooCommerce session conflicts. */
	workers: 1,

	reporter: [
		['list'],
		['html', { outputFolder: 'playwright-report', open: 'never' }],
	],

	use: {
		/* Base URL for wp-env. */
		baseURL: process.env.E2E_BASE_URL || 'http://localhost:8888',

		/* Keep screenshots on failure. */
		screenshot: 'only-on-failure',

		/* Keep video on failure (CI). */
		video: process.env.CI ? 'retain-on-failure' : 'off',

		/* Keep trace on failure (CI). */
		trace: process.env.CI ? 'retain-on-failure' : 'off',

		/* Locale for date/number formatting. */
		locale: 'ja-JP',
		timezoneId: 'Asia/Tokyo',
	},

	projects: [
		/* Authenticated tests: reuse admin session saved by globalSetup. */
		{
			name: 'e2e',
			testMatch: /(?<!\.guest)\.spec\.js$/,
			use: {
				...devices['Desktop Chrome'],
				storageState: 'tests/E2E/.auth/admin.json',
			},
		},

		/* Guest checkout tests: no auth state. */
		{
			name: 'e2e-guest',
			testMatch: /\.guest\.spec\.js$/,
			use: {
				...devices['Desktop Chrome'],
			},
		},

		/* Logged-in member tests: stored card, amount correction. */
		{
			name: 'e2e-member',
			testMatch: /\.member\.spec\.js$/,
			use: {
				...devices['Desktop Chrome'],
				storageState: 'tests/E2E/.auth/member.json',
			},
		},
	],
});
