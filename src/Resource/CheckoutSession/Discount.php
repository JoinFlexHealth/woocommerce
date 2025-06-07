<?php
/**
 * Flex Checkout Session Discount
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Resource\CheckoutSession;

use Flex\Resource\Resource;
use Flex\Resource\Coupon;

/**
 * Flex Checkout Session Discount
 */
class Discount extends Resource {

	/**
	 * Creates the checkout discount
	 *
	 * @param Coupon $item The coupon within the discount.
	 */
	public function __construct(
		protected Coupon $item
	) {}

	/**
	 * {@inheritdoc}
	 */
	public function id(): ?string {
		return null;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Only serialize properties where WooCommerce is the system of record.
	 */
	public function jsonSerialize(): ?array {
		if ( $this->item instanceof Coupon ) {
			if ( null !== $this->item->id() ) {
				return array(
					'coupon_id' => $this->item->id(),
				);
			}

			return array(
				'coupon_data' => $this->item,
			);
		}

		return null;
	}
}
