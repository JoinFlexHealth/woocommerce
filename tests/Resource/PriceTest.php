<?php
/**
 * Tests for the Price resource
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Tests\Resource;

use Flex\Resource\Price;

/**
 * Test the Price::from_wc_item functionality.
 *
 * These tests verify that line item prices are correctly calculated when
 * the actual cart amount differs from the catalog price (e.g., due to add-ons,
 * dynamic pricing, or other modifications).
 */
class PriceTest extends \WP_UnitTestCase {

	/**
	 * Test from_wc_item when line item price matches catalog price.
	 *
	 * When the line item subtotal matches the expected product price * quantity,
	 * the method should return a standard product-based price.
	 */
	public function test_from_wc_item_matching_price(): void {
		// Create a simple product with a $50.00 price.
		$product = new \WC_Product_Simple();
		$product->set_name( 'Test Product' );
		$product->set_regular_price( '50.00' );
		$product->save();

		// Create an order with the product.
		$order   = wc_create_order();
		$item_id = $order->add_product( $product, 1 );
		$order->save();

		// Get the line item.
		$item = $order->get_item( $item_id );

		// Create price from line item.
		$price = Price::from_wc_item( $item );

		// Verify the unit amount matches the catalog price (5000 cents = $50.00).
		$json = $price->jsonSerialize();
		$this->assertSame( 5000, $json['unit_amount'] );
	}

	/**
	 * Test from_wc_item when line item price differs from catalog price.
	 *
	 * When the line item subtotal differs from the expected product price
	 * (e.g., due to add-ons or dynamic pricing), the method should create
	 * an ad-hoc price based on the actual line item amount.
	 */
	public function test_from_wc_item_different_price(): void {
		// Create a simple product with a $50.00 catalog price.
		$product = new \WC_Product_Simple();
		$product->set_name( 'Test Product' );
		$product->set_regular_price( '50.00' );
		$product->save();

		// Create an order with the product.
		$order   = wc_create_order();
		$item_id = $order->add_product( $product, 1 );
		$order->save();

		// Get the line item and modify subtotal to simulate an add-on ($52.95).
		$item = $order->get_item( $item_id );
		$item->set_subtotal( '52.95' );
		$item->save();

		// Create price from line item.
		$price = Price::from_wc_item( $item );

		// Verify the unit amount uses the actual line item price (5295 cents = $52.95).
		$json = $price->jsonSerialize();
		$this->assertSame( 5295, $json['unit_amount'] );

		// Verify the description is set from the line item name.
		$this->assertSame( 'Test Product', $json['description'] );
	}

	/**
	 * Test from_wc_item with quantity > 1 and different total.
	 *
	 * When purchasing multiple units with dynamic pricing, the per-unit
	 * amount should be calculated as total / quantity.
	 */
	public function test_from_wc_item_with_quantity(): void {
		// Create a simple product with a $25.00 catalog price.
		$product = new \WC_Product_Simple();
		$product->set_name( 'Quantity Test Product' );
		$product->set_regular_price( '25.00' );
		$product->save();

		// Create an order with 2 units of the product.
		$order   = wc_create_order();
		$item_id = $order->add_product( $product, 2 );
		$order->save();

		// Get the line item and modify subtotal to simulate dynamic pricing.
		// Expected: $25.00 * 2 = $50.00, but actual is $55.00 (e.g., volume pricing).
		$item = $order->get_item( $item_id );
		$item->set_subtotal( '55.00' );
		$item->save();

		// Create price from line item.
		$price = Price::from_wc_item( $item );

		// Verify the unit amount is $55.00 / 2 = $27.50 per unit (2750 cents).
		$json = $price->jsonSerialize();
		$this->assertSame( 2750, $json['unit_amount'] );
	}

	/**
	 * Test from_wc_item with quantity > 1 and matching total.
	 *
	 * When purchasing multiple units and the total matches expected,
	 * it should return the standard product-based price.
	 */
	public function test_from_wc_item_with_quantity_matching(): void {
		// Create a simple product with a $25.00 catalog price.
		$product = new \WC_Product_Simple();
		$product->set_name( 'Quantity Match Product' );
		$product->set_regular_price( '25.00' );
		$product->save();

		// Create an order with 3 units of the product.
		$order   = wc_create_order();
		$item_id = $order->add_product( $product, 3 );
		$order->save();

		// Get the line item (subtotal should be $75.00 = $25.00 * 3).
		$item = $order->get_item( $item_id );

		// Create price from line item.
		$price = Price::from_wc_item( $item );

		// Verify the unit amount matches the catalog price (2500 cents = $25.00).
		$json = $price->jsonSerialize();
		$this->assertSame( 2500, $json['unit_amount'] );
	}

	/**
	 * Test that dynamic pricing does not inherit the product's stored price ID.
	 *
	 * When a line item has a different amount than the catalog price (e.g., add-ons),
	 * and no ID is passed, the ad-hoc price should NOT use the product's stored ID.
	 * This ensures new checkout sessions create fresh prices in Flex.
	 */
	public function test_from_wc_item_different_price_does_not_use_product_price_id(): void {
		// Create a product with a stored price ID (simulating synced product).
		$product = new \WC_Product_Simple();
		$product->set_name( 'Test Product' );
		$product->set_regular_price( '100.00' );
		$product->update_meta_data( '_wc_flex_price_id', 'fprice_catalog_123' );
		$product->save();

		// Create order with dynamic pricing (add-on makes it $125).
		$order   = wc_create_order();
		$item_id = $order->add_product( $product, 1 );
		$order->save();

		$item = $order->get_item( $item_id );
		$item->set_subtotal( '125.00' );
		$item->save();

		// Create price WITHOUT passing an ID (new order scenario).
		$price = Price::from_wc_item( $item );

		// The price should NOT have the product's stored ID.
		$this->assertNull( $price->id(), 'Dynamic pricing should not inherit product price ID for new orders' );

		// But should have the correct amount.
		$json = $price->jsonSerialize();
		$this->assertSame( 12500, $json['unit_amount'] );
	}

	/**
	 * Test that dynamic pricing preserves stored ID for refunds.
	 *
	 * When processing a refund (non-pending order with stored ID),
	 * the ad-hoc price should preserve the stored ID for Flex reference.
	 */
	public function test_from_wc_item_different_price_preserves_passed_id_for_refunds(): void {
		// Create a product.
		$product = new \WC_Product_Simple();
		$product->set_name( 'Refund Test Product' );
		$product->set_regular_price( '100.00' );
		$product->update_meta_data( '_wc_flex_price_id', 'fprice_catalog_456' );
		$product->save();

		// Create order with dynamic pricing.
		$order   = wc_create_order();
		$item_id = $order->add_product( $product, 1 );
		$order->save();

		$item = $order->get_item( $item_id );
		$item->set_subtotal( '125.00' );
		$item->save();

		// Create price WITH a stored ID (refund scenario).
		$stored_id = 'fprice_stored_at_checkout_789';
		$price     = Price::from_wc_item( $item, $stored_id );

		// The price should have the stored ID (not product's ID).
		$this->assertSame( $stored_id, $price->id(), 'Dynamic pricing should preserve stored ID for refunds' );

		// And the correct amount.
		$json = $price->jsonSerialize();
		$this->assertSame( 12500, $json['unit_amount'] );
	}

	/**
	 * Test that matching amounts still use passed ID for refunds, not product's ID.
	 *
	 * Even when the line item amount matches the catalog price, refunds should
	 * use the stored ID from checkout - the product's price ID may have changed
	 * in Flex since the original purchase.
	 */
	public function test_from_wc_item_matching_price_uses_passed_id_for_refunds(): void {
		// Create a product with a stored price ID.
		$product = new \WC_Product_Simple();
		$product->set_name( 'Matching Price Refund Product' );
		$product->set_regular_price( '100.00' );
		// Simulate product's current price ID (different from the one stored at checkout).
		$product->update_meta_data( '_wc_flex_price_id', 'fprice_current_product_id' );
		$product->save();

		// Create order with matching price (no add-ons).
		$order   = wc_create_order();
		$item_id = $order->add_product( $product, 1 );
		$order->save();

		$item = $order->get_item( $item_id );

		// Create price WITH a stored ID from the original checkout.
		// This simulates a refund where the product's price ID has changed since purchase.
		$stored_id = 'fprice_original_checkout_id';
		$price     = Price::from_wc_item( $item, $stored_id );

		// The price should use the stored ID, NOT the product's current ID.
		$this->assertSame( $stored_id, $price->id(), 'Matching price refunds must use stored ID, not product ID' );

		// Amount should still be correct.
		$json = $price->jsonSerialize();
		$this->assertSame( 10000, $json['unit_amount'] );
	}
}
