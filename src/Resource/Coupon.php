<?php
/**
 * Flex Coupon
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Resource;

/**
 * Flex Coupon
 */
class Coupon extends Resource {

	/**
	 * The prices that the coupon applies to.
	 *
	 * @var Price[]
	 */
	protected $applies_to;

	/**
	 * Creates a coupon
	 *
	 * @param string   $name The name of the coupon.
	 * @param ?string  $id The id of the coupon.
	 * @param ?int     $amount_off The amount to take off in cents.
	 * @param ?Price[] $applies_to $the prices that the coupon applies to.
	 *
	 * @throws \LogicException If $applies_to contains anything other than instances of Price.
	 */
	public function __construct(
		protected string $name,
		protected ?string $id = null,
		protected ?int $amount_off = null,
		?array $applies_to = null,
	) {
		if ( ! empty( $applies_to ) && ! array_all( $applies_to, fn ( $item ) => $item instanceof Price ) ) {
			throw new \LogicException( 'Coupon::$applies_to may only contain instances of Price' );
		}

		$this->applies_to = $applies_to ?? array();
	}

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
		$data = array(
			'name'       => $this->name,
			'amount_off' => $this->amount_off,
		);

		if ( ! empty( $this->applies_to ) ) {
			$data['applies_to']['prices'] = array_map( fn ( $price ) => $price->id(), $this->applies_to );
		}

		return $data;
	}
}
