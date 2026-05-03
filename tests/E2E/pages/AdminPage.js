// @ts-check

/**
 * Page Object for the WordPress admin area.
 */
class AdminPage {
	/** @param {import('@playwright/test').Page} page */
	constructor(page) {
		this.page = page;
		this.baseURL = process.env.E2E_BASE_URL || 'http://localhost:8888';
	}

	async gotoPaymentSettings() {
		await this.page.goto(`${this.baseURL}/wp-admin/admin.php?page=wc-settings&tab=checkout`);
		await this.page.waitForSelector('.wc-settings-form, .woocommerce-save-button');
	}

	async gotoOrderList() {
		await this.page.goto(`${this.baseURL}/wp-admin/admin.php?page=wc-orders`);
		await this.page.waitForSelector('.wp-list-table, .woocommerce-orders-table');
	}

	/**
	 * Navigate to a specific order detail page.
	 *
	 * @param {string} orderId
	 */
	async gotoOrder(orderId) {
		await this.page.goto(`${this.baseURL}/wp-admin/admin.php?page=wc-orders&action=edit&id=${orderId}`);
		await this.page.waitForSelector('#order_data, .woocommerce-order-data');
	}

	/**
	 * Get the current order status from the admin order page.
	 *
	 * @returns {Promise<string>}
	 */
	async getOrderStatus() {
		const select = this.page.locator('#order_status, select[name="order_status"]');
		return select.inputValue();
	}

	/**
	 * Check if Paygent CC gateway is listed in payment settings.
	 *
	 * @returns {Promise<boolean>}
	 */
	async isPaygentCCVisible() {
		await this.gotoPaymentSettings();
		return this.page.locator('text=paygent_cc, text=Paygent').first().isVisible();
	}
}

module.exports = { AdminPage };
