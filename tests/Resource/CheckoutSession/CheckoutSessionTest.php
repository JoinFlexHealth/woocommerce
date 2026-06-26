<?php
/**
 * Tests for the CheckoutSession::needs() method
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Tests\Resource\CheckoutSession;

use Flex\Resource\CheckoutSession\CheckoutSession;
use Flex\Resource\CheckoutSession\Discount;
use Flex\Resource\CheckoutSession\LineItem;
use Flex\Resource\CheckoutSession\Status;
use Flex\Resource\ResourceAction;
use phpmock\phpunit\PHPMock;

/**
 * Test the CheckoutSession::needs() method.
 *
 * The needs() method determines what action should be taken for a checkout session:
 * - DEPENDENCY: If any line items or discounts need action
 * - NONE: If the status is COMPLETE
 * - CREATE: Otherwise (status is OPEN, null, or any other non-COMPLETE value)
 */
class CheckoutSessionTest extends \WP_UnitTestCase {

	use PHPMock;

	/**
	 * Define the namespaced function_exists() override up front.
	 *
	 * The php-mock library works via PHP's namespace fallback, and the override must
	 * exist before the first unqualified call to function_exists() in this namespace
	 * — which other tests here trigger via from_wc(). Defining it now keeps it inert
	 * (delegating to the real function) until a test enables a mock on it.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::defineFunctionMock( 'Flex\\Resource\\CheckoutSession', 'function_exists' );
	}

	/**
	 * Test that needs() returns NONE when status is COMPLETE.
	 */
	public function test_needs_returns_none_when_status_is_complete(): void {
		$checkout_session = new CheckoutSession(
			success_url: 'https://example.com/success',
			status: Status::COMPLETE,
		);

		self::assertSame( ResourceAction::NONE, $checkout_session->needs() );
	}

	/**
	 * Test that needs() returns CREATE when status is OPEN.
	 */
	public function test_needs_returns_create_when_status_is_open(): void {
		$checkout_session = new CheckoutSession(
			success_url: 'https://example.com/success',
			status: Status::OPEN,
		);

		self::assertSame( ResourceAction::CREATE, $checkout_session->needs() );
	}

	/**
	 * Test that needs() returns CREATE when status is null.
	 */
	public function test_needs_returns_create_when_status_is_null(): void {
		$checkout_session = new CheckoutSession(
			success_url: 'https://example.com/success',
			status: null,
		);

		self::assertSame( ResourceAction::CREATE, $checkout_session->needs() );
	}

	/**
	 * Test that needs() returns DEPENDENCY when a line item needs action.
	 */
	public function test_needs_returns_dependency_when_line_item_needs_action(): void {
		$line_item = $this->createStub( LineItem::class );
		$line_item->method( 'needs' )->willReturn( ResourceAction::CREATE );

		$checkout_session = new CheckoutSession(
			success_url: 'https://example.com/success',
			line_items: array( $line_item ),
			status: Status::OPEN,
		);

		self::assertSame( ResourceAction::DEPENDENCY, $checkout_session->needs() );
	}

	/**
	 * Test that needs() returns DEPENDENCY when a discount needs action.
	 */
	public function test_needs_returns_dependency_when_discount_needs_action(): void {
		$discount = $this->createStub( Discount::class );
		$discount->method( 'needs' )->willReturn( ResourceAction::DEPENDENCY );

		$checkout_session = new CheckoutSession(
			success_url: 'https://example.com/success',
			status: Status::OPEN,
			discounts: array( $discount ),
		);

		self::assertSame( ResourceAction::DEPENDENCY, $checkout_session->needs() );
	}

	/**
	 * Test that dependencies are checked before status.
	 *
	 * Even if the status is COMPLETE, if a line item needs action,
	 * the result should be DEPENDENCY (not NONE).
	 */
	public function test_needs_checks_dependencies_before_status(): void {
		$line_item = $this->createStub( LineItem::class );
		$line_item->method( 'needs' )->willReturn( ResourceAction::DEPENDENCY );

		$checkout_session = new CheckoutSession(
			success_url: 'https://example.com/success',
			line_items: array( $line_item ),
			status: Status::COMPLETE,
		);

		self::assertSame(
			ResourceAction::DEPENDENCY,
			$checkout_session->needs(),
			'Dependencies should be checked before status'
		);
	}

	/**
	 * A WooCommerce Product Bundle order lists the bundle container alongside its
	 * bundled children. Children whose price is rolled into the container carry a
	 * $0 line subtotal and a `_bundled_by` meta. They duplicate the bundle in the
	 * Flex checkout (and show up as "FREE"), so from_wc() must drop them and keep
	 * only the priced container — matching what WooCommerce shows the shopper.
	 */
	public function test_from_wc_excludes_free_bundled_children(): void {
		$order = wc_create_order();
		self::assertInstanceOf( \WC_Order::class, $order );

		// The bundle container carries the full price.
		$bundle = new class() extends \WC_Product_Simple {
			/**
			 * Returns the product type slug.
			 *
			 * @return string
			 */
			public function get_type() {
				return 'bundle';
			}
		};
		$bundle->set_name( 'Hoodie Bundle' );
		$bundle->set_regular_price( '50.00' );
		$bundle->set_status( 'publish' );
		$bundle->save();

		$container_id   = $order->add_product( $bundle, 1 );
		$container_item = $order->get_item( $container_id );
		assert( $container_item instanceof \WC_Order_Item_Product );
		$container_key = md5( (string) $container_id );
		$container_item->add_meta_data( '_bundled_items', array( $container_key ), true );
		$container_item->add_meta_data( '_bundle_cart_key', $container_key, true );
		$container_item->save();

		// Two bundled children whose price is rolled into the container ($0 subtotal).
		foreach ( array( 'Hoodie - Blue', 'Hoodie with Zipper' ) as $name ) {
			$child = new \WC_Product_Simple();
			$child->set_name( $name );
			$child->set_regular_price( '20.00' );
			$child->set_status( 'publish' );
			$child->save();

			$child_id   = $order->add_product( $child, 1 );
			$child_item = $order->get_item( $child_id );
			assert( $child_item instanceof \WC_Order_Item_Product );
			$child_item->set_subtotal( '0' );
			$child_item->set_total( '0' );
			$child_item->add_meta_data( '_bundled_by', $container_key, true );
			$child_item->save();
		}

		$order->save();

		// Reload from the data store so the line items reflect the saved $0
		// subtotals, mirroring how from_wc() receives orders in the gateway.
		$reloaded = wc_get_order( $order->get_id() );
		assert( $reloaded instanceof \WC_Order );

		$line_items = CheckoutSession::from_wc( $reloaded )->line_items();

		// Only the bundle should remain — not the two $0 children.
		self::assertCount( 1, $line_items );
		self::assertSame( 5000, $line_items[0]->price()->jsonSerialize()['unit_amount'] );
	}

	/**
	 * A standalone free product ($0) is NOT a bundle child, so it is kept. Only the
	 * Product Bundles detector decides what gets dropped; a plain $0 line is left
	 * alone (it contributes nothing to the total either way). This guards against
	 * regressing to a blunt "drop every $0 line" rule.
	 */
	public function test_from_wc_keeps_standalone_free_products(): void {
		$order = wc_create_order();
		self::assertInstanceOf( \WC_Order::class, $order );

		$paid = new \WC_Product_Simple();
		$paid->set_name( 'Paid Item' );
		$paid->set_regular_price( '40.00' );
		$paid->set_status( 'publish' );
		$paid->save();
		$order->add_product( $paid, 1 );

		$free = new \WC_Product_Simple();
		$free->set_name( 'Free Sample' );
		$free->set_regular_price( '0' );
		$free->set_status( 'publish' );
		$free->save();
		$order->add_product( $free, 1 );

		$order->calculate_totals();
		$order->save();

		$reloaded = wc_get_order( $order->get_id() );
		assert( $reloaded instanceof \WC_Order );
		$line_items = CheckoutSession::from_wc( $reloaded )->line_items();

		// Both the paid and the free standalone product remain.
		self::assertCount( 2, $line_items );
	}

	/**
	 * An item discounted to a $0 *total* (e.g. a 100%-off coupon) keeps a non-zero
	 * *subtotal*, so it must remain a line item: its price still counts toward the
	 * order total and the discount is modelled separately. Keying the exclusion on
	 * the total instead of the subtotal would drop it and break reconciliation.
	 */
	public function test_from_wc_keeps_items_discounted_to_zero_total(): void {
		$order = wc_create_order();
		self::assertInstanceOf( \WC_Order::class, $order );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Discounted Item' );
		$product->set_regular_price( '40.00' );
		$product->set_status( 'publish' );
		$product->save();

		$item_id = $order->add_product( $product, 1 );
		$item    = $order->get_item( $item_id );
		assert( $item instanceof \WC_Order_Item_Product );
		// Subtotal (pre-discount) stays $40; total is discounted to $0.
		$item->set_subtotal( '40' );
		$item->set_total( '0' );
		$item->save();
		$order->save();

		$reloaded = wc_get_order( $order->get_id() );
		assert( $reloaded instanceof \WC_Order );
		$line_items = CheckoutSession::from_wc( $reloaded )->line_items();

		self::assertCount( 1, $line_items );
	}

	/**
	 * A bundle whose children are priced individually carries the child prices on
	 * the child line items (non-zero subtotals), not on the container. Those
	 * children must be kept, otherwise the Flex line items would no longer sum to
	 * the WooCommerce order total and the gateway would reject the payment
	 * (see PaymentGateway::process_payment). Only $0 children are dropped, so the
	 * total always reconciles regardless of the bundle's pricing mode.
	 */
	public function test_from_wc_keeps_individually_priced_bundled_children(): void {
		$order = wc_create_order();
		self::assertInstanceOf( \WC_Order::class, $order );

		// The container carries only its own base price.
		$bundle = new class() extends \WC_Product_Simple {
			/**
			 * Returns the product type slug.
			 *
			 * @return string
			 */
			public function get_type() {
				return 'bundle';
			}
		};
		$bundle->set_name( 'Build-A-Box' );
		$bundle->set_regular_price( '10.00' );
		$bundle->set_status( 'publish' );
		$bundle->save();

		$container_id   = $order->add_product( $bundle, 1 );
		$container_item = $order->get_item( $container_id );
		assert( $container_item instanceof \WC_Order_Item_Product );
		$container_key = md5( (string) $container_id );
		$container_item->add_meta_data( '_bundled_items', array( $container_key ), true );
		$container_item->save();

		// Children priced individually keep their own non-zero subtotal.
		foreach ( array( 'Add-on A', 'Add-on B' ) as $name ) {
			$child = new \WC_Product_Simple();
			$child->set_name( $name );
			$child->set_regular_price( '20.00' );
			$child->set_status( 'publish' );
			$child->save();

			$child_id   = $order->add_product( $child, 1 );
			$child_item = $order->get_item( $child_id );
			assert( $child_item instanceof \WC_Order_Item_Product );
			$child_item->add_meta_data( '_bundled_by', $container_key, true );
			$child_item->save();
		}

		$order->calculate_totals();
		$order->save();

		$reloaded = wc_get_order( $order->get_id() );
		assert( $reloaded instanceof \WC_Order );
		$session = CheckoutSession::from_wc( $reloaded );

		// All three priced items are retained, and they sum to the order total.
		$line_items = $session->line_items();
		self::assertCount( 3, $line_items );

		$line_total = array_sum(
			array_map(
				static fn( LineItem $li ) => ( $li->price()->jsonSerialize()['unit_amount'] ?? 0 ) * $li->quantity(),
				$line_items,
			)
		);
		self::assertSame( 5000, $line_total );
		self::assertSame( 5000, $session->amount_total() );
	}

	/**
	 * When the (proprietary) Product Bundles plugin is not active, its detector
	 * function is undefined and the function_exists() guard short-circuits, so no
	 * line item is treated as a bundle child. A bundle order's $0 children are then
	 * left in place — from_wc() must never drop them based on price alone.
	 *
	 * Product Bundles is not installed in CI, so the guard is exercised by mocking
	 * function_exists() (via php-mock's namespace fallback) to report the detector
	 * as missing, complementing the plugin-active path the other bundle tests cover.
	 */
	public function test_from_wc_keeps_bundled_children_when_bundles_plugin_inactive(): void {
		$function_exists = $this->getFunctionMock( 'Flex\\Resource\\CheckoutSession', 'function_exists' );
		$function_exists->expects( self::any() )->willReturnCallback(
			static fn( string $name ): bool => 'wc_pb_is_bundled_order_item' !== $name && \function_exists( $name )
		);

		$order = wc_create_order();
		self::assertInstanceOf( \WC_Order::class, $order );

		$bundle = new class() extends \WC_Product_Simple {
			/**
			 * Returns the product type slug.
			 *
			 * @return string
			 */
			public function get_type() {
				return 'bundle';
			}
		};
		$bundle->set_name( 'Hoodie Bundle' );
		$bundle->set_regular_price( '50.00' );
		$bundle->set_status( 'publish' );
		$bundle->save();

		$container_id   = $order->add_product( $bundle, 1 );
		$container_item = $order->get_item( $container_id );
		assert( $container_item instanceof \WC_Order_Item_Product );
		$container_key = md5( (string) $container_id );
		$container_item->add_meta_data( '_bundled_items', array( $container_key ), true );
		$container_item->save();

		foreach ( array( 'Hoodie - Blue', 'Hoodie with Zipper' ) as $name ) {
			$child = new \WC_Product_Simple();
			$child->set_name( $name );
			$child->set_regular_price( '20.00' );
			$child->set_status( 'publish' );
			$child->save();

			$child_id   = $order->add_product( $child, 1 );
			$child_item = $order->get_item( $child_id );
			assert( $child_item instanceof \WC_Order_Item_Product );
			$child_item->set_subtotal( '0' );
			$child_item->set_total( '0' );
			$child_item->add_meta_data( '_bundled_by', $container_key, true );
			$child_item->save();
		}

		$order->save();

		$reloaded = wc_get_order( $order->get_id() );
		assert( $reloaded instanceof \WC_Order );
		$line_items = CheckoutSession::from_wc( $reloaded )->line_items();

		// Detector unavailable → nothing dropped: the container and both $0 children remain.
		self::assertCount( 3, $line_items );
	}

	/**
	 * Test that needs() returns NONE when status is COMPLETE and no dependencies need action.
	 */
	public function test_needs_returns_none_when_complete_with_satisfied_dependencies(): void {
		$line_item = $this->createStub( LineItem::class );
		$line_item->method( 'needs' )->willReturn( ResourceAction::NONE );

		$discount = $this->createStub( Discount::class );
		$discount->method( 'needs' )->willReturn( ResourceAction::NONE );

		$checkout_session = new CheckoutSession(
			success_url: 'https://example.com/success',
			line_items: array( $line_item ),
			status: Status::COMPLETE,
			discounts: array( $discount ),
		);

		self::assertSame( ResourceAction::NONE, $checkout_session->needs() );
	}
}
