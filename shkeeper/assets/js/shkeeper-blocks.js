const { createElement, useState, useEffect } = window.wp.element;
const { getSetting } = window.wc.wcSettings;
const { decodeEntities } = window.wp.htmlEntities;

const settings = getSetting( 'shkeeper_data', {} );
const labelText = decodeEntities( settings.title ) || 'Crypto (SHKeeper)';
const apiUrl = settings.apiUrl || '/wp-json/shkeeper/v1/cryptos';
const apiNonce = settings.nonce || '';
const iconUrl = settings.iconUrl || '';

const i18n = settings.i18n || {
    loading: '🔄 Loading cryptocurrencies...',
    unavailable: 'Payment service is temporarily unavailable.',
    select_crypto: '--- Select cryptocurrency ---',
    validation_msg: 'Please select a cryptocurrency for payment.'
};

const Label = () => {
    return createElement( 'span', { style: { display: 'flex', alignItems: 'center', gap: '8px' } },
        iconUrl ? createElement( 'img', {
            src: iconUrl,
            alt: 'SHKeeper',
            style: { height: '24px', width: 'auto', objectFit: 'contain' }
        } ) : null
    );
};

const Content = ( props ) => {
    const [ selected, setSelected ] = useState( '' );
    const [ cryptos, setCryptos ] = useState( {} );
    const [ isLoading, setIsLoading ] = useState( true );
    const { eventRegistration } = props;

    useEffect( () => {
        fetch( apiUrl, {
            method: 'GET',
            headers: {
                'X-WP-Nonce': apiNonce,
                'Content-Type': 'application/json'
            }
        } )
            .then( response => {
                if ( ! response.ok ) throw new Error( 'Network response was not ok' );
                return response.json();
            } )
            .then( data => {
                const cryptoOptions = {};
                if ( Array.isArray( data ) ) {
                    data.forEach( crypto => {
                        cryptoOptions[ crypto.name ] = crypto.display_name;
                    });
                }
                setCryptos( cryptoOptions );
                setIsLoading( false );
            } )
            .catch( error => {
                console.error( 'Error:', error );
                setIsLoading( false );
            } );
    }, [] );

    useEffect( () => {
        const unsubscribe = eventRegistration.onPaymentProcessing( () => {
            if ( ! selected ) {
                return {
                    type: 'error',
                    message: i18n.validation_msg
                };
            }
            return {
                type: 'success',
                meta: {
                    paymentMethodData: {
                        shkeeper_crypto_curr: selected
                    }
                }
            };
        } );
        return () => unsubscribe();
    }, [ selected, eventRegistration ] );

    const onChange = ( e ) => setSelected( e.target.value );

    if ( isLoading ) {
        return createElement( 'div', { style: { padding: '10px 0', color: '#666' } },
            i18n.loading
        );
    }

    if ( Object.keys( cryptos ).length === 0 ) {
        return createElement( 'div', { style: { color: '#b22222', marginTop: '10px' } },
            i18n.unavailable
        );
    }

    return createElement( 'div', { style: { marginTop: '10px' } },
        createElement( 'p', null, decodeEntities( settings.description || '' ) ),
        createElement( 'select', {
                value: selected,
                onChange: onChange,
                style: { width: '100%', padding: '8px', borderRadius: '4px' }
            },
            createElement( 'option', { value: '' }, i18n.select_crypto ),
            Object.entries( cryptos ).map( ( [ name, displayName ] ) =>
                createElement( 'option', { key: name, value: name }, displayName )
            )
        )
    );
};

const options = {
    name: 'shkeeper',
    label: createElement( Label ),
    content: createElement( Content ),
    edit: createElement( Content ),
    canMakePayment: () => true,
    ariaLabel: labelText,
    supports: {
        features: settings.supports || ['products'],
    },
};

window.wc.wcBlocksRegistry.registerPaymentMethod( options );