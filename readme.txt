=== PAYGENT for WooCommerce ===
Contributors: artisan-workshop-1, shohei.tanaka
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_xclick&business=info@artws.info&item_name=Donation+for+Artisan&currency_code=JPY
Tags: woocommerce, payment Gateway, japan
Requires at least: 5.0
Tested up to: 6.9.0
Stable tag: 2.4.7
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

PAYGENT Payment Gateway plugin provides all popular online payment methods for your Woocommerce webshop in Japan.

== Description ==

By contracting with PAYGENT, this plugin can introduce major payment methods in Japan to WooCommerce.

Note! : When performing a major or other update from version 1.2 to 2.0 or version 2.1 to 2.2, be sure to check the operation on the staging site before using it in the production environment. We cannot guarantee anything if something goes wrong.

1. Credit Payment (include Subscriptions)
2. Convenience Store Payment
3. Multi Currency Credit Card Payment
4. Bank Net Payment
5. ATM Payment
6. Carrier Payment
7. Paidy Payment
8. PayPay Payment
9. Rakuten Payment

== Frequently Asked Questions ==

Q: Do I need anything to use this plugin?<br />
A: Just testing in a test environment requires a contract with Paygent. If you want to check the display, we provide a demo environment. <a herf="https://wc.artws.info/payment-demo-apply/" target="_blank">Please apply from here.</a><br />
<br />
Q: I don't know how to set up. Can you support me?<br />
A: We support with paid support. <a herf="https://wc.artws.info/product/payment-support/" target="_blank">Please use from here if necessary.</a><br />


== Screenshots ==

1. General setting
2. Environmental setting
3. Paygent Payment setting
4. Credit Card setting
5. Convenience Store Payment setting

== Changelog ==

= 2.4.7 - 2026-01-06 =
* Security - Improved IP address acquisition reliability by prioritizing REMOTE_ADDR to prevent IP spoofing. HTTP headers (X-Forwarded-For, X-Real-IP) are now used only as fallback methods.

= 2.4.6 - 2026-01-05 =
* Fixed - Implement countermeasures for double display in 3D Secure redirection.
* Fixed - Fix 3D Secure handling and prevent duplicate actions in Paygent payment gateway.

= 2.4.5 - 2026-01-05 =
* Fixed - Fixed descriptions display.
* Fixed - Mobile Payment Subscriptions HPOS admin screen bugs.
* Fixed - Fixed paygent End Point.

= 2.4.4 - 2025-12-18 =
* Add - Add wordfence-vendor.txt for Security verification.
* Fixed - Convenience Store Payment's Lawson and Ministop text.
* Fixed - Function _load_textdomain_just_in_time bug.
* Fixed - Paidy deprication bug.
* Fixed - Multi Currency Credit Card Payment bugs.

= 2.4.3 - 2025-12-18 =
* Fixed - Minor bug fixes.

= 2.4.2 - 2025-08-06 =
* Fixed - 3DS 2.0 Credit Card bugs.

= 2.4.1 - 2025-07-30 =
* Fixed - Update crt file and pem file bugs.

= 2.4.0 - 2025-07-16 =
* Fixed - Compliant with WordPress PHP coding standards.
* Fixed - Registering a credit card when there is no purchase history.
* Fixed - Endpoint bug fixed.
* Dev - Preparation for checkout & cart block support for the upcoming major version (3.0).

== Upgrade Notice ==

Please do Back Up DB and plugin File, etc.
If you do a major update, be sure to test it on the staging site before applying it to your production environment. Please note that no warranty or support is provided free of charge.