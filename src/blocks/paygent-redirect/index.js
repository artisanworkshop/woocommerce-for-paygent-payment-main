import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
import { PaymentLabel, PaymentDescription } from '../shared/components';

/**
 * Gateway IDs for redirect-type Paygent payment methods.
 * These share a single JS bundle; each is registered only when
 * its settings are present (i.e. the gateway is active in PHP).
 */
const GATEWAY_NAMES = [
	'paygent_atm',
	'paygent_bn',
	'paygent_paypay',
	'paygent_rakutenpay',
];

GATEWAY_NAMES.forEach( ( name ) => {
	const settings = getSetting( `${ name }_data`, null );

	if ( ! settings ) {
		return;
	}

	registerPaymentMethod( {
		name,
		label: <PaymentLabel settings={ settings } />,
		content: <PaymentDescription settings={ settings } />,
		edit: <PaymentDescription settings={ settings } />,
		canMakePayment: () => true,
		ariaLabel: settings.title || name,
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} );
