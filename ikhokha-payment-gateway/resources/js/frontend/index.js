import { __ } from '@wordpress/i18n';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { decodeEntities } from '@wordpress/html-entities';
import { getSetting } from '@woocommerce/settings';

const settings = getSetting( 'ikhokha_data', {} );

const defaultLabel = __(
    'iKhokha Payment Gateway',
    'woo-gutenberg-products-block'
);

const label = decodeEntities( settings.title ) || defaultLabel;
const logoUrl = settings.logo_url;

/**
 * Content component
 */
const Content = () => {
    return decodeEntities( settings.description || '' );
};

/**
 * Label component
 */
const Label = () => {
    return (
        <span style={{ 
            display: 'flex', 
            flexDirection: 'row', 
            flexWrap: 'nowrap', 
            justifyContent: 'center', 
            alignItems: 'center' 
        }}>
            <img 
                src={ logoUrl } 
                alt={ label } 
                style={{ maxHeight: '20px', marginLeft: '0.5em' }} 
            />
            { label }
        </span>
    );
};

/**
 * Payment method config object.
 */
const Config = {
    name: "ikhokha",
    label: <Label />,
    content: <Content />,
    edit: <Content />,
    canMakePayment: () => true,
    ariaLabel: labelText,
    supports: {
        features: settings.supports,
    },
};

registerPaymentMethod( Config );