<?php
/**
 * Tests for the Product resource classification.
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Tests\Resource;

use Flex\Resource\Product;
use Flex\Resource\ResourceAction;

/**
 * Tests for Product::is_catalog_product() and Product::needs().
 */
class ProductTest extends \WP_UnitTestCase {

	/**
	 * Build a bundle-type product without the Product Bundles plugin. The
	 * production code is type-agnostic, so a purchasable, childless product whose
	 * get_type() is 'bundle' exercises the bundle path.
	 */
	private function make_bundle(): \WC_Product {
		$bundle = new class() extends \WC_Product_Simple {
			/**
			 * Returns the product type string.
			 *
			 * @return string
			 */
			public function get_type() {
				return 'bundle';
			}
		};
		$bundle->set_name( 'Kit' );
		$bundle->set_regular_price( '0' );
		$bundle->set_status( 'publish' );
		$bundle->save();
		return $bundle;
	}

	/**
	 * Build a saved variable parent with one variation; returns the reloaded parent.
	 */
	private function make_variable_parent(): \WC_Product {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Variable' );
		$parent->set_status( 'publish' );
		$parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '10.00' );
		$variation->set_status( 'publish' );
		$variation->save();

		\WC_Product_Variable::sync( $parent->get_id() );

		$reloaded = wc_get_product( $parent->get_id() );
		assert( $reloaded instanceof \WC_Product );
		return $reloaded;
	}

	/**
	 * Asserts is_catalog_product returns true for top-level sellable products.
	 */
	public function test_is_catalog_product_true_for_simple_and_bundle_and_variable_parent(): void {
		$simple = new \WC_Product_Simple();
		$simple->set_name( 'Simple' );
		$simple->set_regular_price( '10.00' );
		$simple->set_status( 'publish' );
		$simple->save();

		self::assertTrue( Product::is_catalog_product( $simple ) );
		self::assertTrue( Product::is_catalog_product( $this->make_bundle() ) );
		self::assertTrue( Product::is_catalog_product( $this->make_variable_parent() ) );
	}

	/**
	 * Asserts is_catalog_product returns false for a variation (its catalog product is the parent).
	 */
	public function test_is_catalog_product_false_for_variation(): void {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Variable' );
		$parent->set_status( 'publish' );
		$parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '10.00' );
		$variation->set_status( 'publish' );
		$variation->save();

		self::assertFalse( Product::is_catalog_product( $variation ) );
	}

	/**
	 * Asserts is_catalog_product returns false for non-purchasable grouped/external products.
	 */
	public function test_is_catalog_product_false_for_grouped_and_external(): void {
		$grouped = new \WC_Product_Grouped();
		$grouped->set_name( 'Grouped' );
		$grouped->set_status( 'publish' );
		$grouped->save();

		$external = new \WC_Product_External();
		$external->set_name( 'External' );
		$external->set_regular_price( '10.00' );
		$external->set_status( 'publish' );
		$external->save();

		self::assertFalse( Product::is_catalog_product( $grouped ) );
		self::assertFalse( Product::is_catalog_product( $external ) );
	}

	/**
	 * Asserts needs() returns CREATE for a bundle (syncs like any other top-level product).
	 */
	public function test_needs_create_for_bundle(): void {
		self::assertSame( ResourceAction::CREATE, Product::from_wc( $this->make_bundle() )->needs() );
	}

	/**
	 * Asserts needs() returns CREATE for a variable parent (still a catalog product).
	 */
	public function test_needs_create_for_variable_parent(): void {
		self::assertSame( ResourceAction::CREATE, Product::from_wc( $this->make_variable_parent() )->needs() );
	}

	/**
	 * Asserts needs() returns NONE for a variation (not its own catalog product).
	 */
	public function test_needs_none_for_variation(): void {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Variable' );
		$parent->set_status( 'publish' );
		$parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '10.00' );
		$variation->set_status( 'publish' );
		$variation->save();

		self::assertSame( ResourceAction::NONE, Product::from_wc( $variation )->needs() );
	}

	/**
	 * Asserts needs() returns NONE for grouped and external products (not synced).
	 */
	public function test_needs_none_for_grouped_and_external(): void {
		$grouped = new \WC_Product_Grouped();
		$grouped->set_name( 'Grouped' );
		$grouped->set_status( 'publish' );
		$grouped->save();

		$external = new \WC_Product_External();
		$external->set_name( 'External' );
		$external->set_regular_price( '10.00' );
		$external->set_status( 'publish' );
		$external->save();

		self::assertSame( ResourceAction::NONE, Product::from_wc( $grouped )->needs() );
		self::assertSame( ResourceAction::NONE, Product::from_wc( $external )->needs() );
	}

	/**
	 * A trashed product that was previously synced to Flex should return UPDATE
	 * so the deactivation (active: false) is pushed. The hash gate makes this
	 * idempotent: after exec(UPDATE) rewrites the hash, the next sync is NONE.
	 */
	public function test_needs_update_to_deactivate_trashed_synced_product(): void {
		$wc = new \WC_Product_Simple();
		$wc->set_name( 'Synced Simple' );
		$wc->set_regular_price( '20.00' );
		$wc->set_status( 'publish' );
		$wc->save();

		// Seed the "previously synced" state: apply_to writes KEY_ID + KEY_HASH for
		// the currently active product, mirroring what exec(CREATE) would do.
		( new Product( name: $wc->get_name(), active: true, id: 'fprod_1' ) )->apply_to( $wc );
		$wc->save();

		// Trash the product and reload (reload is required for is_purchasable to reflect
		// the new status, otherwise cached values can be stale).
		$wc->set_status( 'trash' );
		$wc->save();
		$reloaded = wc_get_product( $wc->get_id() );
		assert( $reloaded instanceof \WC_Product );

		self::assertSame( ResourceAction::UPDATE, Product::from_wc( $reloaded )->needs() );
	}

	/**
	 * Seed a product's "previously synced" Flex meta (id + hash) so it matches the
	 * active catalog product, mirroring what exec(CREATE) -> apply_to() persists.
	 * Persisting the id first lets from_wc() pick it up; rewriting the hash from the
	 * real from_wc() state ensures the stored hash matches the published product.
	 * Returns the reloaded product carrying the synced meta.
	 *
	 * @param \WC_Product $wc The WooCommerce product (must be saved).
	 * @param string      $id The Flex product id to seed.
	 */
	private function seed_synced( \WC_Product $wc, string $id ): \WC_Product {
		( new Product( name: $wc->get_name(), active: true, id: $id ) )->apply_to( $wc );
		$wc->save();

		$reloaded = wc_get_product( $wc->get_id() );
		assert( $reloaded instanceof \WC_Product );
		Product::from_wc( $reloaded )->apply_to( $reloaded );
		$reloaded->save();

		$synced = wc_get_product( $reloaded->get_id() );
		assert( $synced instanceof \WC_Product );
		return $synced;
	}

	/**
	 * A previously synced product that is unpublished (draft/private) is no longer a
	 * catalog product, but is not trashed. It must still return UPDATE so the
	 * deactivation (active: false) is pushed. Before deriving active from
	 * is_catalog_product(), active stayed true for non-trashed products, so the hash
	 * was unchanged and needs() wrongly returned NONE.
	 */
	public function test_needs_update_to_deactivate_unpublished_synced_product(): void {
		$wc = new \WC_Product_Simple();
		$wc->set_name( 'Published Simple' );
		$wc->set_regular_price( '20.00' );
		$wc->set_status( 'publish' );
		$wc->save();

		$synced = $this->seed_synced( $wc, 'fprod_unpub' );
		// Baseline: a fully synced catalog product needs nothing.
		self::assertSame( ResourceAction::NONE, Product::from_wc( $synced )->needs() );

		// Unpublish: a draft product is not purchasable, so not a catalog product.
		$synced->set_status( 'draft' );
		$synced->save();
		$reloaded = wc_get_product( $synced->get_id() );
		assert( $reloaded instanceof \WC_Product );

		self::assertSame( ResourceAction::UPDATE, Product::from_wc( $reloaded )->needs() );
	}

	/**
	 * A previously synced product that keeps its published status but loses its price
	 * is no longer purchasable, so no longer a catalog product. It must return UPDATE
	 * to deactivate in Flex. Here the status (and permalink) are unchanged, so the
	 * active flag is the only hash input that moves — proving the deactivation is
	 * driven by catalog eligibility, not just the trash status.
	 */
	public function test_needs_update_to_deactivate_price_cleared_synced_product(): void {
		$wc = new \WC_Product_Simple();
		$wc->set_name( 'Priced Simple' );
		$wc->set_regular_price( '20.00' );
		$wc->set_status( 'publish' );
		$wc->save();

		$synced = $this->seed_synced( $wc, 'fprod_price' );
		// Baseline: a fully synced catalog product needs nothing.
		self::assertSame( ResourceAction::NONE, Product::from_wc( $synced )->needs() );

		// Clear the price: still published, but no longer purchasable.
		$synced->set_regular_price( '' );
		$synced->set_price( '' );
		$synced->save();
		$reloaded = wc_get_product( $synced->get_id() );
		assert( $reloaded instanceof \WC_Product );

		self::assertSame( ResourceAction::UPDATE, Product::from_wc( $reloaded )->needs() );
	}

	/**
	 * A trashed product that was never synced to Flex should return NONE —
	 * there is nothing on the Flex side to deactivate.
	 */
	public function test_needs_none_for_trashed_unsynced_product(): void {
		$wc = new \WC_Product_Simple();
		$wc->set_name( 'Unsynced Simple' );
		$wc->set_regular_price( '20.00' );
		$wc->set_status( 'trash' );
		$wc->save();

		$reloaded = wc_get_product( $wc->get_id() );
		assert( $reloaded instanceof \WC_Product );

		self::assertSame( ResourceAction::NONE, Product::from_wc( $reloaded )->needs() );
	}
}
