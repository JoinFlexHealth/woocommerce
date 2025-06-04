<?php
/**
 * Flex Shipping Rate.
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Resource;

/**
 * Flex Shipping Rate
 */
class ShippingRate extends Resource {

	/**
	 * Creates a shipping rate
	 *
	 * @param string  $display_name The name of the shipping rate.
	 * @param int     $amount The amount of the shipping rate in cents.
	 * @param ?string $id The id of the shipping rate.
	 */
	public function __construct(
		protected string $display_name,
		protected int $amount,
		protected ?string $id = null,
	) {}

	/**
	 * {@inheritdoc}
	 */
	public function id(): ?string {
		return $this->id;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Only serialize properties where WooCommerce is the system of record.
	 */
	public function jsonSerialize(): array {
		return array(
			'display_name' => $this->display_name,
			'amount'       => $this->amount,
		);
	}
}
