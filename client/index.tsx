/**
 * External dependencies
 */
// @ts-expect-error The import only exists as a global. See https://www.npmjs.com/package/@woocommerce/dependency-extraction-webpack-plugin
import { registerPaymentMethod } from '@woocommerce/blocks-registry'; // eslint-disable-line import/no-unresolved, @woocommerce/dependency-group

const { __ } = wp.i18n;

function Content() {
	return __( 'Pay with HSA/FSA', 'pay-with-flex' );
}

registerPaymentMethod( {
	name: 'flex',
	label: __( 'Flex', 'pay-with-flex' ),
	ariaLabel: __( 'Flex | Pay with HSA/FSA', 'pay-with-flex' ),
	content: <Content />,
	edit: <Content />,
	canMakePayment: () => true,
	placeOrderButtonLabel: __( 'Continue', 'pay-with-flex' ),
} );
