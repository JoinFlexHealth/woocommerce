<?php
/**
 * Flex utility functions.
 *
 * This file contains utility functions that need to be available both
 * in the main plugin and in tests.
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex;

/**
 * Flex Payment Gateway.
 *
 * If WooCommerce has initialized the payment gateways, return that instance, if not, return a new instance.
 */
function payment_gateway(): PaymentGateway {
	if ( did_action( 'wc_payment_gateways_initialized' ) ) {
		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( isset( $gateways['flex'] ) && $gateways['flex'] instanceof PaymentGateway ) {
			return $gateways['flex'];
		}
	}

	return new PaymentGateway( actions: false );
}
