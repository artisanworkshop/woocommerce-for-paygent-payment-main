// @ts-check

/**
 * Page Object for the WooCommerce checkout page.
 */
class CheckoutPage {
	/** @param {import('@playwright/test').Page} page */
	constructor(page) {
		this.page = page;
		this.baseURL = process.env.E2E_BASE_URL || 'http://localhost:8888';
	}

	async goto() {
		await this.page.goto(`${this.baseURL}/checkout/`);
	}

	/**
	 * Add a product to cart by slug and navigate to checkout.
	 *
	 * @param {string} slug  Product slug
	 */
	async addToCartAndCheckout(productId) {
		// Use product ID (not slug) for the add-to-cart URL.
		await this.page.goto(`${this.baseURL}/?add-to-cart=${productId}`, {
			waitUntil: 'domcontentloaded',
		});
		// Navigate directly to classic checkout (shortcode — Blocks not supported yet).
		await this.page.goto(`${this.baseURL}/checkout/`, { waitUntil: 'domcontentloaded' });
		await this.page.waitForSelector('#customer_details', { timeout: 15_000 });
	}

	/**
	 * Fill billing fields (classic checkout form).
	 *
	 * @param {object} customer
	 */
	async fillBilling(customer) {
		const p = this.page;
		await p.fill('#billing_last_name',  customer.lastName);
		await p.fill('#billing_first_name', customer.firstName);
		await p.fill('#billing_email',      customer.email);
		await p.fill('#billing_phone',      customer.phone);
		await p.fill('#billing_address_1',  customer.address1);
		await p.fill('#billing_city',       customer.city);
		await p.fill('#billing_postcode',   customer.postcode);
	}

	/**
	 * Select a payment gateway by its ID.
	 *
	 * @param {string} gatewayId  e.g. 'paygent_cc'
	 */
	async selectPaymentMethod(gatewayId) {
		const radio = this.page.locator(`#payment_method_${gatewayId}`);
		if (await radio.isVisible()) {
			await radio.check();
		}
	}

	async placeOrder() {
		await this.page.click('#place_order');
	}

	async waitForOrderConfirmation() {
		await this.page.waitForURL(/order-received/, { timeout: 30_000 });
	}

	/**
	 * Return the order ID from the confirmation URL.
	 *
	 * @returns {string|null}
	 */
	getOrderIdFromURL() {
		const match = this.page.url().match(/order-received\/(\d+)/);
		return match ? match[1] : null;
	}
}

module.exports = { CheckoutPage };
