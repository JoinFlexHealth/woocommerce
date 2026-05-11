<?php
/**
 * Flex Checkout Session Fee
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Resource\CheckoutSession;

use Flex\Resource\Resource;

/**
 * Flex Shipping Rate
 */
class Fee extends Resource {

	/**
	 * WooCommerce Fee
	 *
	 * @var ?\WC_Order_Item_Fee
	 */
	protected ?\WC_Order_Item_Fee $wc = null;

	/**
	 * Creates the checkout session tax rate.
	 *
	 * @param int     $amount The amount of fee to charge.
	 * @param FeeType $type The type of fee.
	 * @param ?string $name The name of the fee to display.
	 * @param ?string $description The description of the fee.
	 */
	public function __construct(
		protected int $amount,
		protected FeeType $type = FeeType::CUSTOM,
		protected ?string $name = null,
		protected ?string $description = null,
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
	 *
	 * @return array{ amount: int, fee_type: string, name: ?string, description: ?string }
	 */
	public function jsonSerialize(): array {
		return array(
			'amount'      => $this->amount,
			'fee_type'    => $this->type->value,
			'name'        => $this->name,
			'description' => $this->description,
		);
	}

	/**
	 * Create a new Fee from the Flex API response.
	 *
	 * @param array<string, mixed> $fee A fee from the API response.
	 */
	public static function from_flex( array $fee ): self {
		$fee_type = $fee['fee_type'] ?? '';

		return new self(
			amount: isset( $fee['amount'] ) && is_int( $fee['amount'] ) ? $fee['amount'] : 0,
			type: is_string( $fee_type ) ? ( FeeType::tryFrom( $fee_type ) ?? FeeType::CUSTOM ) : FeeType::CUSTOM,
			name: isset( $fee['name'] ) && is_string( $fee['name'] ) ? $fee['name'] : null,
			description: isset( $fee['description'] ) && is_string( $fee['description'] ) ? $fee['description'] : null,
		);
	}

	/**
	 * Create a Fee from a WooCommerce Fee.
	 *
	 * @param \WC_Order_Item_Fee $item The WooCommerce Fee.
	 */
	public static function from_wc( \WC_Order_Item_Fee $item ): self {
		$fee = new self(
			amount: self::currency_to_unit_amount( $item->get_amount() ),
			name: $item->get_name(),
		);

		$fee->wc = $item;

		return $fee;
	}
}
