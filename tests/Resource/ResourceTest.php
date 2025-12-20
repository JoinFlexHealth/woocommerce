<?php
/**
 * Tests for the Resource class currency conversion
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Tests\Resource;

use Flex\Resource\Resource;

/**
 * Test the currency conversion functionality.
 *
 * Note: WooCommerce must be loaded for these tests to work since
 * currency_to_unit_amount() calls wc_get_price_decimal_separator() etc.
 */
class ResourceTest extends \WP_UnitTestCase {

	/**
	 * Test conversion with 0 decimal places (ENG-1897).
	 *
	 * This is the bug fix case where WooCommerce is configured to display
	 * 0 decimal places, so prices like $100 are displayed as "100" without
	 * any decimal point.
	 */
	public function test_zero_decimals(): void {
		$this->assertSame( 10000, Resource::currency_to_unit_amount( '100' ) );
		$this->assertSame( 5000, Resource::currency_to_unit_amount( '50' ) );
		$this->assertSame( 250000, Resource::currency_to_unit_amount( '2500' ) );
	}

	/**
	 * Test conversion with standard 2 decimal places.
	 */
	public function test_two_decimals(): void {
		$this->assertSame( 10000, Resource::currency_to_unit_amount( '100.00' ) );
		$this->assertSame( 10099, Resource::currency_to_unit_amount( '100.99' ) );
		$this->assertSame( 10001, Resource::currency_to_unit_amount( '100.01' ) );
		$this->assertSame( 12345, Resource::currency_to_unit_amount( '123.45' ) );
	}

	/**
	 * Test conversion with 1 decimal place.
	 */
	public function test_one_decimal(): void {
		$this->assertSame( 10050, Resource::currency_to_unit_amount( '100.5' ) );
		$this->assertSame( 10090, Resource::currency_to_unit_amount( '100.9' ) );
		$this->assertSame( 10010, Resource::currency_to_unit_amount( '100.1' ) );
	}

	/**
	 * Test conversion with 4+ decimal places (should truncate to 2).
	 */
	public function test_four_decimals_truncates(): void {
		$this->assertSame( 10012, Resource::currency_to_unit_amount( '100.1234' ) );
		$this->assertSame( 10099, Resource::currency_to_unit_amount( '100.9999' ) );
		$this->assertSame( 10056, Resource::currency_to_unit_amount( '100.5678' ) );
	}

	/**
	 * Test conversion with thousand separators.
	 */
	public function test_thousand_separators(): void {
		$this->assertSame( 100000, Resource::currency_to_unit_amount( '1,000.00' ) );
		$this->assertSame( 1000000, Resource::currency_to_unit_amount( '10,000' ) );
		$this->assertSame( 123456789, Resource::currency_to_unit_amount( '1,234,567.89' ) );
		$this->assertSame( 1050, Resource::currency_to_unit_amount( '10.50' ) );
	}

	/**
	 * Test conversion with currency symbols.
	 */
	public function test_currency_symbols(): void {
		$this->assertSame( 10000, Resource::currency_to_unit_amount( '$100.00' ) );
		$this->assertSame( 10000, Resource::currency_to_unit_amount( '$ 100.00' ) );
		$this->assertSame( 10050, Resource::currency_to_unit_amount( '$100.50' ) );
	}

	/**
	 * Test conversion with integer input.
	 */
	public function test_integer_input(): void {
		$this->assertSame( 10000, Resource::currency_to_unit_amount( 100 ) );
		$this->assertSame( 5000, Resource::currency_to_unit_amount( 50 ) );
		$this->assertSame( 100, Resource::currency_to_unit_amount( 1 ) );
	}

	/**
	 * Test conversion with float input.
	 */
	public function test_float_input(): void {
		$this->assertSame( 10000, Resource::currency_to_unit_amount( 100.00 ) );
		$this->assertSame( 10050, Resource::currency_to_unit_amount( 100.50 ) );
		$this->assertSame( 10099, Resource::currency_to_unit_amount( 100.99 ) );
		$this->assertSame( 12345, Resource::currency_to_unit_amount( 123.45 ) );
	}

	/**
	 * Test conversion with negative values (should be converted to positive).
	 */
	public function test_negative_values(): void {
		$this->assertSame( 10000, Resource::currency_to_unit_amount( -100.00 ) );
		$this->assertSame( 10050, Resource::currency_to_unit_amount( '-100.50' ) );
		$this->assertSame( 5000, Resource::currency_to_unit_amount( -50 ) );
	}

	/**
	 * Test edge cases with zero values.
	 */
	public function test_zero_values(): void {
		$this->assertSame( 0, Resource::currency_to_unit_amount( '0' ) );
		$this->assertSame( 0, Resource::currency_to_unit_amount( '0.00' ) );
		$this->assertSame( 0, Resource::currency_to_unit_amount( 0 ) );
		$this->assertSame( 0, Resource::currency_to_unit_amount( 0.00 ) );
	}

	/**
	 * Test edge case with empty string.
	 *
	 * This can occur with WooCommerce products that have no regular price set,
	 * such as free products or gift items. The empty string should be treated
	 * as a zero-value price (0 cents).
	 */
	public function test_empty_string(): void {
		$this->assertSame( 0, Resource::currency_to_unit_amount( '' ) );
	}

	/**
	 * Test edge cases with small values.
	 */
	public function test_small_values(): void {
		$this->assertSame( 1, Resource::currency_to_unit_amount( '0.01' ) );
		$this->assertSame( 99, Resource::currency_to_unit_amount( '0.99' ) );
		$this->assertSame( 50, Resource::currency_to_unit_amount( '0.50' ) );
		$this->assertSame( 5, Resource::currency_to_unit_amount( '0.05' ) );
	}

	/**
	 * Test edge cases with large values.
	 */
	public function test_large_values(): void {
		$this->assertSame( 99999999, Resource::currency_to_unit_amount( '999999.99' ) );
		$this->assertSame( 100000000, Resource::currency_to_unit_amount( '1000000.00' ) );
		$this->assertSame( 100000000, Resource::currency_to_unit_amount( '1,000,000' ) );
	}

	/**
	 * Test the specific bug scenarios.
	 *
	 * The bug: When WooCommerce displays prices with 0 decimals, a $100 item
	 * displayed as "100" was being converted to 100 cents ($1.00) instead of
	 * 10000 cents ($100.00).
	 */
	public function test_zero_decimal_bug_scenario(): void {
		// Main bug case.
		$this->assertSame(
			10000,
			Resource::currency_to_unit_amount( '100' ),
			'Bug: $100 displayed as "100" should convert to 10000 cents, not 100'
		);

		// Other common price points that would be affected.
		$this->assertSame(
			5000,
			Resource::currency_to_unit_amount( '50' ),
			'$50 displayed as "50" should convert to 5000 cents'
		);

		$this->assertSame(
			250000,
			Resource::currency_to_unit_amount( '2500' ),
			'$2500 displayed as "2500" should convert to 250000 cents'
		);

		$this->assertSame(
			9999,
			Resource::currency_to_unit_amount( '99.99' ),
			'$99.99 should still work correctly with decimals'
		);
	}
}
