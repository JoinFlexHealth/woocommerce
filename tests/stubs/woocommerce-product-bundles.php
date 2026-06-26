<?php
/**
 * Stub for the proprietary WooCommerce Product Bundles plugin.
 *
 * Product Bundles is a paid extension that is not installed in CI, so its public
 * API is unavailable to PHPStan and PHPUnit. This file declares the one function we
 * call so static analysis can verify our usage against the documented signature,
 * and so tests can exercise the bundle-detection branch. It is wired into PHPStan
 * via `stubFiles` and loaded at runtime by tests/bootstrap.php (only when the real
 * plugin is absent). The real function is provided by the plugin in production.
 *
 * @see \Flex\Resource\CheckoutSession\CheckoutSession::is_bundled_free_item()
 *
 * @package Flex
 */

/**
 * True if an order item is part of a bundle.
 *
 * Stand-in for the proprietary Product Bundles function. The real implementation
 * additionally validates that the parent container exists in the order; this stub
 * keys on the documented `_bundled_by` child meta, which is enough to drive our
 * filtering tests. Keep the signature in sync with the upstream reference below.
 *
 * Upstream signature: bool wc_pb_is_bundled_order_item( WC_Order_Item $order_item, WC_Order $order = false ).
 * Typed loosely here (object) so the stub resolves without the proprietary plugin's
 * classes; see the reference link for the authoritative types.
 *
 * @link https://woocommerce.com/document/bundles/bundles-functions-reference/#h-wc-pb-is-bundled-order-item
 *
 * @param object       $order_item The order item to check (a WC_Order_Item).
 * @param object|false $order      The order the item belongs to (a WC_Order); derived from the item when false.
 * @return bool
 */
function wc_pb_is_bundled_order_item( $order_item, $order = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	$bundled_by = $order_item->get_meta( '_bundled_by' );
	return is_string( $bundled_by ) && '' !== $bundled_by;
}
