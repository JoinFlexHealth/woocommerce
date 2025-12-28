<?php
/**
 * Tests for the Fee resource
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Tests\Resource\CheckoutSession;

use Flex\Resource\CheckoutSession\CheckoutSession;

/**
 * Test the Fee handling in CheckoutSession.
 */
class FeeTest extends \WP_UnitTestCase {

	/**
	 * Test that fees from WooCommerce orders are converted to an indexed array.
	 *
	 * WooCommerce's get_fees() returns an associative array keyed by item IDs
	 * (e.g., [42 => fee1, 57 => fee2]). The CheckoutSession must convert these
	 * to a sequential indexed array to ensure proper JSON serialization.
	 */
	public function test_from_wc_converts_fees_to_indexed_array(): void {
		// Create a simple product for the order.
		$product = new \WC_Product_Simple();
		$product->set_name( 'Test Product' );
		$product->set_regular_price( '100.00' );
		$product->save();

		// Create an order with the product.
		$order = wc_create_order();
		$order->add_product( $product, 1 );

		// Add multiple fees to the order.
		$fee1 = new \WC_Order_Item_Fee();
		$fee1->set_name( 'Recycling Fee' );
		$fee1->set_amount( '5.00' );
		$fee1->set_total( '5.00' );
		$order->add_item( $fee1 );

		$fee2 = new \WC_Order_Item_Fee();
		$fee2->set_name( 'Processing Fee' );
		$fee2->set_amount( '2.50' );
		$fee2->set_total( '2.50' );
		$order->add_item( $fee2 );

		$order->save();

		// Verify that WooCommerce returns fees with non-sequential keys.
		$wc_fees = $order->get_fees();
		$this->assertCount( 2, $wc_fees, 'Order should have 2 fees' );

		// The keys are item IDs, which are not sequential (e.g., [42, 57]).
		$wc_fee_keys = array_keys( $wc_fees );
		$this->assertNotSame( array( 0, 1 ), $wc_fee_keys, 'WooCommerce fees should have non-sequential item ID keys' );

		// Create CheckoutSession from the order.
		$checkout_session = CheckoutSession::from_wc( $order );

		// Serialize to JSON.
		$json = $checkout_session->jsonSerialize();

		// The fees array must have sequential integer keys.
		$this->assertArrayHasKey( 'fees', $json, 'JSON should contain fees' );
		$this->assertCount( 2, $json['fees'], 'JSON should have 2 fees' );
		$this->assertSame( array( 0, 1 ), array_keys( $json['fees'] ), 'Fees must have sequential integer keys' );

		// Verify fee data is correctly extracted.
		$this->assertSame( 500, $json['fees'][0]->jsonSerialize()['amount'], 'First fee should have correct amount' );
		$this->assertSame( 'Recycling Fee', $json['fees'][0]->jsonSerialize()['name'], 'First fee should have correct name' );
		$this->assertSame( 250, $json['fees'][1]->jsonSerialize()['amount'], 'Second fee should have correct amount' );
		$this->assertSame( 'Processing Fee', $json['fees'][1]->jsonSerialize()['name'], 'Second fee should have correct name' );
	}
}
