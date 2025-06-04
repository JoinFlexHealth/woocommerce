<?php
/**
 * Flex Checkout Session Line Item
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Resource\CheckoutSession\Refund;

use Flex\Resource\CheckoutSession\LineItem as CheckoutSessionLineItem;
use Flex\Resource\Price;

/**
 * Flex Checkout Session Line Item
 */
class LineItem implements \JsonSerializable {

	/**
	 * WooCommerce Line Item
	 *
	 * @var ?\WC_Order_Item_Product
	 */
	protected ?\WC_Order_Item_Product $wc = null;

	/**
	 * Creates a refund line item
	 *
	 * @param Price $price The associated Price object.
	 * @param int   $amount_to_refund The quantity of the item.
	 */
	public function __construct(
		protected Price $price,
		protected int $amount_to_refund,
	) {}

	/**
	 * Returns the price associated with the line item.
	 */
	public function price(): Price {
		return $this->price;
	}

	/**
	 * Returns the amount to be refunded.
	 */
	public function amount_to_refund(): int {
		return $this->amount_to_refund;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Only serialize properties where WooCommerce is the system of record.
	 */
	public function jsonSerialize(): array {
		return array(
			'price'            => $this->price->id(),
			'amount_to_refund' => $this->amount_to_refund,
		);
	}

	/**
	 * Create a Checkout Session Line Item from a WooCommerce Order Item.
	 *
	 * @param \WC_Order_Item_Product $item The WooCommerce Order Item.
	 */
	public static function from_wc( \WC_Order_Item_Product $item ): self {
		$order        = wc_get_order( $item->get_order()->get_parent_id() );
		$cs_line_item = CheckoutSessionLineItem::from_wc( $order->get_item( $item->get_meta( '_refunded_item_id' ) ) );

		$line_item = new self(
			price: $cs_line_item->price(),
			amount_to_refund: Refund::currency_to_unit_amount( $item->get_total() ),
		);

		$line_item->wc = $item;

		return $line_item;
	}
}
