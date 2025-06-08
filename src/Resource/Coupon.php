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
		protected string $name = '',
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
	 * The amount to take off in cents.
	 */
	public function amount_off(): ?int {
		return $this->amount_off;
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

	/**
	 * Extract coupon data from an array of data returned by the Flex API.
	 *
	 * @param array $coupon The coupon object returned from the API.
	 */
	protected function extract( array $coupon ): void {
		$this->id         = $coupon['coupon_id'] ?? $this->id;
		$this->name       = $coupon['name'] ?? $this->name;
		$this->amount_off = $coupon['amount_off'] ?? $this->amount_off;

		if ( ! empty( $coupon['applies_to']['prices'] ) && is_array( $coupon['applies_to']['prices'] ) ) {
			$this->applies_to = array_map( static fn ( $price ) => Price::from_flex( $price ), $coupon['applies_to']['prices'] );
		}
	}

	/**
	 * Extract coupon data from an array of data returned by the Flex API.
	 *
	 * @param array|string $coupon The coupon object returned from the API.
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

		$coupon = new self(
			id: $product->meta_exists( $meta_prefix . self::KEY_ID ) ? $product->get_meta( $meta_prefix . self::KEY_ID ) : null,
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
		if ( empty( $this->amount_off ) ) {
			return ResourceAction::NONE;
		}

		// Wait for the price to be updated if it needs to be.
		if ( array_any( $this->applies_to, static fn ( $price ) => ResourceAction::NONE !== $price->needs() || null === $price->id() ) ) {
			return ResourceAction::DEPENDENCY;
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

		if ( ! isset( $data['coupon'] ) ) {
			throw new FlexException( 'Missing coupon in response.' );
		}

		$this->extract( $data['coupon'] );

		if ( null !== $this->wc ) {
			$this->apply_to( $this->wc );
			$this->wc->save();
		}
	}
}
