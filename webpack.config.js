const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const WooCommerceDepExtractionPlugin = require( '@woocommerce/dependency-extraction-webpack-plugin' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		// Branch 1: Redirect gateways (ATM, BN, PayPay, Rakuten Pay)
		'paygent-redirect': path.resolve( __dirname, 'src/blocks/paygent-redirect/index.js' ),
		// Branch 2: Credit card (CC + Addon_CC)
		'paygent-cc': path.resolve( __dirname, 'src/blocks/paygent-cc/index.js' ),
		// Branch 3: Select gateways (CS, MB, Paidy, MCCC)
		// 'paygent-cs':    path.resolve( __dirname, 'src/blocks/paygent-cs/index.js' ),
		// 'paygent-mb':    path.resolve( __dirname, 'src/blocks/paygent-mb/index.js' ),
		// 'paygent-paidy': path.resolve( __dirname, 'src/blocks/paygent-paidy/index.js' ),
		// 'paygent-mccc':  path.resolve( __dirname, 'src/blocks/paygent-mccc/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
	},
	plugins: [
		// Replace the default WordPress DependencyExtractionWebpackPlugin with
		// WooCommerce's version, which additionally externalises @woocommerce/* packages.
		...defaultConfig.plugins.filter(
			( plugin ) =>
				plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
		),
		new WooCommerceDepExtractionPlugin(),
	],
};
