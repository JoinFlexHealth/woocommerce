<?php
/**
 * Tests for the Price resource
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Tests\Resource;

use Flex\Resource\Price;
use Flex\Resource\ResourceAction;

/**
 * Tests for the Price resource.
 */
class PriceTest extends \WP_UnitTestCase {

	/**
	 * Price::is_purchasable_unit returns true for purchasable leaves.
	 */
	public function test_is_purchasable_unit_true_for_simple_variation_bundle(): void {
		$simple = new \WC_Product_Simple();
		$simple->set_name( 'Simple' );
		$simple->set_regular_price( '10.00' );
		$simple->set_status( 'publish' );
		$simple->save();
		self::assertTrue( Price::is_purchasable_unit( $simple ) );

		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Variable' );
		$parent->set_status( 'publish' );
		$parent->save();
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '10.00' );
		$variation->set_status( 'publish' );
		$variation->save();
		\WC_Product_Variable::sync( $parent->get_id() );
		$variation = wc_get_product( $variation->get_id() );
		assert( $variation instanceof \WC_Product );
		self::assertTrue( Price::is_purchasable_unit( $variation ) );

		$bundle = new class() extends \WC_Product_Simple {
			/**
			 * Returns the product type slug.
			 *
			 * @return string
			 */
			public function get_type() {
				return 'bundle';
			}
		};
		$bundle->set_name( 'Kit' );
		$bundle->set_regular_price( '0' );
		$bundle->set_status( 'publish' );
		$bundle->save();
		self::assertTrue( Price::is_purchasable_unit( $bundle ) );
	}

	/**
	 * Price::is_purchasable_unit returns false for containers and non-purchasable products.
	 */
	public function test_is_purchasable_unit_false_for_containers_and_external(): void {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Variable' );
		$parent->set_status( 'publish' );
		$parent->save();
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '10.00' );
		$variation->set_status( 'publish' );
		$variation->save();
		$parent = wc_get_product( $parent->get_id() );
		assert( $parent instanceof \WC_Product );
		self::assertFalse( Price::is_purchasable_unit( $parent ) );

		$grouped = new \WC_Product_Grouped();
		$grouped->set_name( 'Grouped' );
		$grouped->set_status( 'publish' );
		$grouped->save();
		self::assertFalse( Price::is_purchasable_unit( $grouped ) );

		$external = new \WC_Product_External();
		$external->set_name( 'External' );
		$external->set_regular_price( '10.00' );
		$external->set_status( 'publish' );
		$external->save();
		self::assertFalse( Price::is_purchasable_unit( $external ) );
	}

	/**
	 * Price::needs() returns NONE for a variable parent (its variations carry prices).
	 *
	 * Without the unit gate, needs() would create a spurious $0 price from the
	 * parent's empty regular price.
	 */
	public function test_needs_none_for_variable_parent(): void {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Variable' );
		$parent->set_status( 'publish' );
		$parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '10.00' );
		$variation->set_status( 'publish' );
		$variation->save();

		$parent = wc_get_product( $parent->get_id() );
		assert( $parent instanceof \WC_Product );
		self::assertSame( ResourceAction::NONE, Price::from_wc( $parent )->needs() );
	}

	/**
	 * Test from_wc returns a no-op price for a variation whose parent product no longer exists.
	 */
	public function test_from_wc_returns_noop_for_orphaned_variation(): void {
		// Create a variation pointing to a non-existent parent.
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( 999999 );
		$variation->set_regular_price( '10.00' );
		$variation->save();
		$variation = wc_get_product( $variation->get_id() );
		assert( $variation instanceof \WC_Product );

		$price = Price::from_wc( $variation );
		self::assertSame( ResourceAction::NONE, $price->needs() );
	}

	/**
	 * Test from_wc returns an actionable price for a variation with a valid parent product.
	 */
	public function test_from_wc_works_for_variation_with_valid_parent(): void {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Variable Product' );
		$parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '25.00' );
		$variation->save();

		\WC_Product_Variable::sync( $parent->get_id() );
		$variation = wc_get_product( $variation->get_id() );
		assert( $variation instanceof \WC_Product );

		$price = Price::from_wc( $variation );
		self::assertNotSame( ResourceAction::NONE, $price->needs() );
	}


	/**
	 * A bundle-type product (no parent) resolves its Flex product to itself, so the
	 * price has a syncable product and reports DEPENDENCY (product not yet created).
	 * If routing wrongly treated it as a child, the product would be empty and
	 * needs() would be NONE.
	 */
	public function test_from_wc_bundle_type_uses_self_as_product(): void {
		$bundle = new class() extends \WC_Product_Simple {
			/**
			 * Returns the product type slug.
			 *
			 * @return string
			 */
			public function get_type() {
				return 'bundle';
			}
		};
		$bundle->set_name( 'Kit' );
		$bundle->set_regular_price( '0' );
		$bundle->set_status( 'publish' );
		$bundle->save();

		self::assertSame( ResourceAction::DEPENDENCY, Price::from_wc( $bundle )->needs() );
	}

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
		$order = wc_create_order();
		self::assertInstanceOf( \WC_Order::class, $order );
		$item_id = $order->add_product( $product, 1 );
		$order->save();

		// Get the line item.
		$item = $order->get_item( $item_id );
		assert( $item instanceof \WC_Order_Item_Product );

		// Create price from line item.
		$price = Price::from_wc_item( $item );

		// Verify the unit amount matches the catalog price (5000 cents = $50.00).
		$json = $price->jsonSerialize();
		self::assertSame( 5000, $json['unit_amount'] );
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
		$order = wc_create_order();
		self::assertInstanceOf( \WC_Order::class, $order );
		$item_id = $order->add_product( $product, 1 );
		$order->save();

		// Get the line item and modify subtotal to simulate an add-on ($52.95).
		$item = $order->get_item( $item_id );
		self::assertInstanceOf( \WC_Order_Item_Product::class, $item );
		$item->set_subtotal( '52.95' );
		$item->save();

		// Create price from line item.
		$price = Price::from_wc_item( $item );

		// Verify the unit amount uses the actual line item price (5295 cents = $52.95).
		$json = $price->jsonSerialize();
		self::assertSame( 5295, $json['unit_amount'] );

		// Verify the description is set from the line item name.
		self::assertSame( 'Test Product', $json['description'] );
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
		$order = wc_create_order();
		self::assertInstanceOf( \WC_Order::class, $order );
		$item_id = $order->add_product( $product, 2 );
		$order->save();

		// Get the line item and modify subtotal to simulate dynamic pricing.
		// Expected: $25.00 * 2 = $50.00, but actual is $55.00 (e.g., volume pricing).
		$item = $order->get_item( $item_id );
		self::assertInstanceOf( \WC_Order_Item_Product::class, $item );
		$item->set_subtotal( '55.00' );
		$item->save();

		// Create price from line item.
		$price = Price::from_wc_item( $item );

		// Verify the unit amount is $55.00 / 2 = $27.50 per unit (2750 cents).
		$json = $price->jsonSerialize();
		self::assertSame( 2750, $json['unit_amount'] );
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
		$order = wc_create_order();
		self::assertInstanceOf( \WC_Order::class, $order );
		$item_id = $order->add_product( $product, 3 );
		$order->save();

		// Get the line item (subtotal should be $75.00 = $25.00 * 3).
		$item = $order->get_item( $item_id );
		assert( $item instanceof \WC_Order_Item_Product );

		// Create price from line item.
		$price = Price::from_wc_item( $item );

		// Verify the unit amount matches the catalog price (2500 cents = $25.00).
		$json = $price->jsonSerialize();
		self::assertSame( 2500, $json['unit_amount'] );
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
		$order = wc_create_order();
		self::assertInstanceOf( \WC_Order::class, $order );
		$item_id = $order->add_product( $product, 1 );
		$order->save();

		$item = $order->get_item( $item_id );
		self::assertInstanceOf( \WC_Order_Item_Product::class, $item );
		$item->set_subtotal( '125.00' );
		$item->save();

		// Create price WITHOUT passing an ID (new order scenario).
		$price = Price::from_wc_item( $item );

		// The price should NOT have the product's stored ID.
		self::assertNull( $price->id(), 'Dynamic pricing should not inherit product price ID for new orders' );

		// But should have the correct amount.
		$json = $price->jsonSerialize();
		self::assertSame( 12500, $json['unit_amount'] );
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
		$order = wc_create_order();
		self::assertInstanceOf( \WC_Order::class, $order );
		$item_id = $order->add_product( $product, 1 );
		$order->save();

		$item = $order->get_item( $item_id );
		self::assertInstanceOf( \WC_Order_Item_Product::class, $item );
		$item->set_subtotal( '125.00' );
		$item->save();

		// Create price WITH a stored ID (refund scenario).
		$stored_id = 'fprice_stored_at_checkout_789';
		$price     = Price::from_wc_item( $item, $stored_id );

		// The price should have the stored ID (not product's ID).
		self::assertSame( $stored_id, $price->id(), 'Dynamic pricing should preserve stored ID for refunds' );

		// And the correct amount.
		$json = $price->jsonSerialize();
		self::assertSame( 12500, $json['unit_amount'] );
	}

	/**
	 * A trashed variation that was previously synced to Flex should return UPDATE
	 * so the deactivation (active: false) is pushed for its price. Hash-gated so
	 * exec(UPDATE) -> apply_to() makes the next sync NONE.
	 */
	public function test_needs_update_to_deactivate_trashed_synced_variation(): void {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Variable Product' );
		$parent->set_status( 'publish' );
		$parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '15.00' );
		$variation->set_status( 'publish' );
		$variation->save();

		\WC_Product_Variable::sync( $parent->get_id() );

		// Reload so is_purchasable() has parent_data populated.
		$variation = wc_get_product( $variation->get_id() );
		assert( $variation instanceof \WC_Product );

		// Seed the "previously synced" state for the price.
		( new \Flex\Resource\Price(
			product: new \Flex\Resource\Product( id: 'fprod_1' ),
			id: 'fprice_1',
			active: true,
			unit_amount: 1500,
		) )->apply_to( $variation );
		$variation->save();

		// Trash the variation and reload.
		$variation->set_status( 'trash' );
		$variation->save();
		$reloaded = wc_get_product( $variation->get_id() );
		assert( $reloaded instanceof \WC_Product );

		self::assertSame( ResourceAction::UPDATE, Price::from_wc( $reloaded )->needs() );
	}

	/**
	 * A previously synced simple product moved to draft or private (unpublished, but
	 * not trashed) is no longer purchasable, so needs() should return UPDATE to push
	 * the deactivation (active: false). Regression for units that unpublish without
	 * being trashed.
	 *
	 * @dataProvider unpublished_status_provider
	 *
	 * @param string $status The non-trash, non-publish status to apply.
	 */
	public function test_needs_update_to_deactivate_unpublished_synced_simple( string $status ): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Simple' );
		$product->set_regular_price( '20.00' );
		$product->set_status( 'publish' );
		$product->save();

		$product = wc_get_product( $product->get_id() );
		assert( $product instanceof \WC_Product );

		// Seed the "previously synced" state for the price (active while published).
		( new \Flex\Resource\Price(
			product: new \Flex\Resource\Product( id: 'fprod_1' ),
			id: 'fprice_1',
			active: true,
			unit_amount: 2000,
		) )->apply_to( $product );
		$product->save();

		// Unpublish (draft/private) and reload.
		$product->set_status( $status );
		$product->save();
		$reloaded = wc_get_product( $product->get_id() );
		assert( $reloaded instanceof \WC_Product );

		self::assertSame( ResourceAction::UPDATE, Price::from_wc( $reloaded )->needs() );
	}

	/**
	 * Non-trash statuses that still make a product unpurchasable.
	 *
	 * @return array<string, array{string}>
	 */
	public static function unpublished_status_provider(): array {
		return array(
			'draft'   => array( 'draft' ),
			'private' => array( 'private' ),
		);
	}

	/**
	 * A trashed variation with no Flex price id (never synced) should return NONE.
	 */
	public function test_needs_none_for_trashed_unsynced_variation(): void {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Variable Parent' );
		$parent->set_status( 'publish' );
		$parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '15.00' );
		$variation->set_status( 'publish' );
		$variation->save();

		\WC_Product_Variable::sync( $parent->get_id() );

		$variation->set_status( 'trash' );
		$variation->save();
		$reloaded = wc_get_product( $variation->get_id() );
		assert( $reloaded instanceof \WC_Product );

		self::assertSame( ResourceAction::NONE, Price::from_wc( $reloaded )->needs() );
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
		$order = wc_create_order();
		self::assertInstanceOf( \WC_Order::class, $order );
		$item_id = $order->add_product( $product, 1 );
		$order->save();

		$item = $order->get_item( $item_id );
		assert( $item instanceof \WC_Order_Item_Product );

		// Create price WITH a stored ID from the original checkout.
		// This simulates a refund where the product's price ID has changed since purchase.
		$stored_id = 'fprice_original_checkout_id';
		$price     = Price::from_wc_item( $item, $stored_id );

		// The price should use the stored ID, NOT the product's current ID.
		self::assertSame( $stored_id, $price->id(), 'Matching price refunds must use stored ID, not product ID' );

		// Amount should still be correct.
		$json = $price->jsonSerialize();
		self::assertSame( 10000, $json['unit_amount'] );
	}
}
