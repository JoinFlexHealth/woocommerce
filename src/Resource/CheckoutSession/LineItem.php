<?php
/**
 * Flex Checkout Session Line Item
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Resource\CheckoutSession;

use Flex\Resource\Price;
use Flex\Resource\Resource;
use Flex\Resource\ResourceAction;

/**
 * Flex Checkout Session Line Item
 */
class LineItem extends Resource {

	protected const KEY_PRICE = 'line_item_price';

	/**
	 * WooCommerce Line Item
	 *
	 * @var ?\WC_Order_Item_Product
	 */
	protected ?\WC_Order_Item_Product $wc = null;

	/**
	 * Creates a checkout session line item
	 *
	 * @param Price   $price The associated Price object.
	 * @param ?string $id The line item id.
	 * @param int     $quantity The quantity of the item.
	 */
	public function __construct(
		protected Price $price = new Price(),
		protected ?string $id = null,
		protected int $quantity = 1,
	) {}

	/**
	 * {@inheritdoc}
	 */
	public function id(): ?string {
		return $this->id;
	}

	/**
	 * Returns the price associated with the line item.
	 */
	public function price(): Price {
		return $this->price;
	}

	/**
	 * Returns the quantity associated with the line item.
	 */
	public function quantity(): int {
		return $this->quantity;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Only serialize properties where WooCommerce is the system of record.
	 */
	public function jsonSerialize(): array {
		return array(
			'quantity' => $this->quantity,
			'price'    => $this->price->id(),
		);
	}

	/**
	 * Create a new Line Item from the Flex API response.
	 *
	 * @param array $line_item A single line item from the API response.
	 */
	public static function from_flex( array $line_item ) {
		return new self(
			id: $line_item['line_item_id'] ?? null,
			price: isset( $line_item['price'] ) ? Price::from_flex( $line_item['price'] ) : null,
			quantity: $line_item['quantity'] ?? null,
		);
	}

	/**
	 * Create a Checkout Session Line Item from a WooCommerce Order Item.
	 *
	 * @param \WC_Order_Item_Product $item The WooCommerce Order Item.
	 */
	public static function from_wc( \WC_Order_Item_Product $item ): self {
		// If the order has a transaction id, the checkout session was completed
		// and the price id is fixed.
		if ( $item->get_order()->get_transaction_id() && $item->meta_exists( self::META_PREFIX . self::KEY_PRICE ) ) {
			$price = new Price( id: $item->get_meta( self::META_PREFIX . self::KEY_PRICE ) );
		} else {
			$price = Price::from_wc_item( $item );
		}

		$line_item = new self(
			price: $price,
			quantity: $item->get_quantity(),
		);

		$line_item->wc = $item;

		return $line_item;
	}

	/**
	 * Applies the product data onto a WooCommerce Line Item.
	 * This is effectively the opposite of {@link LineItem::from_wc}.
	 *
	 * @param \WC_Order_Item_Product $item The WooCommerce Item.
	 */
	public function apply_to( \WC_Order_Item_Product $item ): void {
		if ( null === $this->price->id() ) {
			$item->delete_meta_data( self::META_PREFIX . self::KEY_PRICE );
		} else {
			$item->update_meta_data( self::META_PREFIX . self::KEY_PRICE, $this->price->id() );
		}
	}

	/**
	 * Applies the changes to the underlying WooCommerce Line Item, if there is one.
	 */
	public function apply(): void {
		if ( null === $this->wc ) {
			return;
		}

		$this->apply_to( $this->wc );
		$this->wc->save();
	}

	/**
	 * {@inheritdoc}
	 *
	 * Since this item gets created as part of the checkout session, the only thing that needs to be checked is the
	 * dependencies.
	 */
	public function needs(): ResourceAction {
		// If the line item was not created from WooCommerce, then there is nothing that needs to be done.
		if ( null === $this->wc ) {
			return ResourceAction::NONE;
		}

		if ( ResourceAction::NONE !== $this->price->needs() ) {
			return ResourceAction::DEPENDENCY;
		}

		return ResourceAction::NONE;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param ResourceAction $action The action to check.
	 */
	public function can( ResourceAction $action ): bool {
		return match ( $action ) {
			ResourceAction::DEPENDENCY => true,
			default => false,
		};
	}

	/**
	 * Updates the dependencies if they need updating.
	 *
	 * @param ResourceAction $action The operation to perform.
	 *
	 * @throws \Exception If anything goes wrong.
	 */
	public function exec( ResourceAction $action ): void {
		if ( ! $this->can( $action ) ) {
			return;
		}

		$this->price->exec( $this->price->needs() );
	}

	/**
	 * The Line Item meta are never hidden so we'll add more helpful labels.
	 *
	 * @param string $label The label that was previously created.
	 * @param string $name  The name of the attribute.
	 */
	public static function wc_attribute_label( string $label, string $name ): string {
		return match ( $name ) {
			self::META_PREFIX . self::KEY_PRICE      => __( 'Flex Price ID', 'pay-with-flex' ),
			default => $label,
		};
	}
}
