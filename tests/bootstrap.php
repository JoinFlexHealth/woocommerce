<?php
/**
 * PHPUnit bootstrap file for Flex plugin tests
 *
 * @package Flex
 */

declare(strict_types=1);

// Load Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Mock WooCommerce functions for testing.
if ( ! function_exists( 'wc_get_price_decimal_separator' ) ) {
	/**
	 * Mock function for WooCommerce price decimal separator.
	 *
	 * @return string
	 */
	function wc_get_price_decimal_separator(): string {
		return '.';
	}
}

if ( ! function_exists( 'wc_get_price_thousand_separator' ) ) {
	/**
	 * Mock function for WooCommerce price thousand separator.
	 *
	 * @return string
	 */
	function wc_get_price_thousand_separator(): string {
		return ',';
	}
}

if ( ! function_exists( 'get_woocommerce_currency_symbol' ) ) {
	/**
	 * Mock function for WooCommerce currency symbol.
	 *
	 * @return string
	 */
	function get_woocommerce_currency_symbol(): string {
		return '$';
	}
}
