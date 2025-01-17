<?php
/**
 * Admin screen for WooCommerce Paygent Payment
 *
 * @package Paygent_For_WooCommerce
 */

?>
<div class="wrap woocommerce">
	<h2 class="nav-tab-wrapper woo-nav-tab-wrapper">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=jp4wc-paygent-output' ) ); ?>" class="nav-tab <?php echo ( 'setting' === $tab ) ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( __( 'Setting', 'woocommerce-for-paygent-payment-main' ) ); ?></a><a href="<?php echo esc_url( admin_url( 'admin.php?page=jp4wc-paygent-output&tab=info' ) ); ?>" class="nav-tab <?php echo ( 'info' === $tab ) ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( __( 'Infomations', 'woocommerce-for-paygent-payment-main' ) ); ?></a>
	</h2>
	<?php
	switch ( $tab ) {
		case 'setting':
			$this->admin_paygent_setting_page();
			break;
		default:
			$this->admin_paygent_info_page();
			break;
	}
	?>
</div>
