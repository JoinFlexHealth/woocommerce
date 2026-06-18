<?php
/**
 * Tests for the inconsistent state created by duplicating a Flex-synced product.
 *
 * Reproduces the root cause behind MER-1371 / CON-873 (price_not_found at checkout):
 * WooCommerce's "Duplicate product" action deep-copies *all* post meta onto the
 * new product and its variations, including the Flex identity meta
 * (`_wc_flex_price_id`, `_wc_flex_product_id`, ...). The copy therefore claims a
 * Flex price that actually belongs to the original variation. Because Price::needs()
 * is local-only, the duplicate is never reconciled, and once the original's price is
 * recreated/deactivated on the Flex side the duplicate sends a now-invalid price ID.
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Tests;

/**
 * Tests that product duplication does not strand the original's Flex price ID
 * on the duplicated variation.
 */
class ProductDuplicationTest extends \WP_UnitTestCase {

	/**
	 * Duplicate a variable product the way the WooCommerce admin "Duplicate" action does.
	 *
	 * @param \WC_Product $product The product to duplicate.
	 */
	private function duplicate_product( \WC_Product $product ): \WC_Product {
		// The duplication logic lives in an admin-only class that is not loaded
		// outside the dashboard, so pull it in explicitly for the test.
		if ( ! class_exists( '\WC_Admin_Duplicate_Product' ) ) {
			require_once WC()->plugin_path() . '/includes/admin/class-wc-admin-duplicate-product.php';
		}

		$duplicate = ( new \WC_Admin_Duplicate_Product() )->product_duplicate( $product );
		self::assertInstanceOf( \WC_Product::class, $duplicate );

		return $duplicate;
	}

	/**
	 * Create a synced variable product (parent + one variation) carrying Flex
	 * identity meta, and return the re-fetched parent with its children loaded.
	 *
	 * The variation carries a fully-synced Flex price (id + reconciliation meta);
	 * the parent carries its Flex product id.
	 */
	private function create_synced_variable_product(): \WC_Product {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Membership' );
		$parent->update_meta_data( '_wc_flex_product_id', 'fprod_original' );
		$parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( '69.00' );
		$variation->update_meta_data( '_wc_flex_price_id', 'fprice_original' );
		$variation->update_meta_data( '_wc_flex_price_product', 'fprod_original' );
		$variation->update_meta_data( '_wc_flex_price_amount', '6900' );
		$variation->update_meta_data( '_wc_flex_price_hash', 'hash_original' );
		$variation->save();

		// Re-fetch so the parent's children are loaded; duplication reads
		// get_children() off the passed object, and our in-memory $parent was
		// saved before the variation existed.
		$parent = wc_get_product( $parent->get_id() );
		self::assertInstanceOf( \WC_Product::class, $parent );

		return $parent;
	}

	/**
	 * Return the single variation of a duplicated variable product.
	 *
	 * @param \WC_Product $duplicate The duplicated parent product.
	 */
	private function only_variation_of( \WC_Product $duplicate ): \WC_Product_Variation {
		// Re-fetch so we read the persisted children, not a stale in-memory cache.
		$duplicate = wc_get_product( $duplicate->get_id() );
		self::assertInstanceOf( \WC_Product::class, $duplicate );

		$children = $duplicate->get_children();
		self::assertCount( 1, $children, 'Duplicate should have one variation' );

		$variation = wc_get_product( $children[0] );
		self::assertInstanceOf( \WC_Product_Variation::class, $variation );

		return $variation;
	}

	/**
	 * A duplicated variation must not inherit the original variation's Flex price ID.
	 *
	 * The stored `_wc_flex_price_id` identifies a Flex price that belongs to the
	 * original variation. Copying it onto the duplicate puts the two products in an
	 * inconsistent state and is what ultimately surfaces as `price_not_found`.
	 */
	public function test_duplicating_product_does_not_copy_flex_price_id_to_variation(): void {
		$parent              = $this->create_synced_variable_product();
		$original_variation  = wc_get_product( $parent->get_children()[0] );
		$duplicate           = $this->duplicate_product( $parent );
		$duplicate_variation = $this->only_variation_of( $duplicate );

		// The original keeps its price id; the duplicate must NOT share it.
		self::assertInstanceOf( \WC_Product::class, $original_variation );
		self::assertSame(
			'fprice_original',
			$original_variation->get_meta( '_wc_flex_price_id' ),
			'Original variation should still own its Flex price id'
		);
		self::assertSame(
			'',
			$duplicate_variation->get_meta( '_wc_flex_price_id' ),
			'Duplicated variation must not inherit the original variation\'s Flex price id'
		);
	}

	/**
	 * Duplication must strip the *entire* Flex price meta set from the variation,
	 * not just the id — otherwise a stale product/amount/hash would still let the
	 * local-only needs() check treat the copy as already-synced.
	 */
	public function test_duplicating_product_strips_all_flex_price_meta_from_variation(): void {
		$duplicate_variation = $this->only_variation_of(
			$this->duplicate_product( $this->create_synced_variable_product() )
		);

		foreach ( array( '_wc_flex_price_id', '_wc_flex_price_product', '_wc_flex_price_amount', '_wc_flex_price_hash' ) as $key ) {
			self::assertSame(
				'',
				$duplicate_variation->get_meta( $key ),
				"Duplicated variation must not inherit {$key}"
			);
		}
	}

	/**
	 * The duplicated parent product must not inherit the original's Flex product ID,
	 * which would make two WooCommerce products claim the same Flex product.
	 */
	public function test_duplicating_product_does_not_copy_flex_product_id_to_parent(): void {
		$original  = $this->create_synced_variable_product();
		$duplicate = $this->duplicate_product( $original );

		// Re-fetch both from the database so we assert on persisted state, and so
		// duplicating cannot be seen to have mutated the original in memory.
		$original  = wc_get_product( $original->get_id() );
		$duplicate = wc_get_product( $duplicate->get_id() );
		self::assertInstanceOf( \WC_Product::class, $original );
		self::assertInstanceOf( \WC_Product::class, $duplicate );

		self::assertSame(
			'fprod_original',
			$original->get_meta( '_wc_flex_product_id' ),
			'Original parent should still own its Flex product id'
		);
		self::assertSame(
			'',
			$duplicate->get_meta( '_wc_flex_product_id' ),
			'Duplicated parent must not inherit the original\'s Flex product id'
		);
	}
}
