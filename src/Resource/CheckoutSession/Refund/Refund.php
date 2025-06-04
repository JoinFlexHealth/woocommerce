<?php
/**
 * Flex Checkout Session
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Resource\CheckoutSession\Refund;

use Flex\Exception\FlexException;
use Flex\Resource\CheckoutSession\LineItem as CheckoutSessionLineItem;
use Flex\Resource\Resource;
use Flex\Resource\ResourceAction;

/**
 * Flex Checkout Session
 */
class Refund extends Resource {

	/**
	 * Line Items
	 *
	 * @var LineItem[]
	 */
	protected array $line_items;

	/**
	 * WooCommerce Order Refund
	 *
	 * @var ?\WC_Order_Refund
	 */
	protected ?\WC_Order_Refund $wc = null;

	/**
	 * Creates a checkout session refund.
	 *
	 * @param string                $id The checkout session id.
	 * @param LineItem[]            $line_items The line items for the checkout session.
	 * @param ?array<string,string> $refund_metadata Metadata to attach to the refund.
	 * @throws \LogicException If the line items contain something other than a LineItem.
	 */
	public function __construct(
		protected string $id,
		array $line_items,
		protected ?array $refund_metadata = null,
	) {
		if ( ! array_all( $line_items, fn ( $item ) => $item instanceof LineItem ) ) {
			throw new \LogicException( 'Refund::$line_items may only contain instances of LineItem' );
		}
		$this->line_items = $line_items;
	}

	/**
	 * {@inheritdoc}
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Only serialize properties where WooCommerce is the system of record.
	 */
	public function jsonSerialize(): array {
		$data = array(
			'line_items' => $this->line_items,
		);

		if ( null !== $this->refund_metadata ) {
			$data['refund_metadata'] = $this->refund_metadata;
		}

		return $data;
	}

	/**
	 * Creates a Checkout Session Refund from a WooCommerce Refund.
	 *
	 * @param \WC_Order_Refund $refund the WooCommerce Refund.
	 * @throws FlexException If no line items can be established.
	 */
	public static function from_wc( \WC_Order_Refund $refund ): self {
		$refund_amount = self::currency_to_unit_amount( $refund->get_total() );
		$order         = wc_get_order( $refund->get_parent_id() );

		$cs_refund = new self(
			id: $order->get_transaction_id(),
			line_items: array_map( fn( $item ) => LineItem::from_wc( $item ), array_values( $refund->get_items() ) ),
			refund_metadata: array( 'refund_id' => (string) $refund->get_id() ),
		);

		$cs_refund->wc = $refund;

		if ( empty( $cs_refund->line_items ) ) {
			// If the line items are empty, then the user only specified an amount, not a line item amount,
			// therefore, we will spread the amount proportionally against all of the items.
			$order_total = self::currency_to_unit_amount( $order->get_total() );

			$cs_refund->line_items = array_map(
				function ( \WC_Order_Item_Product $item ) use ( $refund_amount, $order_total ) {
					$ratio        = self::currency_to_unit_amount( $item->get_total() ) / $order_total;
					$cs_line_item = CheckoutSessionLineItem::from_wc( $item );
					return new LineItem(
						price: $cs_line_item->price(),
						amount_to_refund: intval( ceil( $ratio * $refund_amount ) ),
					);
				},
				array_values( $order->get_items() )
			);
		} else {
			// Determine if the line item total, specified by the user, adds up. If there is some left, spread it proportionally
			// onto the items being refunded.
			$line_item_total = array_reduce( $cs_refund->line_items, fn ( $acc, $li ) => $acc + $li->amount_to_refund(), 0 );
			$remainder       = $refund_amount - $line_item_total;

			if ( $remainder > 0 ) {
				$cs_refund->line_items = array_map(
					function ( $li ) use ( $line_item_total, $remainder ) {
						$ratio = $li->amount_to_refund() / $line_item_total;
						return new LineItem(
							price: $li->price(),
							amount_to_refund: $li->amount_to_refund() + intval( ceil( $ratio * $remainder ) ),
						);
					},
					$cs_refund->line_items,
				);
			}
		}

		if ( empty( $cs_refund->line_items ) ) {
			throw new FlexException( 'Refunds can only be made against line items' );
		}

		// Now that we have all of the line items proportionally setup, we need to deal with any rounding errors by
		// reducing/adding each item by a penny until we get to the correct amount.
		$remainder = $refund_amount - array_reduce( $cs_refund->line_items, fn ( $acc, $li ) => $acc + $li->amount_to_refund(), 0 );

		$i = 0;
		while ( $remainder < 0 ) {
			if ( ! isset( $cs_refund->line_items[ $i ] ) ) {
				$i = 0;
				continue;
			}

			$cs_refund->line_items[ $i ] = new LineItem(
				price: $cs_refund->line_items[ $i ]->price(),
				amount_to_refund: $cs_refund->line_items[ $i ]->amount_to_refund() - 1,
			);

			++$remainder;
		}

		$i = 0;
		while ( $remainder > 0 ) {
			if ( ! isset( $cs_refund->line_items[ $i ] ) ) {
				$i = 0;
				continue;
			}

			$cs_refund->line_items[ $i ] = new LineItem(
				price: $cs_refund->line_items[ $i ]->price(),
				amount_to_refund: $cs_refund->line_items[ $i ]->amount_to_refund() + 1,
			);

			--$remainder;
		}

		return $cs_refund;
	}

	/**
	 * Extract the values from a checkout session API response.
	 *
	 * @param array $checkout_session The checkout session object returned from the API.
	 *
	 * @throws \Exception If data is missing.
	 */
	protected function extract( array $checkout_session ): void {
		$this->id = $checkout_session['checkout_session_id'] ?? $this->id;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param ResourceAction $action The action to check.
	 */
	public function can( ResourceAction $action ): bool {
		return match ( $action ) {
			ResourceAction::CREATE => ! empty( $this->line_items ),
			default => false,
		};
	}

	/**
	 * Creates a Checkout Session with the Flex API.
	 *
	 * @param ResourceAction $action The operation to perform.
	 *
	 * @throws FlexException If anything goes wrong.
	 */
	public function exec( ResourceAction $action ): void {
		if ( ! $this->can( $action ) ) {
			return;
		}

		$this->remote_request(
			'/v1/checkout/sessions/' . $this->id . '/refund',
			array(
				'method' => 'POST',
				'flex'   => array( 'data' => array( 'checkout_session' => $this ) ),
			),
		);
	}
}
