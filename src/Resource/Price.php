<?php
/**
 * Flex Price
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Resource;

use Automattic\WooCommerce\Enums\ProductStatus;
use Automattic\WooCommerce\Enums\ProductType;
use Flex\Exception\FlexException;

/**
 * Flex Price
 */
class Price extends Resource implements ResourceInterface {

	public const WC_TYPES = array( ProductType::SIMPLE, ProductType::VARIATION );

	protected const KEY_ID                  = 'price_id';
	protected const KEY_HASH                = 'price_hash';
	protected const KEY_PRODUCT             = 'price_product';
	protected const KEY_AMOUNT              = 'price_amount';
	protected const KEY_HSA_FSA_ELIGIBILITY = 'price_hsa_fsa_eligibility';

	/**
	 * WooCommerce Product
	 *
	 * @var ?\WC_Product
	 */
	protected ?\WC_Product $wc = null;

	/**
	 * Creates a Flex Price
	 *
	 * @param Product $product The product the price belongs too.
	 * @param ?string $id The id of the price.
	 * @param bool    $active Determines if the price is active or not.
	 * @param ?string $description The description of the product.
	 * @param ?int    $unit_amount The amount of the price.
	 * @param ?string $hsa_fsa_eligibility The FSA/HSA eligibility of the price.
	 */
	public function __construct(
		protected Product $product = new Product(),
		protected ?string $id = null,
		protected bool $active = true,
		protected ?string $description = null,
		protected ?int $unit_amount = null,
		protected ?string $hsa_fsa_eligibility = null,
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
			'active'      => $this->active,
			'description' => $this->description,
			'product'     => $this->product->id(),
			'unit_amount' => $this->unit_amount,
		);
	}

	/**
	 * Create a new price from a WooCommerce Product.
	 * Since WooCommerce is the system-of-record, this product represents the intended state.
	 *
	 * @param \WC_Product $product The WooCommerce Product.
	 */
	public static function from_wc( \WC_Product $product ): self {
		$meta_prefix = self::meta_prefix();

		$flex_product = null;
		if ( ProductType::VARIATION === $product->get_type() ) {
			$parent_product = wc_get_product( $product->get_parent_id() );
			if ( $parent_product ) {
				$flex_product = Product::from_wc( $parent_product );
			}
		} else {
			$flex_product = Product::from_wc( $product );
		}

		$price = $product->get_regular_price();

		$description = $product->get_short_description();
		if ( ! $description && ProductType::VARIATION === $product->get_type() ) {
			$description = wc_get_formatted_variation( $product, true );
		}

		$price = new self(
			id: $product->meta_exists( $meta_prefix . self::KEY_ID ) ? $product->get_meta( $meta_prefix . self::KEY_ID ) : null,
			active: $product->get_status() !== ProductStatus::TRASH,
			description: $description ? trim( $description ) : null,
			product: $flex_product,
			unit_amount: $price ? self::currency_to_unit_amount( $price ) : null,
			hsa_fsa_eligibility: $product->meta_exists( $meta_prefix . self::KEY_HSA_FSA_ELIGIBILITY ) ? $product->get_meta( $meta_prefix . self::KEY_HSA_FSA_ELIGIBILITY ) : null,
		);

		$price->wc = $product;

		return $price;
	}

	/**
	 * Extract price data from an array of data returned by the Flex API.
	 *
	 * @param array|string $price The price object returned from the API.
	 */
	public static function from_flex( array|string $price ): self {
		if ( is_string( $price ) ) {
			return new self( id: $price );
		}

		$p = new self();
		$p->extract( $price );
		return $p;
	}


	/**
	 * Extract price data from an array of data returned by the Flex API.
	 *
	 * @param array $price The price object returned from the API.
	 */
	protected function extract( array $price ): void {
		$this->id                  = $price['price_id'] ?? $this->id;
		$this->active              = $price['active'] ?? $this->active;
		$this->description         = $price['description'] ?? $this->description;
		$this->unit_amount         = $price['unit_amount'] ?? $this->unit_amount;
		$this->hsa_fsa_eligibility = $price['hsa_fsa_eligibility'] ?? $this->hsa_fsa_eligibility;

		if ( isset( $price['product'] ) ) {
			$updated = Product::from_flex( $price['product'] );
			if ( $this->product->id() !== $updated->id() ) {
				$this->product = $updated;
			}
		}
	}

	/**
	 * Applies the product data onto a WooCommerce Product.
	 * This is effectively the opposite of {@link Price::from_wc}.
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

		if ( null === $this->product->id() ) {
			$product->delete_meta_data( $meta_prefix . self::KEY_PRODUCT );
		} else {
			$product->update_meta_data( $meta_prefix . self::KEY_PRODUCT, $this->product->id() );
		}

		if ( null === $this->unit_amount ) {
			$product->delete_meta_data( $meta_prefix . self::KEY_AMOUNT );
		} else {
			$product->update_meta_data( $meta_prefix . self::KEY_AMOUNT, strval( $this->unit_amount ) );
		}

		if ( null === $this->hsa_fsa_eligibility ) {
			$product->delete_meta_data( $meta_prefix . self::KEY_HSA_FSA_ELIGIBILITY );
		} else {
			$product->update_meta_data( $meta_prefix . self::KEY_HSA_FSA_ELIGIBILITY, $this->hsa_fsa_eligibility );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function needs(): ResourceAction {
		// If the price was not created from WooCommerce, then there is nothing that needs to be done.
		if ( null === $this->wc ) {
			return ResourceAction::NONE;
		}

		if ( ! in_array( $this->wc->get_type(), self::WC_TYPES, true ) ) {
			return ResourceAction::NONE;
		}

		// If there is no unit amount, there is nothing to be done.
		if ( null === $this->unit_amount ) {
			return ResourceAction::NONE;
		}

		// Wait for a product id to be set.
		if ( null === $this->product->id() ) {
			return ResourceAction::DEPENDENCY;
		}

		if ( null === $this->id ) {
			return ResourceAction::CREATE;
		}

		$meta_prefix = self::meta_prefix();

		if ( $this->wc->get_meta( $meta_prefix . self::KEY_PRODUCT ) !== $this->product->id() ) {
			return ResourceAction::CREATE;
		}

		$amount = $this->wc->get_meta( $meta_prefix . self::KEY_AMOUNT );
		if ( null === $amount || intval( $amount ) !== $this->unit_amount ) {
			return ResourceAction::CREATE;
		}

		if ( $this->wc->get_meta( $meta_prefix . self::KEY_HASH ) !== $this->hash() ) {
			return ResourceAction::UPDATE;
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
			ResourceAction::UPDATE => null !== $this->id,
			default => false,
		};
	}

	/**
	 * Creates or updates a Price with the Flex API.
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
			$this->product->exec( $this->product->needs() );

			// Revaluate the action that is needed.
			$action = $this->needs();
			if ( ResourceAction::DEPENDENCY === $action || false === $this->can( $action ) ) {
				return;
			}
		}

		// If the Price is being re-created, deactivate the existing one.
		$existing = null;
		$price    = $this;
		if ( null !== $this->id && ResourceAction::CREATE === $action ) {
			$existing = new self(
				id: $this->id,
				active: false,
				product: $this->product,
			);

			// Retrieve the existing price so we do not drop any existing values on re-creation.
			$data = $this->remote_request(
				'/v1/prices/' . $this->id,
			);

			if ( isset( $data['price'] ) && is_array( $data['price'] ) ) {
				// Remove fields that we no longer care about.
				unset( $data['price']['price_id'] );
				unset( $data['price']['price'] );

				$price = array_merge( $data['price'], $this->jsonSerialize() );
			}
		}

		$data = $this->remote_request(
			match ( $action ) {
				ResourceAction::CREATE =>  '/v1/prices',
				ResourceAction::UPDATE =>  '/v1/prices/' . $this->id,
			},
			array(
				'method' => 'POST',
				'flex'   => array( 'data' => array( 'price' => $price ) ),
			),
		);

		if ( ! isset( $data['price'] ) ) {
			throw new FlexException( 'Missing price in response.' );
		}

		$this->extract( $data['price'] );

		if ( null !== $this->wc ) {
			$this->apply_to( $this->wc );
			$this->wc->save();
		}

		// Deactivate the existing Product.
		if ( $existing ) {
			$existing->exec( ResourceAction::UPDATE );
		}
	}
}
