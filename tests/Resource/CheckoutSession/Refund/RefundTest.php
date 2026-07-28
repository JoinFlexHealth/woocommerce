<?php
/**
 * Tests for the Refund::from_wc() penny reconciliation.
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Tests\Resource\CheckoutSession\Refund;

use Flex\Resource\CheckoutSession\Refund\LineItem;
use Flex\Resource\CheckoutSession\Refund\Refund;

/**
 * Tests how Refund::from_wc() distributes the reconciliation remainder.
 *
 * The proportional pass rounds every line up with ceil(), so the line totals
 * routinely overshoot the refund amount by a few cents. A trailing loop then
 * walks the lines adding or removing single cents until the sum matches.
 *
 * The order total coming out of that loop is right either way, so a test that
 * only checks the sum cannot see a misallocation. Flex records amount_to_refund
 * per line, and those per-line figures are the HSA/FSA substantiation artifact,
 * so assert the distribution rather than just the total.
 */
class RefundTest extends \WP_UnitTestCase {

	/**
	 * Builds an order of four identically-priced items and refunds it with
	 * per-line amounts that force the reconciliation loop to run more than once.
	 *
	 * Four lines at $1.00 (400 cents) refunded for $4.05 (405 cents):
	 *
	 *   - remainder is +5, so the proportional pass runs
	 *   - each ratio is 100/400 = 0.25, and ceil( 0.25 * 5 ) = 2
	 *   - every line becomes 102, summing to 408
	 *   - the trailing remainder is 405 - 408 = -3, so three cents come back off
	 *
	 * Three cents across four lines is the smallest case that distinguishes a
	 * round-robin walk from one that keeps rewriting the first line.
	 *
	 * @return non-empty-array<int> The per-line amount_to_refund values, in line order.
	 */
	private function refund_line_amounts(): array {
		$order = wc_create_order();
		self::assertInstanceOf( \WC_Order::class, $order );

		$line_items = array();
		for ( $n = 1; $n <= 4; $n++ ) {
			$product = new \WC_Product_Simple();
			$product->set_name( "Item {$n}" );
			$product->set_regular_price( '1.00' );
			$product->set_status( 'publish' );
			$product->save();

			$item_id = $order->add_product( $product, 1 );

			$line_items[ $item_id ] = array(
				'qty'          => 1,
				'refund_total' => '1.00',
			);
		}

		// Shipping lifts the order total to $5.00 so the $4.05 refund is valid --
		// WooCommerce rejects a refund larger than the order total. It also mirrors
		// the real shape of this bug: the refund total covers shipping and tax,
		// while LineItem::from_wc() only ever sees the ex-tax line totals, so the
		// remainder handed to the reconciliation loop is routinely more than a cent.
		$shipping = new \WC_Order_Item_Shipping();
		$shipping->set_method_title( 'Flat Rate' );
		$shipping->set_total( '1.00' );
		$order->add_item( $shipping );

		$order->calculate_totals();
		$order->save();

		$refund = wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => '4.05',
				'line_items' => $line_items,
			)
		);
		self::assertInstanceOf(
			\WC_Order_Refund::class,
			$refund,
			$refund instanceof \WP_Error ? 'wc_create_refund failed: ' . $refund->get_error_message() : '',
		);

		// Read the lines back off the serialized payload rather than adding an
		// accessor to the production class purely for this test. This is the same
		// shape that goes on the wire to Flex.
		$amounts = array_map(
			static fn ( LineItem $line_item ) => $line_item->amount_to_refund(),
			Refund::from_wc( $refund )->jsonSerialize()['line_items'],
		);

		self::assertCount( 4, $amounts, 'Expected one refund line per ordered item.' );

		/**
		 * The assertion above establishes this at runtime; restate it so the
		 * analyser does not treat the callers' max()/min() as possibly-empty.
		 *
		 * @var non-empty-array<int> $amounts
		 */
		return $amounts;
	}

	/**
	 * The reconciled lines must still sum to the refund amount. This held before
	 * the round-robin fix too — it is here so a future change to the distribution
	 * cannot quietly break the total.
	 */
	public function test_reconciled_lines_sum_to_the_refund_amount(): void {
		self::assertSame( 405, array_sum( $this->refund_line_amounts() ) );
	}

	/**
	 * Each of the three cents must come off a different line.
	 *
	 * Without advancing the index the loop rewrites line 0 on every pass, so it
	 * absorbs all three cents and the lines come out as 99/102/102/102 — a line
	 * three cents light and three lines a cent heavy, for a refund that was
	 * evenly split across four identical items.
	 */
	public function test_reconciliation_spreads_across_lines(): void {
		$amounts = $this->refund_line_amounts();
		sort( $amounts );

		self::assertSame( array( 101, 101, 101, 102 ), $amounts );
	}

	/**
	 * No single line may absorb the whole remainder. Stated separately from the
	 * exact distribution so the intent survives a change to the walk order.
	 */
	public function test_no_line_absorbs_the_entire_remainder(): void {
		$amounts = $this->refund_line_amounts();

		self::assertLessThanOrEqual(
			1,
			max( $amounts ) - min( $amounts ),
			'The reconciliation left the per-line amounts more than a cent apart, so one line absorbed the remainder.',
		);
	}
}
