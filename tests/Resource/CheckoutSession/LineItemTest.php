<?php
/**
 * Tests for the LineItem resource
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Tests\Resource\CheckoutSession;

use Automattic\WooCommerce\Enums\OrderStatus;
use Flex\Resource\CheckoutSession\LineItem;
use Flex\Resource\ResourceAction;

/**
 * Test the LineItem::from_wc functionality.
 */
class LineItemTest extends \WP_UnitTestCase {

	/**
	 * Test that from_wc creates a properly initialized Price for pending orders.
	 *
	 * When the order is PENDING, the price should be calculated fresh from the
	 * product to allow exec() to work properly (no infinite recursion).
	 */
	public function test_from_wc_with_pending_order_creates_full_price(): void {
		// Create a simple product.
		$product = new \WC_Product_Simple();
		$product->set_name( 'Test Product' );
		$product->set_regular_price( '50.00' );
		$product->save();

		// Create a pending order with the product.
		$order   = wc_create_order();
		$item_id = $order->add_product( $product, 1 );
		$order->set_status( OrderStatus::PENDING );
		$order->save();

		// Get the line item.
		$item = $order->get_item( $item_id );

		// Store a price id in metadata to simulate previous checkout attempt.
		$item->update_meta_data( '_wc_flex_line_item_price', 'price_test_456' );
		$item->save();

		// Create LineItem from WC item.
		$line_item = LineItem::from_wc( $item );

		// The price should have a valid needs() that doesn't return DEPENDENCY
		// with an unresolvable product dependency.
		$price       = $line_item->price();
		$price_needs = $price->needs();

		// If needs() returns DEPENDENCY, verify the product can actually be resolved.
		if ( ResourceAction::DEPENDENCY === $price_needs ) {
			// Get the product via reflection since it's protected.
			$reflection  = new \ReflectionClass( $price );
			$property    = $reflection->getProperty( 'product' );
			$product_obj = $property->getValue( $price );

			// The product should have a resolvable needs() - not NONE with null id.
			$product_needs = $product_obj->needs();
			$product_id    = $product_obj->id();

			$this->assertFalse(
				ResourceAction::NONE === $product_needs && null === $product_id,
				'Price has DEPENDENCY but Product cannot be resolved (needs=NONE, id=null). This would cause infinite recursion.'
			);
		}

		// Verify the price has the expected unit amount from the product.
		$json = $price->jsonSerialize();
		$this->assertSame( 5000, $json['unit_amount'], 'Price should have correct unit amount from product' );

		// For pending orders, the stored price ID should NOT be used.
		$this->assertNotSame( 'price_test_456', $price->id(), 'Pending orders should not use stored price ID' );
	}

	/**
	 * Test that from_wc uses stored price ID for non-pending orders (refunds).
	 *
	 * When the order is not PENDING (e.g., PROCESSING), the stored price ID
	 * should be used to ensure refunds reference the correct price.
	 */
	public function test_from_wc_with_processing_order_uses_stored_price_id(): void {
		// Create a simple product.
		$product = new \WC_Product_Simple();
		$product->set_name( 'Refund Test Product' );
		$product->set_regular_price( '75.00' );
		$product->save();

		// Create a processing order (payment was completed).
		$order   = wc_create_order();
		$item_id = $order->add_product( $product, 1 );
		$order->set_status( OrderStatus::PROCESSING );
		$order->save();

		// Get the line item.
		$item = $order->get_item( $item_id );

		// Store the price id that was saved during the original checkout.
		$item->update_meta_data( '_wc_flex_line_item_price', 'price_original_checkout_789' );
		$item->save();

		// Create LineItem from WC item.
		$line_item = LineItem::from_wc( $item );

		// For non-pending orders with stored metadata, the stored price ID should be used.
		$price = $line_item->price();
		$this->assertSame( 'price_original_checkout_789', $price->id(), 'Non-pending orders should use stored price ID for refunds' );

		// The price should still have the correct unit amount.
		$json = $price->jsonSerialize();
		$this->assertSame( 7500, $json['unit_amount'], 'Price should have correct unit amount' );
	}

	/**
	 * Test that from_wc works correctly for a fresh order without stored metadata.
	 */
	public function test_from_wc_without_stored_metadata(): void {
		// Create a simple product.
		$product = new \WC_Product_Simple();
		$product->set_name( 'Fresh Order Product' );
		$product->set_regular_price( '25.00' );
		$product->save();

		// Create an order with the product (no stored metadata).
		$order   = wc_create_order();
		$item_id = $order->add_product( $product, 2 );
		$order->save();

		// Get the line item.
		$item = $order->get_item( $item_id );

		// Create LineItem from WC item.
		$line_item = LineItem::from_wc( $item );

		// Verify quantity is correct.
		$this->assertSame( 2, $line_item->quantity() );

		// Verify price has correct unit amount.
		$json = $line_item->price()->jsonSerialize();
		$this->assertSame( 2500, $json['unit_amount'] );
	}
}
