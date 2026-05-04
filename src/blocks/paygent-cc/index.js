import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
import { decodeEntities } from '@wordpress/html-entities';
import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { PaymentLabel, PaymentDescription } from '../shared/components';
import { createCardToken, createCvcToken, detectCardType } from '../shared/utils/tokenize';

const settings = getSetting( 'paygent_cc_data', null );
if ( ! settings ) {
	throw new Error( 'paygent_cc_data not found' );
}

// ─── Input formatters ─────────────────────────────────────────────────────────

/**
 * Format a raw card number string as "XXXX XXXX XXXX XXXX".
 * Strips non-digits and inserts spaces every 4 digits.
 *
 * @param {string} raw
 * @returns {string}
 */
function formatCardNumber( raw ) {
	return raw
		.replace( /\D/g, '' )
		.slice( 0, 16 )
		.replace( /(.{4})(?=.)/g, '$1 ' );
}

/**
 * Format a raw expiry string as "MM / YY".
 * Auto-inserts " / " after the month when a third digit is typed,
 * and removes it cleanly when the user backspaces.
 *
 * @param {string} raw     Current raw value from the input element.
 * @param {string} prev    Previous formatted state value (for delete detection).
 * @returns {string}
 */
function formatExpiry( raw, prev ) {
	const digits      = raw.replace( /\D/g, '' ).slice( 0, 4 );
	const isDeleting  = raw.length < prev.length;

	if ( digits.length > 2 ) {
		return digits.slice( 0, 2 ) + ' / ' + digits.slice( 2 );
	}
	if ( digits.length === 2 && ! isDeleting ) {
		return digits + ' / ';
	}
	return digits;
}

// ─── Build payment-method options list ───────────────────────────────────────

/**
 * Returns an array of { value, label } options for the payment-method selector.
 * Non-installment codes get the convention {code}9 (e.g. "109", "239", "809").
 * Installment codes use the raw count value (e.g. "3", "6", "12").
 *
 * @param {Array<{code: string, label: string}>} paymentMethods
 * @param {string[]} numberOfPayments
 * @returns {Array<{value: string, label: string}>}
 */
function buildPaymentOptions( paymentMethods, numberOfPayments ) {
	const options = [];
	for ( const method of paymentMethods ) {
		if ( method.code === '61' ) {
			for ( const count of numberOfPayments ) {
				/* translators: %d: number of installments */
				options.push( { value: String( count ), label: sprintf( __( '%d installments', 'woocommerce-for-paygent-payment-main' ), count ) } );
			}
		} else {
			options.push( { value: method.code + '9', label: method.label } );
		}
	}
	return options;
}

// ─── CardForm component ───────────────────────────────────────────────────────

/**
 * Paygent CC card entry form rendered inside the WooCommerce Block checkout.
 *
 * Layout:
 *   Row 1 — Card number (full width, monospace)
 *   Row 2 — Expiry (3fr) | CVC (2fr)  — side by side on ≥480 px
 *   Row 3 — Cardholder name (full width, 3DS2 only)
 *   Row 4 — Save-card checkbox (logged-in, feature-enabled)
 *   Row 5 — Payment-method selector (installments, if configured)
 *
 * @param {{ eventRegistration: object, emitResponse: object }} props
 */
const CardForm = ( { eventRegistration, emitResponse } ) => {
	const {
		merchantId,
		tokenKey,
		isTds2,
		enableSaveCard,
		savedCards,
		paymentMethods,
		numberOfPayments,
	} = settings;

	const hasSavedCards = savedCards && savedCards.length > 0;

	const [ useStored,      setUseStored      ] = useState( hasSavedCards );
	const [ selectedCardId, setSelectedCardId ] = useState( savedCards?.[ 0 ]?.customerCardId ?? '' );
	const [ storedCvc,      setStoredCvc      ] = useState( '' );
	const [ cardNumber,     setCardNumber     ] = useState( '' );
	const [ expiry,         setExpiry         ] = useState( '' );
	const [ cvc,            setCvc            ] = useState( '' );
	const [ cardholderName, setCardholderName ] = useState( '' );
	const [ saveCard,       setSaveCard       ] = useState( false );
	const [ paymentOption,  setPaymentOption  ] = useState( '' );

	const paymentOptions      = buildPaymentOptions( paymentMethods || [], numberOfPayments || [] );
	const showPaymentSelector = paymentOptions.length > 1;
	const defaultOption       = paymentOptions[ 0 ]?.value ?? '109';

	useEffect( () => {
		if ( ! paymentOption && paymentOptions.length ) {
			setPaymentOption( paymentOptions[ 0 ].value );
		}
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Re-register on every state change so the closure captures current values.
	useEffect( () => {
		const unsubscribe = eventRegistration.onPaymentSetup( async () => {
			const chosenOption = paymentOption || defaultOption;

			try {
				if ( useStored ) {
					const cvcRes = await createCvcToken( merchantId, tokenKey, storedCvc );
					return {
						type: emitResponse.responseTypes.SUCCESS,
						meta: {
							paymentMethodData: {
								'paygent-use-stored-payment-info': 'yes',
								'stored-info':                     selectedCardId,
								'paygent_cc-token':                '',
								'paygent_cc-cvc_token':            cvcRes.tokenizedCardObject.token,
								'card_type':                       '',
								'paygent_save_card_info':          'no',
								'paygent_cardholder_name':         '',
								'number_of_payments':              chosenOption,
							},
						},
					};
				}

				const expClean = expiry.replace( /\s/g, '' ).replace( '/', '' );
				const expMonth = expClean.slice( 0, 2 );
				const expYear  = expClean.slice( 2, 4 );

				const [ tokenRes, cvcRes ] = await Promise.all( [
					createCardToken( merchantId, tokenKey, { number: cardNumber, expMonth, expYear, cvc } ),
					createCvcToken( merchantId, tokenKey, cvc ),
				] );

				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: {
						paymentMethodData: {
							'paygent-use-stored-payment-info': 'no',
							'paygent_cc-token':               tokenRes.tokenizedCardObject.token,
							'paygent_cc-cvc_token':           cvcRes.tokenizedCardObject.token,
							'card_type':                      detectCardType( cardNumber ),
							'paygent_save_card_info':         saveCard ? 'yes' : 'no',
							'paygent_cardholder_name':        isTds2 ? cardholderName : '',
							'number_of_payments':             chosenOption,
						},
					},
				};
			} catch ( err ) {
				const msg = err?.responseDetail
					? decodeEntities( err.responseDetail )
					: __( 'Card authentication failed. Please check your input.', 'woocommerce-for-paygent-payment-main' );
				return { type: emitResponse.responseTypes.ERROR, message: msg };
			}
		} );

		return unsubscribe;
	}, [
		eventRegistration, emitResponse,
		useStored, selectedCardId, storedCvc,
		cardNumber, expiry, cvc, cardholderName,
		saveCard, paymentOption,
		merchantId, tokenKey, isTds2, defaultOption,
	] );

	// ── Render ─────────────────────────────────────────────────────────────────
	return (
		<div className="wc-paygent-cc-form">

			{ /* ── Saved cards toggle ── */ }
			{ hasSavedCards && (
				<fieldset className="wc-paygent-stored-card-toggle">
					<legend className="screen-reader-text">{ __( 'Card selection', 'woocommerce-for-paygent-payment-main' ) }</legend>
					<label>
						<input
							type="radio"
							name="paygent-use-stored"
							checked={ useStored }
							onChange={ () => setUseStored( true ) }
						/>
						{ __( 'Use saved credit card', 'woocommerce-for-paygent-payment-main' ) }
					</label>
					<label>
						<input
							type="radio"
							name="paygent-use-stored"
							checked={ ! useStored }
							onChange={ () => setUseStored( false ) }
						/>
						{ __( 'Enter a new card', 'woocommerce-for-paygent-payment-main' ) }
					</label>
				</fieldset>
			) }

			{ /* ── Stored card section ── */ }
			{ hasSavedCards && useStored && (
				<div className="wc-paygent-stored-card-section">
					<div className="wc-paygent-cc-field">
						<label className="wc-paygent-cc-label" htmlFor="paygent-cc-stored-select">
							{ __( 'Select card', 'woocommerce-for-paygent-payment-main' ) }
						</label>
						<select
							id="paygent-cc-stored-select"
							className="wc-paygent-cc-select"
							value={ selectedCardId }
							onChange={ ( e ) => setSelectedCardId( e.target.value ) }
						>
							{ savedCards.map( ( card ) => (
								<option key={ card.customerCardId } value={ card.customerCardId }>
									{ card.cardType } ****{ card.last4 }
									{ ' ' }({ card.expiryMonth }/{ card.expiryYear.slice( -2 ) })
								</option>
							) ) }
						</select>
					</div>
					<div className="wc-paygent-cc-field">
						<label className="wc-paygent-cc-label" htmlFor="paygent-cc-stored-cvc">
							{ __( 'Security code', 'woocommerce-for-paygent-payment-main' ) }
							<span className="wc-paygent-cc-label__required" aria-hidden="true">*</span>
						</label>
						<input
							id="paygent-cc-stored-cvc"
							className="wc-paygent-cc-input"
							type="tel"
							inputMode="numeric"
							maxLength={ 4 }
							placeholder="•••"
							value={ storedCvc }
							onChange={ ( e ) => setStoredCvc( e.target.value.replace( /\D/g, '' ).slice( 0, 4 ) ) }
							autoComplete="cc-csc"
							aria-required="true"
							aria-label={ __( 'Security code (3–4 digits on card back)', 'woocommerce-for-paygent-payment-main' ) }
						/>
					</div>
				</div>
			) }

			{ /* ── New card section ── */ }
			{ ( ! hasSavedCards || ! useStored ) && (
				<div className="wc-paygent-new-card-section">

					{ /* Row 1: Card number */ }
					<div className="wc-paygent-cc-field">
						<label className="wc-paygent-cc-label" htmlFor="paygent-cc-number">
							{ __( 'Card number', 'woocommerce-for-paygent-payment-main' ) }
							<span className="wc-paygent-cc-label__required" aria-hidden="true">*</span>
						</label>
						<input
							id="paygent-cc-number"
							className="wc-paygent-cc-input wc-paygent-cc-input--card-number"
							type="tel"
							inputMode="numeric"
							placeholder="0000 0000 0000 0000"
							value={ cardNumber }
							onChange={ ( e ) => setCardNumber( formatCardNumber( e.target.value ) ) }
							autoComplete="cc-number"
							aria-required="true"
							aria-label={ __( 'Credit card number (16 digits)', 'woocommerce-for-paygent-payment-main' ) }
						/>
					</div>

					{ /* Row 2: Expiry + CVC side by side */ }
					<div className="wc-paygent-cc-row wc-paygent-cc-row--exp-cvc">
						<div className="wc-paygent-cc-field">
							<label className="wc-paygent-cc-label" htmlFor="paygent-cc-expiry">
								{ __( 'Expiry date', 'woocommerce-for-paygent-payment-main' ) }
								<span className="wc-paygent-cc-label__required" aria-hidden="true">*</span>
							</label>
							<input
								id="paygent-cc-expiry"
								className="wc-paygent-cc-input"
								type="tel"
								inputMode="numeric"
								placeholder="MM / YY"
								value={ expiry }
								onChange={ ( e ) => setExpiry( formatExpiry( e.target.value, expiry ) ) }
								autoComplete="cc-exp"
								aria-required="true"
								aria-label={ __( 'Expiry date (MM / YY)', 'woocommerce-for-paygent-payment-main' ) }
							/>
						</div>
						<div className="wc-paygent-cc-field">
							<label className="wc-paygent-cc-label" htmlFor="paygent-cc-cvc">
								{ __( 'Security code', 'woocommerce-for-paygent-payment-main' ) }
								<span className="wc-paygent-cc-label__required" aria-hidden="true">*</span>
								{ /* Tooltip-style hint button */ }
								<span
									className="wc-paygent-cc-cvc-hint"
									title={ __( '3–4 digit number on the back of your card', 'woocommerce-for-paygent-payment-main' ) }
									role="img"
									aria-label={ __( 'About security codes: 3–4 digit number on the back of your card', 'woocommerce-for-paygent-payment-main' ) }
								>
									?
								</span>
							</label>
							<input
								id="paygent-cc-cvc"
								className="wc-paygent-cc-input"
								type="tel"
								inputMode="numeric"
								maxLength={ 4 }
								placeholder="•••"
								value={ cvc }
								onChange={ ( e ) => setCvc( e.target.value.replace( /\D/g, '' ).slice( 0, 4 ) ) }
								autoComplete="cc-csc"
								aria-required="true"
								aria-label={ __( 'Security code (3–4 digits on card back)', 'woocommerce-for-paygent-payment-main' ) }
							/>
						</div>
					</div>

					{ /* Row 3: Cardholder name (3DS2 only) */ }
					{ isTds2 && (
						<div className="wc-paygent-cc-field">
							<label className="wc-paygent-cc-label" htmlFor="paygent-cc-cardholder">
								{ __( 'Cardholder name', 'woocommerce-for-paygent-payment-main' ) }
								<span className="wc-paygent-cc-label__hint">{ __( '(Latin alphabet only)', 'woocommerce-for-paygent-payment-main' ) }</span>
								<span className="wc-paygent-cc-label__required" aria-hidden="true">*</span>
							</label>
							<input
								id="paygent-cc-cardholder"
								className="wc-paygent-cc-input"
								type="text"
								placeholder="TARO YAMADA"
								pattern="[a-zA-Z\s]+"
								value={ cardholderName }
								onChange={ ( e ) => setCardholderName( e.target.value.toUpperCase() ) }
								autoComplete="cc-name"
								aria-required="true"
								aria-label={ __( 'Cardholder name (Latin alphabet, as printed on card)', 'woocommerce-for-paygent-payment-main' ) }
							/>
						</div>
					) }

					{ /* Save-card checkbox */ }
					{ enableSaveCard && (
						<div className="wc-paygent-cc-field">
							<label className="wc-paygent-cc-save-label">
								<input
									type="checkbox"
									checked={ saveCard }
									onChange={ ( e ) => setSaveCard( e.target.checked ) }
									aria-label={ __( 'Save this card for future payments', 'woocommerce-for-paygent-payment-main' ) }
								/>
								{ __( 'Save this card for future payments', 'woocommerce-for-paygent-payment-main' ) }
							</label>
						</div>
					) }
				</div>
			) }

			{ /* ── Payment method selector (installments) ── */ }
			{ showPaymentSelector && (
				<div className="wc-paygent-cc-field wc-paygent-cc-field--payment-method">
					<label className="wc-paygent-cc-label" htmlFor="paygent-cc-payment-method">
						{ __( 'Payment method', 'woocommerce-for-paygent-payment-main' ) }
					</label>
					<select
						id="paygent-cc-payment-method"
						className="wc-paygent-cc-select"
						value={ paymentOption }
						onChange={ ( e ) => setPaymentOption( e.target.value ) }
					>
						{ paymentOptions.map( ( opt ) => (
							<option key={ opt.value } value={ opt.value }>
								{ opt.label }
							</option>
						) ) }
					</select>
				</div>
			) }
		</div>
	);
};

// ─── Register ─────────────────────────────────────────────────────────────────

registerPaymentMethod( {
	name:  'paygent_cc',
	label: <PaymentLabel settings={ settings } />,
	content: <CardForm />,
	edit:    <PaymentDescription settings={ settings } />,
	canMakePayment: () => true,
	ariaLabel: settings.title || __( 'Credit card', 'woocommerce-for-paygent-payment-main' ),
	supports: {
		features:       settings.supports || [ 'products' ],
		showSavedCards: settings.enableSaveCard && ( settings.savedCards?.length > 0 ),
		showSaveOption: settings.enableSaveCard,
	},
} );
