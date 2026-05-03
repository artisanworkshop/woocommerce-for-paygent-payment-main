import { decodeEntities } from '@wordpress/html-entities';

/**
 * Payment method label displayed in the Block checkout payment list.
 *
 * Renders the gateway icon (when icon_url is provided) alongside the title.
 *
 * @param {Object} props
 * @param {Object} props.settings  Data from PHP get_payment_method_data().
 */
const PaymentLabel = ( { settings } ) => {
	const title = decodeEntities( settings?.title || '' );

	return (
		<span className="wc-block-components-payment-method-label wc-paygent-payment-label">
			{ settings?.icon_url && (
				<img
					src={ settings.icon_url }
					alt={ title }
					className="wc-paygent-payment-icon"
					style={ { height: '1.5em', verticalAlign: 'middle', marginRight: '0.5em' } }
				/>
			) }
			{ title }
		</span>
	);
};

export default PaymentLabel;
