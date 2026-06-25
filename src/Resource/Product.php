<?php
/**
 * Flex Product
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Resource;

use Flex\Exception\FlexException;
use Flex\Exception\FlexResponseException;

/**
 * Flex Product
 */
class Product extends Resource implements ResourceInterface {

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
	 *
	 * @return array{ name: string, active: bool, description: ?string, url: ?string, gtin?: ?string }
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
	 * @param array<string, mixed>|string $product The product object returned from the API.
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
	 * @param array<string, mixed> $product The product object returned from the API.
	 */
	protected function extract( array $product ): void {
		$this->name                = isset( $product['name'] ) && is_string( $product['name'] ) ? $product['name'] : $this->name;
		$this->id                  = isset( $product['product_id'] ) && is_string( $product['product_id'] ) ? $product['product_id'] : $this->id;
		$this->active              = isset( $product['active'] ) && is_bool( $product['active'] ) ? $product['active'] : $this->active;
		$this->description         = isset( $product['description'] ) && is_string( $product['description'] ) ? $product['description'] : $this->description;
		$this->gtin                = isset( $product['gtin'] ) && is_string( $product['gtin'] ) ? $product['gtin'] : $this->gtin;
		$this->url                 = isset( $product['url'] ) && is_string( $product['url'] ) ? $product['url'] : $this->url;
		$this->hsa_fsa_eligibility = isset( $product['hsa_fsa_eligibility'] ) && is_string( $product['hsa_fsa_eligibility'] ) ? $product['hsa_fsa_eligibility'] : $this->hsa_fsa_eligibility;
	}

	/**
	 * Returns the GTIN that is managed by WooCommerce (if any).
	 *
	 * @param \WC_Product $product The WooCommerce Product.
	 */
	protected static function wc_gtin( \WC_Product $product ): ?string {
		$global_unique_id = $product->get_global_unique_id();
		if ( '' !== $global_unique_id ) {
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
		/**
		 * Product Description.
		 *
		 * @see https://github.com/woocommerce/woocommerce/blob/9.3.3/plugins/woocommerce/includes/class-wc-structured-data.php#L203
		 */
		$short_description = $product->get_short_description();
		$description       = trim( wp_strip_all_tags( do_shortcode( '' !== $short_description ? $short_description : $product->get_description() ) ) );
		$url               = get_permalink( $product->get_id() );

		$gtin = self::wc_gtin( $product );

		$product_id_meta  = $product->get_meta( $meta_prefix . self::KEY_ID );
		$eligibility_meta = $product->get_meta( $meta_prefix . self::KEY_HSA_FSA_ELIGIBILITY );

		$p = new self(
			name: $product->get_name(),
			id: $product->meta_exists( $meta_prefix . self::KEY_ID ) && is_string( $product_id_meta ) ? $product_id_meta : null,
			// A product is active only while it remains a catalog product. Trashed,
			// draft, and private products (and those with no price) are not catalog
			// products, so they deactivate in Flex. Including this in the hash makes
			// needs() report UPDATE when a synced product unpublishes or loses its price.
			active: self::is_catalog_product( $product ),
			description: '' !== $description ? $description : null,
			gtin: $gtin,
			url: false !== $url && '' !== $url ? $url : null,
			hsa_fsa_eligibility: $product->meta_exists( $meta_prefix . self::KEY_HSA_FSA_ELIGIBILITY ) && is_string( $eligibility_meta ) ? $eligibility_meta : null,
		);

		$p->wc = $product;

		return $p;
	}

	/**
	 * Whether a WooCommerce product is a Flex catalog product.
	 *
	 * A catalog product is a top-level sellable product: simple, variable parent,
	 * bundle, composite, subscription. It excludes variations (whose catalog product
	 * is their parent — see Price::from_wc) and non-purchasable grouped/external
	 * products. Catalog products map to a Flex product; their purchasable units map
	 * to Flex prices (see Price::is_purchasable_unit).
	 *
	 * @param \WC_Product $product The WooCommerce product.
	 */
	public static function is_catalog_product( \WC_Product $product ): bool {
		return 0 === $product->get_parent_id() && $product->is_purchasable();
	}

	/**
	 * {@inheritdoc}
	 */
	public function needs(): ResourceAction {
		// If the product was not created from WooCommerce, then there is nothing that needs to be done.
		if ( null === $this->wc ) {
			return ResourceAction::NONE;
		}

		$meta_prefix = self::meta_prefix();

		if ( ! self::is_catalog_product( $this->wc ) ) {
			// No longer a catalog product (trashed, unpublished, or price cleared).
			// If it was previously synced, push the deactivation from_wc captured
			// (active: false). The hash gate keeps this idempotent and loop-free:
			// exec(UPDATE) -> apply_to() rewrites the hash, so the next sync is NONE.
			if ( null === $this->id ) {
				return ResourceAction::NONE;
			}
			return $this->wc->get_meta( $meta_prefix . self::KEY_HASH ) !== $this->hash()
				? ResourceAction::UPDATE
				: ResourceAction::NONE;
		}

		if ( null === $this->id ) {
			return ResourceAction::CREATE;
		}

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
		if ( null !== self::wc_gtin( $product ) || '' === $product->get_global_unique_id() ) {
			$product->set_global_unique_id( $this->gtin ?? '' );
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
				ResourceAction::UPDATE, ResourceAction::REFRESH => null !== $this->id,
				default => false,
			};
	}

	/**
	 * Creates or updates a product with the Flex API.
	 *
	 * @param ResourceAction $action The action to perform.
	 *
	 * @throws FlexException If the response is malformed.
	 * @throws FlexResponseException Rethrows exception if it cannot be handled.
	 * @throws \LogicException If unsupported actions is passed in.
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
			try {
				$data = $this->remote_request(
					'/v1/products/' . $this->id,
				);

				if ( isset( $data['product'] ) && is_array( $data['product'] ) ) {
					// Remove fields that we no longer care about.
					unset( $data['product']['product_id'] );

					$product = array_merge( $data['product'], $this->jsonSerialize() );
				}
			} catch ( FlexResponseException $e ) {
				if ( $e->code() !== 404 ) {
					throw $e;
				}

				$existing = null;
			}
		}

		try {
			$data = $this->remote_request(
				match ( $action ) {
					ResourceAction::CREATE =>  '/v1/products',
					ResourceAction::UPDATE, ResourceAction::REFRESH =>  '/v1/products/' . $this->id,
					default => throw new \LogicException( "Unhandled action: {$action->name}" ),
				},
				array(
					'method' => match ( $action ) {
						ResourceAction::CREATE => 'POST',
						ResourceAction::UPDATE => 'PATCH',
						ResourceAction::REFRESH => 'GET',
					},
					'flex'   => match ( $action ) {
						ResourceAction::CREATE, ResourceAction::UPDATE => array( 'data' => array( 'product' => $product ) ),
						ResourceAction::REFRESH => array(),
					},
				),
			);

			if ( ! isset( $data['product'] ) || ! is_array( $data['product'] ) ) {
				throw new FlexException( 'Missing product in response.' );
			}

			/**
			 * The product data from the API response.
			 *
			 * @var array<string, mixed> $product_data
			 */
			$product_data = $data['product'];
			$this->extract( $product_data );
		} catch ( FlexResponseException $e ) {
			if ( ResourceAction::CREATE === $action ) {
				throw $e;
			}

			if ( $e->code() !== 404 ) {
				throw $e;
			}

			// If an update or refresh was being performed and the API returned a 404, then re-create it.
			$this->exec( ResourceAction::CREATE );
			return;
		}

		if ( null !== $this->wc ) {
			$this->apply_to( $this->wc );
			$this->wc?->save();
		}

		// Deactivate the existing Product.
		if ( null !== $existing ) {
			$existing->exec( ResourceAction::UPDATE );
		}
	}
}
