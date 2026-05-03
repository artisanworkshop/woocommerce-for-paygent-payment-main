import { decodeEntities } from '@wordpress/html-entities';

/**
 * Payment method label displayed in the Block checkout payment list.
 *
 * @param {Object} props
 * @param {Object} props.settings  Data from PHP get_payment_method_data().
 */
const PaymentLabel = ( { settings } ) => {
	const title = decodeEntities( settings?.title || '' );
	return (
		<span className="wc-block-components-payment-method-label">
			{ title }
		</span>
	);
};

export default PaymentLabel;
