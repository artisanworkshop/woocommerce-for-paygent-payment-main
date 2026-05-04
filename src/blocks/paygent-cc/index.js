import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
import { decodeEntities } from '@wordpress/html-entities';
import { useState, useEffect } from '@wordpress/element';
import { PaymentLabel, PaymentDescription } from '../shared/components';
import { createCardToken, createCvcToken, detectCardType } from '../shared/utils/tokenize';

const settings = getSetting( 'paygent_cc_data', null );
if ( ! settings ) {
	// Gateway not active — nothing to register.
	// eslint-disable-next-line no-undef
	throw new Error( 'paygent_cc_data not found' );
}

// ─── helpers ─────────────────────────────────────────────────────────────────

/**
 * Build the <select> options for the payment method / installment selector.
 * Returns an array of { value, label } objects matching what PHP process_payment() expects.
 *
 * Non-installment options use the convention {code}9 (e.g. '109', '239', '809').
 * Installment options use the raw count value (e.g. '3', '6', '12').
 */
function buildPaymentOptions( paymentMethods, numberOfPayments ) {
	const options = [];
	for ( const method of paymentMethods ) {
		if ( method.code === '61' ) {
			for ( const count of numberOfPayments ) {
				options.push( { value: String( count ), label: `${ count }回払い` } );
			}
		} else {
			options.push( { value: method.code + '9', label: method.label } );
		}
	}
	return options;
}

// ─── CardForm component ───────────────────────────────────────────────────────

/**
 * Full card entry form for the Block checkout.
 *
 * Handles:
 *   - New card entry (number / expiry / CVC / cardholder name for 3DS2)
 *   - Saved card selection + CVC-only re-entry
 *   - Save-card checkbox
 *   - Installment payment selector
 *   - onPaymentSetup tokenization via PaygentToken.js
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

	const paymentOptions = buildPaymentOptions(
		paymentMethods  || [],
		numberOfPayments || []
	);
	const showPaymentSelector = paymentOptions.length > 1;
	const defaultOption       = paymentOptions[ 0 ]?.value ?? '109';

	useEffect( () => {
		if ( ! paymentOption && paymentOptions.length ) {
			setPaymentOption( paymentOptions[ 0 ].value );
		}
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Register onPaymentSetup handler — re-subscribes when any input changes.
	useEffect( () => {
		const unsubscribe = eventRegistration.onPaymentSetup( async () => {
			const chosenOption = paymentOption || defaultOption;

			try {
				if ( useStored ) {
					// Stored card: only tokenize CVC.
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

				// New card: parse expiry then tokenize.
				const expClean = expiry.replace( /\s/g, '' ).replace( '/', '' );
				const expMonth = expClean.slice( 0, 2 );
				const expYear  = expClean.slice( 2, 4 );

				const [ tokenRes, cvcRes ] = await Promise.all( [
					createCardToken( merchantId, tokenKey, {
						number:   cardNumber,
						expMonth,
						expYear,
						cvc,
					} ),
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
					: 'カードの認証に失敗しました。入力内容をご確認ください。';
				return {
					type:    emitResponse.responseTypes.ERROR,
					message: msg,
				};
			}
		} );

		return unsubscribe;
	}, [
		eventRegistration,
		emitResponse,
		useStored,
		selectedCardId,
		storedCvc,
		cardNumber,
		expiry,
		cvc,
		cardholderName,
		saveCard,
		paymentOption,
		merchantId,
		tokenKey,
		isTds2,
		defaultOption,
	] );

	return (
		<div className="wc-paygent-cc-form">

			{ /* ── Saved cards toggle ── */ }
			{ hasSavedCards && (
				<fieldset className="wc-paygent-stored-card-toggle">
					<label>
						<input
							type="radio"
							name="paygent-use-stored"
							checked={ useStored }
							onChange={ () => setUseStored( true ) }
						/>
						{ ' ' }保存済みクレジットカードを使用する
					</label>
					<label>
						<input
							type="radio"
							name="paygent-use-stored"
							checked={ ! useStored }
							onChange={ () => setUseStored( false ) }
						/>
						{ ' ' }新しいカードを入力する
					</label>
				</fieldset>
			) }

			{ /* ── Stored card section ── */ }
			{ hasSavedCards && useStored && (
				<div className="wc-paygent-stored-card-section">
					<p>
						<label htmlFor="paygent-cc-stored-select">カードを選択</label>
						<select
							id="paygent-cc-stored-select"
							value={ selectedCardId }
							onChange={ ( e ) => setSelectedCardId( e.target.value ) }
						>
							{ savedCards.map( ( card ) => (
								<option key={ card.customerCardId } value={ card.customerCardId }>
									{ card.cardType } ****{ card.last4 } ({ card.expiryMonth }/{ card.expiryYear.slice( -2 ) })
								</option>
							) ) }
						</select>
					</p>
					<p>
						<label htmlFor="paygent-cc-stored-cvc">
							セキュリティコード <span aria-hidden="true">*</span>
						</label>
						<input
							id="paygent-cc-stored-cvc"
							type="tel"
							inputMode="numeric"
							maxLength={ 4 }
							placeholder="CVC"
							value={ storedCvc }
							onChange={ ( e ) => setStoredCvc( e.target.value ) }
							autoComplete="cc-csc"
						/>
					</p>
				</div>
			) }

			{ /* ── New card section ── */ }
			{ ( ! hasSavedCards || ! useStored ) && (
				<div className="wc-paygent-new-card-section">
					<p>
						<label htmlFor="paygent-cc-number">
							カード番号 <span aria-hidden="true">*</span>
						</label>
						<input
							id="paygent-cc-number"
							type="tel"
							inputMode="numeric"
							placeholder="•••• •••• •••• ••••"
							value={ cardNumber }
							onChange={ ( e ) => setCardNumber( e.target.value ) }
							autoComplete="cc-number"
						/>
					</p>
					<p>
						<label htmlFor="paygent-cc-expiry">
							有効期限 (MM/YY) <span aria-hidden="true">*</span>
						</label>
						<input
							id="paygent-cc-expiry"
							type="tel"
							inputMode="numeric"
							placeholder="MM / YY"
							value={ expiry }
							onChange={ ( e ) => setExpiry( e.target.value ) }
							autoComplete="cc-exp"
						/>
					</p>
					<p>
						<label htmlFor="paygent-cc-cvc">
							セキュリティコード <span aria-hidden="true">*</span>
						</label>
						<input
							id="paygent-cc-cvc"
							type="tel"
							inputMode="numeric"
							maxLength={ 4 }
							placeholder="CVC"
							value={ cvc }
							onChange={ ( e ) => setCvc( e.target.value ) }
							autoComplete="cc-csc"
						/>
					</p>
					{ isTds2 && (
						<p>
							<label htmlFor="paygent-cc-cardholder">
								カード名義人（半角ローマ字） <span aria-hidden="true">*</span>
							</label>
							<input
								id="paygent-cc-cardholder"
								type="text"
								pattern="[a-zA-Z\s]+"
								placeholder="TARO YAMADA"
								value={ cardholderName }
								onChange={ ( e ) => setCardholderName( e.target.value ) }
								autoComplete="cc-name"
							/>
						</p>
					) }
					{ enableSaveCard && (
						<p>
							<label>
								<input
									type="checkbox"
									checked={ saveCard }
									onChange={ ( e ) => setSaveCard( e.target.checked ) }
								/>
								{ ' ' }次回以降のお支払いにこのカードを使用する
							</label>
						</p>
					) }
				</div>
			) }

			{ /* ── Payment method selector ── */ }
			{ showPaymentSelector && (
				<p>
					<label htmlFor="paygent-cc-payment-method">支払い方法</label>
					<select
						id="paygent-cc-payment-method"
						value={ paymentOption }
						onChange={ ( e ) => setPaymentOption( e.target.value ) }
					>
						{ paymentOptions.map( ( opt ) => (
							<option key={ opt.value } value={ opt.value }>
								{ opt.label }
							</option>
						) ) }
					</select>
				</p>
			) }
		</div>
	);
};

// ─── register ─────────────────────────────────────────────────────────────────

registerPaymentMethod( {
	name:  'paygent_cc',
	label: <PaymentLabel settings={ settings } />,
	content: (
		<CardForm />
	),
	edit: <PaymentDescription settings={ settings } />,
	canMakePayment: () => true,
	ariaLabel: settings.title || 'クレジットカード',
	supports: {
		features:     settings.supports || [ 'products' ],
		showSavedCards:    settings.enableSaveCard && ( settings.savedCards?.length > 0 ),
		showSaveOption:    settings.enableSaveCard,
	},
} );
