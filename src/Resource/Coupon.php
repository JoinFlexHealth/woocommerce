<?php
/**
 * Flex Coupon
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Resource;

use Flex\Exception\FlexException;

/**
 * Flex Coupon
 */
class Coupon extends Resource {

	protected const KEY_ID   = 'coupon_id';
	protected const KEY_HASH = 'coupon_hash';

	/**
	 * WooCommerce Product
	 *
	 * @var ?\WC_Product
	 */
	protected ?\WC_Product $wc = null;

	/**
	 * Creates a coupon
	 *
	 * @param string  $name The name of the coupon.
	 * @param ?string $id The id of the coupon.
	 * @param ?int    $amount_off The amount to take off in cents.
	 * @param Price[] $applies_to The prices that the coupon applies to.
	 */
	public function __construct(
		protected string $name = '',
		protected ?string $id = null,
		protected ?int $amount_off = null,
		protected array $applies_to = array(),
	) {
	}

	/**
	 * {@inheritdoc}
	 */
	public function id(): ?string {
		return $this->id;
	}

	/**
	 * The amount to take off in cents.
	 */
	public function amount_off(): ?int {
		return $this->amount_off;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Only serialize properties where WooCommerce is the system of record.
	 *
	 * @return array{ name: string, amount_off: ?int, applies_to?: array{ prices: (?string)[] } }
	 */
	public function jsonSerialize(): array {
		$data = array(
			'name'       => $this->name,
			'amount_off' => $this->amount_off,
		);

		if ( array() !== $this->applies_to ) {
			$data['applies_to'] = array(
				'prices' => array_map( fn ( $price ) => $price->id(), $this->applies_to ),
			);
		}

		return $data;
	}

	/**
	 * Extract coupon data from an array of data returned by the Flex API.
	 *
	 * @param array<string, mixed> $coupon The coupon object returned from the API.
	 */
	protected function extract( array $coupon ): void {
		$this->id         = isset( $coupon['coupon_id'] ) && is_string( $coupon['coupon_id'] ) ? $coupon['coupon_id'] : $this->id;
		$this->name       = isset( $coupon['name'] ) && is_string( $coupon['name'] ) ? $coupon['name'] : $this->name;
		$this->amount_off = isset( $coupon['amount_off'] ) && is_int( $coupon['amount_off'] ) ? $coupon['amount_off'] : $this->amount_off;

		$applies_to = isset( $coupon['applies_to'] ) && is_array( $coupon['applies_to'] ) ? $coupon['applies_to'] : array();
		if ( isset( $applies_to['prices'] ) && is_array( $applies_to['prices'] ) && array() !== $applies_to['prices'] ) {
			/**
			 * Price data for coupon applicability.
			 *
			 * @var list<array<string, mixed> |string> $prices
			 */
			$prices           = $applies_to['prices'];
			$this->applies_to = array_map( static fn ( array|string $price ) => Price::from_flex( $price ), $prices );
		}
	}

	/**
	 * Extract coupon data from an array of data returned by the Flex API.
	 *
	 * @param array<string, mixed>|string $coupon The coupon object returned from the API.
	 */
	public static function from_flex( array|string $coupon ): self {
		if ( is_string( $coupon ) ) {
			return new self( id: $coupon );
		}

		$p = new self();
		$p->extract( $coupon );
		return $p;
	}

	/**
	 * Create a new coupon from a WooCommerce Product.
	 * Since WooCommerce is the system-of-record, this product represents the intended state.
	 *
	 * @param \WC_Product $product The WooCommerce Product.
	 */
	public static function from_wc( \WC_Product $product ): self {
		$meta_prefix = self::meta_prefix();

		$amount_off = 0;
		if ( '' !== $product->get_regular_price() && '' !== $product->get_sale_price() ) {
			if ( $product->get_regular_price() !== $product->get_sale_price() ) {
				$amount_off = self::currency_to_unit_amount( $product->get_regular_price() ) - self::currency_to_unit_amount( $product->get_sale_price() );
			}
		}

		$coupon_id_meta = $product->get_meta( $meta_prefix . self::KEY_ID );
		$coupon         = new self(
			id: $product->meta_exists( $meta_prefix . self::KEY_ID ) && is_string( $coupon_id_meta ) ? $coupon_id_meta : null,
			name: __( 'Sale', 'pay-with-flex' ),
			applies_to: array(
				Price::from_wc( $product ),
			),
			amount_off: $amount_off,
		);

		$coupon->wc = $product;

		return $coupon;
	}

	/**
	 * Applies the coupon data onto a WooCommerce Product.
	 * This is effectively the opposite of {@link Coupon::from_wc}.
	 *
	 * @param \WC_Product $product The WooCommerce Product.
	 */
	public function apply_to( \WC_Product $product ): void {
		$meta_prefix = self::meta_prefix();

		if ( null === $this->id ) {
			$product->delete_meta_data( $meta_prefix . self::KEY_ID );
		} else {
			$product->update_meta_data( $meta_prefix . self::KEY_ID, $this->id );
		}

		$product->update_meta_data( $meta_prefix . self::KEY_HASH, $this->hash() );
	}

		/**
		 * {@inheritdoc}
		 */
	public function needs(): ResourceAction {
		// If the coupon was not created from WooCommerce, then there is nothing that needs to be done.
		if ( null === $this->wc ) {
			return ResourceAction::NONE;
		}

		// If there is no amount off, there is nothing to be done.
		if ( null === $this->amount_off || 0 === $this->amount_off ) {
			return ResourceAction::NONE;
		}

		// Wait for the price to be updated if it needs to be.
		foreach ( $this->applies_to as $price ) {
			if ( ResourceAction::NONE !== $price->needs() ) {
				return ResourceAction::DEPENDENCY;
			}
			// Price is settled but has no ID — it can never be resolved (e.g. orphaned variation).
			if ( null === $price->id() ) {
				return ResourceAction::NONE;
			}
		}

		if ( null === $this->id ) {
			return ResourceAction::CREATE;
		}

		$meta_prefix = self::meta_prefix();

		if ( $this->wc->get_meta( $meta_prefix . self::KEY_HASH ) !== $this->hash() ) {
			return ResourceAction::CREATE;
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
			ResourceAction::CREATE, ResourceAction::DEPENDENCY => true,
			default => false,
		};
	}

	/**
	 * Creates a Coupon with the Flex API.
	 *
	 * @param ResourceAction $action The action to perform.
	 *
	 * @throws FlexException If anything goes wrong.
	 */
	public function exec( ResourceAction $action ): void {
		if ( ! $this->can( $action ) ) {
			return;
		}

		if ( ResourceAction::DEPENDENCY === $action ) {
			foreach ( $this->applies_to as $price ) {
				$price->exec( $price->needs() );
			}

			// Revaluate the action that is needed.
			$this->exec( $this->needs() );
			return;
		}

		$data = $this->remote_request(
			'/v1/coupons',
			array(
				'method' => 'POST',
				'flex'   => array( 'data' => array( 'coupon' => $this ) ),
			),
		);

		if ( ! isset( $data['coupon'] ) || ! is_array( $data['coupon'] ) ) {
			throw new FlexException( 'Missing coupon in response.' );
		}

		/**
		 * The coupon data from the API response.
		 *
		 * @var array<string, mixed> $coupon_data
		 */
		$coupon_data = $data['coupon'];
		$this->extract( $coupon_data );

		if ( null !== $this->wc ) {
			$this->apply_to( $this->wc );
			$this->wc?->save();
		}
	}
}
