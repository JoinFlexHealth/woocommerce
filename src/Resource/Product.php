<?php
/**
 * Flex Product
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Resource;

use Automattic\WooCommerce\Enums\ProductStatus;
use Automattic\WooCommerce\Enums\ProductType;
use Flex\Exception\FlexException;

/**
 * Flex Product
 */
class Product extends Resource implements ResourceInterface {

	public const WC_TYPES = array( ProductType::SIMPLE, ProductType::VARIABLE );

	protected const KEY_ID                  = 'product_id';
	protected const KEY_HASH                = 'product_hash';
	protected const KEY_NAME                = 'product_name';
	protected const KEY_HSA_FSA_ELIGIBILITY = 'product_hsa_fsa_eligibility';

	/**
	 * WooCommerce Product
	 *
	 * @var ?\WC_Product
	 */
	protected ?\WC_Product $wc = null;

	/**
	 * Creates a Flex Product
	 *
	 * @param string  $name The name of the product.
	 * @param bool    $active Determines if the product is active or not.
	 * @param ?string $id The id of the product.
	 * @param ?string $description The description of the product.
	 * @param ?string $gtin The GTIN of the product.
	 * @param ?string $url The image url of the product.
	 * @param ?string $hsa_fsa_eligibility The FSA/HSA eligibility of the product.
	 */
	public function __construct(
		protected string $name = '',
		protected bool $active = true,
		protected ?string $id = null,
		protected ?string $description = null,
		protected ?string $gtin = null,
		protected ?string $url = null,
		protected ?string $hsa_fsa_eligibility = null
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
		$data = array(
			'name'        => $this->name,
			'active'      => $this->active,
			'description' => $this->description,
			'url'         => $this->url,
		);

		// Only include the GTIN if it's managed by WooCommerce.
		if ( null !== $this->wc && null !== self::wc_gtin( $this->wc ) ) {
			$data['gtin'] = $this->gtin;
		}

		return $data;
	}

	/**
	 * Extract product data from an array of data returned by the Flex API.
	 *
	 * @param array|string $product The product object returned from the API.
	 */
	public static function from_flex( array|string $product ): self {
		if ( is_string( $product ) ) {
			return new self( id: $product );
		}

		$p = new self();
		$p->extract( $product );
		return $p;
	}

	/**
	 * Extract product data from an array of data returned by the Flex API.
	 *
	 * @param array $product The product object returned from the API.
	 */
	protected function extract( array $product ): void {
		$this->name                = $product['name'] ?? $this->name;
		$this->id                  = $product['product_id'] ?? $this->id;
		$this->active              = $product['active'] ?? $this->active;
		$this->description         = $product['description'] ?? $this->description;
		$this->gtin                = $product['gtin'] ?? $this->gtin;
		$this->url                 = $product['url'] ?? $this->url;
		$this->hsa_fsa_eligibility = $product['hsa_fsa_eligibility'] ?? $this->hsa_fsa_eligibility;
	}

	/**
	 * Returns the GTIN that is managed by WooCommerce (if any).
	 *
	 * @param \WC_Product $product The WooCommerce Product.
	 */
	protected static function wc_gtin( \WC_Product $product ): ?string {
		$global_unique_id = $product->get_global_unique_id();
		if ( ! empty( $global_unique_id ) ) {
			$structured_data  = WC()->structured_data;
			$global_unique_id = $structured_data->prepare_gtin( $global_unique_id );
			if ( $structured_data->is_valid_gtin( $global_unique_id ) ) {
				return $global_unique_id;
			}
		}

		return null;
	}

	/**
	 * Create a new product from a WooCommerce Product.
	 * Since WooCommerce is the system-of-record, this product represents the intended state.
	 *
	 * @param \WC_Product $product The WooCommerce Product.
	 */
	public static function from_wc( \WC_Product $product ): self {
		$meta_prefix = self::meta_prefix();
		$description = trim( $product->get_short_description() );
		$url         = get_permalink( $product->get_id() );

		$gtin = self::wc_gtin( $product );

		$p = new self(
			name: $product->get_name(),
			id: $product->meta_exists( $meta_prefix . self::KEY_ID ) ? $product->get_meta( $meta_prefix . self::KEY_ID ) : null,
			active: $product->get_status() !== ProductStatus::TRASH,
			description: $description ? $description : null,
			gtin: $gtin,
			url: $url ? $url : null,
			hsa_fsa_eligibility: $product->meta_exists( $meta_prefix . self::KEY_HSA_FSA_ELIGIBILITY ) ? $product->get_meta( $meta_prefix . self::KEY_HSA_FSA_ELIGIBILITY ) : null,
		);

		$p->wc = $product;

		return $p;
	}

	/**
	 * {@inheritdoc}
	 */
	public function needs(): ResourceAction {
		// If the product was not created from WooCommerce, then there is nothing that needs to be done.
		if ( null === $this->wc ) {
			return ResourceAction::NONE;
		}

		if ( ! in_array( $this->wc->get_type(), self::WC_TYPES, true ) ) {
			return ResourceAction::NONE;
		}

		if ( null === $this->id ) {
			return ResourceAction::CREATE;
		}

		$meta_prefix = self::meta_prefix();

		if ( $this->wc->get_meta( $meta_prefix . self::KEY_NAME ) !== $this->name ) {
			return ResourceAction::CREATE;
		}

		if ( $this->wc->get_meta( $meta_prefix . self::KEY_HASH ) !== $this->hash() ) {
			return ResourceAction::UPDATE;
		}

		return ResourceAction::NONE;
	}

	/**
	 * Applies the Product data onto a WooCommerce Product.
	 * This is effectively the opposite of {@link Product::from_wc}.
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

		$product->update_meta_data( $meta_prefix . self::KEY_NAME, $this->name );
		$product->update_meta_data( $meta_prefix . self::KEY_HASH, $this->hash() );

		if ( null === $this->hsa_fsa_eligibility ) {
			$product->delete_meta_data( $meta_prefix . self::KEY_HSA_FSA_ELIGIBILITY );
		} else {
			$product->update_meta_data( $meta_prefix . self::KEY_HSA_FSA_ELIGIBILITY, $this->hsa_fsa_eligibility );
		}

		// Only override the existing GTIN if it's managed by WooCommerce or is empty.
		if ( null !== self::wc_gtin( $product ) || empty( $product->get_global_unique_id() ) ) {
			$product->set_global_unique_id( $this->gtin );
		}
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param ResourceAction $action The action to check.
	 */
	public function can( ResourceAction $action ): bool {
			return match ( $action ) {
				ResourceAction::CREATE => true,
				ResourceAction::UPDATE => null !== $this->id,
				default => false,
			};
	}

	/**
	 * Creates or updates a product with the Flex API.
	 *
	 * @param ResourceAction $action The action to perform.
	 *
	 * @throws FlexException If anything goes wrong.
	 */
	public function exec( ResourceAction $action ): void {
		if ( ! $this->can( $action ) ) {
			return;
		}

		// If the Product is being re-created, deactivate the existing one.
		$existing = null;
		$product  = $this;
		if ( null !== $this->id && ResourceAction::CREATE === $action ) {
			$existing = new self(
				name: $this->name,
				id: $this->id,
				active: false,
			);

			// Retrieve the existing product so we do not drop any existing values on re-creation.
			$data = $this->remote_request(
				'/v1/products/' . $this->id,
			);

			if ( isset( $data['product'] ) && is_array( $data['product'] ) ) {
				// Remove fields that we no longer care about.
				unset( $data['product']['product_id'] );

				$product = array_merge( $data['product'], $this->jsonSerialize() );
			}
		}

		$data = $this->remote_request(
			match ( $action ) {
				ResourceAction::CREATE =>  '/v1/products',
				ResourceAction::UPDATE =>  '/v1/products/' . $this->id,
			},
			array(
				'method' => match ( $action ) {
					ResourceAction::CREATE => 'POST',
					ResourceAction::UPDATE => 'PATCH',
				},
				'flex'   => array( 'data' => array( 'product' => $product ) ),
			),
		);

		if ( ! isset( $data['product'] ) ) {
			throw new FlexException( 'Missing product in response.' );
		}

		$this->extract( $data['product'] );

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
