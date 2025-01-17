<?php
/**
 * Admin Info Screen
 *
 * This file contains the HTML for the admin info screen.
 *
 * @package Paygent_For_WooCommerce
 */

?>
<h3><?php esc_html_e( 'Infomation of Japanese Support', 'woocommerce-for-paygent-payment-main' ); ?></h3>
<p><b>Sorry, Japanese Only</b></p>
<div>
<p>
	こちらでは、WooCommerce For Paygentのサポート情報や告知等を定期的に行なわせて頂きます。
</p>
<p>
	準備中。
</p>
<p>
	<?php
	// translators: %s: framework version.
	printf( esc_html__( 'This Plugin\'s framework is %s.', 'woocommerce-for-paygent-payment-main' ), esc_html( WC_PAYGENT_FRAMEWORK_VERSION ) );
	?>
</p>
</div>
