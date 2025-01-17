<?php
/**
 * Admin Setting Screen
 *
 * @package Paygent_For_WooCommerce
 */

global $woocommerce;
$this->jp4wc_plugin->show_messages( $this );
?>
<div class="wrap">
	<h2><?php esc_html_e( 'General Setting', 'woocommerce-for-paygent-payment-main' ); ?></h2>
	<div class="wc-paygent-settings metabox-holder">
		<div class="wc-paygent-sidebar">
			<div class="wc-paygent-credits">
				<h3 class="hndle"><?php echo esc_html__( 'WooCommerce Paygent', 'woocommerce-for-paygent-payment-main' ) . ' ' . esc_html( WC_PAYGENT_VERSION ); ?></h3>
				<div class="inside">
					<?php $this->jp4wc_plugin->jp4wc_support_notice( 'https://support.artws.info/forums/forum/wordpress-official/woocommerce-for-paygent-plugin/' ); ?>
					<hr />
					<?php $this->jp4wc_plugin->jp4wc_update_notice(); ?>
					<hr />
					<?php $this->jp4wc_plugin->jp4wc_community_info(); ?>
					<hr />
					<h4 class="inner"><?php echo esc_html__( 'Do you like this plugin?', 'woocommerce-for-paygent-payment-main' ); ?></h4>
					<p class="inner"><a href="https://wordpress.org/support/plugin/woocommerce-for-paygent-payment-main/reviews?rate=5#new-post" target="_blank" title="' . esc_html__( 'Rate it 5', 'woocommerce-for-paygent-payment-main' ) . '"><?php echo esc_html__( 'Rate it 5', 'woocommerce-for-paygent-payment-main' ); ?> </a><?php echo esc_html__( 'on WordPress.org', 'woocommerce-for-paygent-payment-main' ); ?><br />
					</p>
					<hr />
					<p class="wc-paygent-link inner"><?php echo esc_html__( 'Created by', 'woocommerce-for-paygent-payment-main' ); ?> <a href="https://wc.artws.info/?utm_source=jp4wc-settings&utm_medium=link&utm_campaign=created-by" target="_blank" title="Artisan Workshop"><img src="<?php echo esc_url( WC_PAYGENT_PLUGIN_URL ); ?>assets/images/woo-logo.png" title="Artsain Workshop" alt="Artsain Workshop" class="jp4wc-logo" /></a><br />
					<a href="https://docs.artws.info/?utm_source=jp4wc-settings&utm_medium=link&utm_campaign=created-by" target="_blank"><?php echo esc_html__( 'WooCommerce Doc in Japanese', 'woocommerce-for-paygent-payment-main' ); ?></a>
					</p>
				</div>
			</div>
		</div>
		<form id="wc-paygent-setting-form" method="post" action="" enctype="multipart/form-data">
			<div id="main-sortables" class="meta-box-sortables ui-sortable">
<?php
	// Display Setting Screen.
	settings_fields( 'wc_paygent_options' );
	$this->jp4wc_plugin->do_settings_sections( 'wc_paygent_options' );
?>
			<p class="submit">
<?php
	submit_button( '', 'primary', 'save_wc_paygent_options', false );
?>
			</p>
			</div>
		</form>
		<div class="clear"></div>
	</div>
	<script type="text/javascript">
	//<![CDATA[
	jQuery(document).ready( function ($) {
		// close postboxes that should be closed
		$('.if-js-closed').removeClass('if-js-closed').addClass('closed');
		// postboxes setup
		postboxes.add_postbox_toggles('wc_paygent_options');
	});
	//]]>
	</script>
</div>
