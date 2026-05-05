/**
 * Detect card brand from the first digits of the card number.
 *
 * @param {string} cardNumber Raw card number (may contain spaces).
 * @return {string} Brand slug matching Paygent's card_type values.
 */
export function detectCardType( cardNumber ) {
	const n  = cardNumber.replace( /\s/g, '' );
	const c1 = n.slice( 0, 1 );
	const c2 = n.slice( 0, 2 );

	if ( c1 === '4' ) return 'visa';
	if ( [ '51','52','53','54','55','22','23','24','25','26','27','21','59' ].includes( c2 ) ) return 'mastercard';
	if ( [ '30','36','38','39' ].includes( c2 ) ) return 'diners';
	if ( [ '60','64','65','62' ].includes( c2 ) ) return 'discover';
	if ( [ '34','37' ].includes( c2 ) ) return 'american express';
	if ( [ '35','31' ].includes( c2 ) ) return 'jcb';
	return '';
}

/**
 * Tokenize a full card via PaygentToken.createToken.
 *
 * @param {string} merchantId Paygent merchant ID.
 * @param {string} tokenKey   Paygent token key.
 * @param {Object} cardData   { number, expMonth (MM), expYear (YY), cvc }
 * @return {Promise<Object>} Resolved with PaygentToken response.
 */
export function createCardToken( merchantId, tokenKey, cardData ) {
	return new Promise( ( resolve, reject ) => {
		if ( ! window.PaygentToken ) {
			reject( new Error( 'PaygentToken library not loaded' ) );
			return;
		}
		new window.PaygentToken().createToken(
			merchantId,
			tokenKey,
			{
				card_number:  cardData.number.replace( /\s/g, '' ),
				expire_month: cardData.expMonth,
				expire_year:  cardData.expYear,
				cvc:          cardData.cvc,
			},
			( res ) => {
				if ( res.result === '0000' ) {
					resolve( res );
				} else {
					reject( res );
				}
			}
		);
	} );
}

/**
 * Tokenize a CVC code via PaygentToken.createCvcToken.
 *
 * @param {string} merchantId Paygent merchant ID.
 * @param {string} tokenKey   Paygent token key.
 * @param {string} cvc        CVC / security code.
 * @return {Promise<Object>} Resolved with PaygentToken response.
 */
export function createCvcToken( merchantId, tokenKey, cvc ) {
	return new Promise( ( resolve, reject ) => {
		if ( ! window.PaygentToken ) {
			reject( new Error( 'PaygentToken library not loaded' ) );
			return;
		}
		new window.PaygentToken().createCvcToken(
			merchantId,
			tokenKey,
			{ cvc },
			( res ) => {
				if ( res.result === '0000' ) {
					resolve( res );
				} else {
					reject( res );
				}
			}
		);
	} );
}
