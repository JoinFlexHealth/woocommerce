<?php
/**
 * Flex Checkout Session Line Item
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Resource\CheckoutSession;

use Flex\Resource\Resource;
use Flex\Resource\ShippingRate;

/**
 * Flex Shipping Rate
 */
class ShippingOptions extends Resource {

	/**
	 * WooCommerce Order
	 *
	 * @var ?\WC_Order
	 */
	protected ?\WC_Order $wc = null;

	/**
	 * Creates the checkout session shipping options
	 *
	 * @param ShippingRate $shipping_rate The shipping rate that comprises the options.
	 */
	public function __construct(
		protected ShippingRate $shipping_rate
	) {}

	/**
	 * {@inheritdoc}
	 */
	public function id(): ?string {
		return $this->shipping_rate->id();
	}

	/**
	 * {@inheritdoc}
	 *
	 * Only serialize properties where WooCommerce is the system of record.
	 */
	public function jsonSerialize(): array {
		if ( null !== $this->id() ) {
			return array(
				'shipping_rate_id' => $this->id(),
			);
		}

		return array(
			'shipping_rate_data' => $this->shipping_rate,
		);
	}

	/**
	 * Create a new Shipping Option from the Flex API response.
	 *
	 * @param array $shipping_option A shipping option from the API response.
	 */
	public static function from_flex( array $shipping_option ) {
		return new self(
			new ShippingRate(
				display_name: '',
				amount: $shipping_option['shipping_amount'] ?? 0,
				id: $shipping_option['shipping_rate_id'] ?? null,
			),
		);
	}

	/**
	 * Create a Shipping Rate from a WooCommerce Order.
	 *
	 * @param \WC_Order $order The WooCommerce Order.
	 */
	public static function from_wc( \WC_Order $order ): self {
		$shipping_option = new self(
			new ShippingRate(
				display_name: $order->get_shipping_method(),
				amount: self::currency_to_unit_amount( $order->get_shipping_total() ),
			)
		);

		$shipping_option->wc = $order;

		return $shipping_option;
	}
}
