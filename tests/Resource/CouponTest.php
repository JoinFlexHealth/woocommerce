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

		$coupon = Coupon::from_wc( $variation );
		self::assertNotSame( ResourceAction::NONE, $coupon->needs() );
	}
}
