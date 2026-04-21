// @ts-check
const { test: base } = require('@playwright/test');

/**
 * Shared fixture data for E2E tests.
 */
const TEST_PRODUCT = {
	slug: 'paygent-e2e-test-product',
	name: 'Paygent E2E Test Product',
	price: 1000,
};

const GUEST_CUSTOMER = {
	firstName: 'テスト',
	lastName:  '太郎',
	email:     'e2e-test@example.com',
	phone:     '0312345678',
	address1:  '千代田区1-1-1',
	city:      '東京都',
	postcode:  '1000001',
	country:   'JP',
};

/**
 * Extended test with shared fixtures.
 * Usage: const { test, expect } = require('../fixtures');
 */
const test = base.extend({
	testProduct: async ({}, use) => {
		await use(TEST_PRODUCT);
	},
	guestCustomer: async ({}, use) => {
		await use(GUEST_CUSTOMER);
	},
});

module.exports = { test, expect: base.expect, TEST_PRODUCT, GUEST_CUSTOMER };
