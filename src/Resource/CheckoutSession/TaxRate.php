<?php
/**
 * Flex Checkout Session Tax Rate
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Resource\CheckoutSession;

use Flex\Resource\Resource;

/**
 * Flex Shipping Rate
 */
class TaxRate extends Resource {

	/**
	 * WooCommerce Order
	 *
	 * @var ?\WC_Order
	 */
	protected ?\WC_Order $wc = null;

	/**
	 * Creates the checkout session tax rate.
	 *
	 * @param int $amount The amount of taxes to charge.
	 */
	public function __construct(
		protected int $amount
	) {}

	/**
	 * {@inheritdoc}
	 */
	public function id(): ?string {
		return null;
	}

	/**
	 * The amount of the tax rate.
	 */
	public function amount(): int {
		return $this->amount;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Only serialize properties where WooCommerce is the system of record.
	 */
	public function jsonSerialize(): array {
		return array(
			'amount' => $this->amount,
		);
	}

	/**
	 * Create a new Tax Rate from the Flex API response.
	 *
	 * @param array $tax_rate A tax rate from the API response.
	 */
	public static function from_flex( array $tax_rate ) {
		return new self(
			amount: $tax_rate['amount'] ?? 0,
		);
	}

	/**
	 * Create a Tax Rate from a WooCommerce Order.
	 *
	 * @param \WC_Order $order The WooCommerce Order.
	 */
	public static function from_wc( \WC_Order $order ): self {
		$tax_rate = new self(
			amount: self::currency_to_unit_amount( $order->get_total_tax() ),
		);

		$tax_rate->wc = $order;

		return $tax_rate;
	}
}
