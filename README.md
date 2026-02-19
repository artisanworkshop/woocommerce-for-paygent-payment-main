# PAYGENT for WooCommerce

PAYGENT Payment Gateway plugin provides all popular online payment methods for your WooCommerce webshop in Japan.

## Description

PAYGENTと契約することで、日本の主要な決済方法をWooCommerceに導入できるプラグインです。

## Supported Payment Methods

| # | Payment Method | Subscriptions |
| --- | --- | :---: |
| 1 | Credit Card Payment (VISA, MASTER, AMEX, Diners, JCB) | Yes |
| 2 | Convenience Store Payment (Seven Eleven, Lawson, Ministop, FamilyMart) | - |
| 3 | Multi Currency Credit Card Payment (23 currencies) | - |
| 4 | Bank Net Payment | - |
| 5 | ATM Payment | - |
| 6 | Carrier Payment (docomo, SoftBank, au) | Yes |
| 7 | Paidy Payment | - |
| 8 | PayPay Payment | - |
| 9 | Rakuten Payment | - |

## Requirements

| Requirement | Version |
| --- | --- |
| PHP | >= 7.4 |
| WordPress | >= 5.0 |
| WooCommerce | >= 8.0.0 |

## Features

- 3D Secure 2.0 authentication for credit card payments
- WooCommerce Subscriptions support (Credit Card / Carrier Payment)
- High-Performance Order Storage (HPOS) compatible
- Multi-currency support (23 currencies including USD, EUR, GBP, KRW, CNY, etc.)
- Webhook endpoint for payment status notifications
- Production / Test / Sandbox environment switching
- Card expiry notification

## Installation

1. Upload the plugin files to the `/wp-content/plugins/woocommerce-for-paygent-payment-main` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to WooCommerce > Paygent Setting to configure the plugin.

**Note:** A contract with PAYGENT is required to use this plugin. If you want to check the display, a demo environment is available. [Please apply from here.](https://wc.artws.info/payment-demo-apply/)

## FAQ

**Q: Do I need anything to use this plugin?**
A: Testing in a test environment requires a contract with Paygent. If you want to check the display, we provide a [demo environment](https://wc.artws.info/payment-demo-apply/).

**Q: I don't know how to set up. Can you support me?**
A: We provide [paid support](https://wc.artws.info/product/payment-support/).

## Screenshots

1. General setting
2. Environmental setting
3. Paygent Payment setting
4. Credit Card setting
5. Convenience Store Payment setting

## Upgrade Notice

Please back up your database and plugin files before updating. If you do a major update, be sure to test it on a staging site before applying it to your production environment.

## Changelog

### 2.4.8 - 2026-02-05

- Fixed - Refactor next payment date calculation for subscriptions to use a dedicated method.
- Fixed - Handle expired authorization status in Paygent webhook response.
- Fixed - Add allowed redirect hosts for Paygent payment gateway and improve IP address permission checks.
- Fixed - Enhance error handling by including detailed response information in order notes.

### 2.4.7 - 2026-01-06

- Security - Improved IP address acquisition reliability by prioritizing REMOTE_ADDR to prevent IP spoofing.

### 2.4.6 - 2026-01-05

- Fixed - Implement countermeasures for double display in 3D Secure redirection.
- Fixed - Fix 3D Secure handling and prevent duplicate actions in Paygent payment gateway.

### 2.4.5 - 2026-01-05

- Fixed - Fixed descriptions display.
- Fixed - Mobile Payment Subscriptions HPOS admin screen bugs.
- Fixed - Fixed Paygent endpoint.

### 2.4.4 - 2025-12-18

- Add - Add wordfence-vendor.txt for Security verification.
- Fixed - Convenience Store Payment's Lawson and Ministop text.
- Fixed - Function _load_textdomain_just_in_time bug.
- Fixed - Paidy deprecation bug.
- Fixed - Multi Currency Credit Card Payment bugs.

### 2.4.3 - 2025-12-18

- Fixed - Minor bug fixes.

### 2.4.2 - 2025-08-06

- Fixed - 3DS 2.0 Credit Card bugs.

### 2.4.1 - 2025-07-30

- Fixed - Update crt file and pem file bugs.

### 2.4.0 - 2025-07-16

- Fixed - Compliant with WordPress PHP coding standards.
- Fixed - Registering a credit card when there is no purchase history.
- Fixed - Endpoint bug fixed.
- Dev - Preparation for checkout & cart block support for the upcoming major version (3.0).

## License

[GPLv3](http://www.gnu.org/licenses/gpl-3.0.html)

## Author

[Artisan Workshop](https://wc.artws.info/)
