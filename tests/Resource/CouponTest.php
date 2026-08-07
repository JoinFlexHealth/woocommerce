<?php
/**
 * Tests for the Coupon resource
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Tests\Resource;

use Flex\Resource\Coupon;
use Flex\Resource\ResourceAction;

/**
 * Tests for the Coupon resource.
 */
class CouponTest extends \WP_UnitTestCase {

	/**
	 * Test from_wc returns a no-op coupon for a variation whose parent product no longer exists.
	 */
	public function test_from_wc_returns_noop_for_orphaned_variation(): void {
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( 999999 );
		$variation->set_regular_price( '20.00' );
		$variation->set_sale_price( '15.00' );
		$variation->save();

		$coupon = Coupon::from_wc( $variation );
		self::assertSame( ResourceAction::NONE, $coupon->needs() );
	}

	/**
	 * Test from_wc returns an actionable coupon for a variation with a valid parent product.
	 */
	public function test_from_wc_works_for_variation_with_valid_parent(): void {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Variable Product' );
		$parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '20.00' );
		$variation->set_sale_price( '15.00' );
		$variation->save();

		\WC_Product_Variable::sync( $parent->get_id() );
		$variation = wc_get_product( $variation->get_id() );
		assert( $variation instanceof \WC_Product );

		$coupon = Coupon::from_wc( $variation );
		self::assertNotSame( ResourceAction::NONE, $coupon->needs() );
	}

	/**
	 * A sale price above the regular price is not a discount: amount_off is clamped to 0
	 * rather than going negative, so it never reaches the API (which 422s a negative
	 * amount_off and drives the WOOCOMMERCE-84 retry storm).
	 */
	public function test_from_wc_clamps_negative_amount_off_to_zero(): void {
		$product = new \WC_Product_Simple();
		$product->set_regular_price( '122.00' );
		$product->set_sale_price( '160.00' );
		$product->save();

		$coupon = Coupon::from_wc( $product );

		self::assertSame( 0, $coupon->amount_off() );
		self::assertSame( ResourceAction::NONE, $coupon->needs() );
	}

	/**
	 * A genuine sale (sale below regular) still yields a positive discount.
	 */
	public function test_from_wc_keeps_positive_amount_off(): void {
		$product = new \WC_Product_Simple();
		$product->set_regular_price( '20.00' );
		$product->set_sale_price( '15.00' );
		$product->save();

		$coupon = Coupon::from_wc( $product );

		self::assertSame( 500, $coupon->amount_off() );
	}
}
