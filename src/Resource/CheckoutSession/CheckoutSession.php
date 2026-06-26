<?php
/**
 * Flex Checkout Session
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Resource\CheckoutSession;

use Flex\Controller\Controller;
use Flex\Exception\FlexException;
use Flex\Exception\FlexResponseException;
use Flex\Resource\Coupon;
use Flex\Resource\Resource;
use Flex\Resource\ResourceAction;

/**
 * Flex Checkout Session
 */
class CheckoutSession extends Resource {

	protected const KEY_STATUS       = 'checkout_session_status';
	protected const KEY_REDIRECT_URL = 'checkout_session_redirect_url';
	protected const KEY_TEST_MODE    = 'checkout_session_test_mode';

	/**
	 * WooCommerce Order
	 *
	 * @var ?\WC_Order
	 */
	protected ?\WC_Order $wc = null;

	/**
	 * Creates a checkout session.
	 *
	 * @param string            $success_url The url to redirect users back too upon success.
	 * @param ?CustomerDefaults $defaults The customer defaults.
	 * @param string            $redirect_url The url that WooCommerce needs to redirect the user to in order to complete payment.
	 * @param LineItem[]        $line_items The line items for the checkout session.
	 * @param ?string           $id The id of the checkout session.
	 * @param ?string           $client_reference_id The client reference id, which is the WooCommerce order id.
	 * @param ?int              $amount_total The total amount of the checkout session.
	 * @param ?Mode             $mode The mode of the checkout session.
	 * @param ?Status           $status The status of the checkout session.
	 * @param ?bool             $test_mode If the checkout session was created in test mode.
	 * @param ?ShippingOptions  $shipping_options The shipping options if there are any.
	 * @param ?TaxRate          $tax_rate The tax if there is one.
	 * @param ?string           $cancel_url The url to use to cancel the checkout session.
	 * @param Discount[]        $discounts The discounts to apply to the checkout session.
	 * @param Fee[]             $fees The fees to apply to the checkout session.
	 */
	public function __construct(
		protected string $success_url,
		protected ?CustomerDefaults $defaults = null,
		protected ?string $redirect_url = null,
		protected array $line_items = array(),
		protected ?string $id = null,
		protected ?string $client_reference_id = null,
		protected ?int $amount_total = null,
		protected ?Mode $mode = Mode::PAYMENT,
		protected ?Status $status = null,
		protected ?bool $test_mode = null,
		protected ?ShippingOptions $shipping_options = null,
		protected ?TaxRate $tax_rate = null,
		protected ?string $cancel_url = null,
		protected array $discounts = array(),
		protected array $fees = array(),
	) {
	}

	/**
	 * {@inheritdoc}
	 */
	public function id(): ?string {
		return $this->id;
	}

	/**
	 * Status of the checkout session.
	 */
	public function status(): ?Status {
		return $this->status;
	}

	/**
	 * Returns the redirect url for the checkout session.
	 */
	public function redirect_url(): ?string {
		return $this->redirect_url;
	}

	/**
	 * Returns the amount total for the checkout session.
	 */
	public function amount_total(): ?int {
		return $this->amount_total;
	}

	/**
	 * Returns the line items for the checkout session.
	 *
	 * @return LineItem[]
	 */
	public function line_items(): array {
		return $this->line_items;
	}

	/**
	 * Returns whether the checkout session is in test mode or not.
	 */
	public function test_mode(): ?bool {
		return $this->test_mode;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Only serialize properties where WooCommerce is the system of record.
	 *
	 * @return array{
	 *     defaults: ?CustomerDefaults,
	 *     success_url: string,
	 *     line_items: LineItem[],
	 *     client_reference_id: ?string,
	 *     mode?: string,
	 *     cancel_url: ?string,
	 *     shipping_options?: ShippingOptions,
	 *     tax_rate?: TaxRate,
	 *     discounts?: Discount[],
	 *     fees?: Fee[],
	 * }
	 */
	public function jsonSerialize(): array {
		$data = array(
			'defaults'            => $this->defaults,
			'success_url'         => $this->success_url,
			'line_items'          => $this->line_items,
			'client_reference_id' => $this->client_reference_id,
			'cancel_url'          => $this->cancel_url,
		);

		$mode = $this->mode?->value;
		if ( null !== $mode ) {
			$data['mode'] = $mode;
		}

		if ( null !== $this->shipping_options ) {
			$data['shipping_options'] = $this->shipping_options;
		}

		if ( null !== $this->tax_rate ) {
			$data['tax_rate'] = $this->tax_rate;
		}

		if ( array() !== $this->discounts ) {
			$data['discounts'] = $this->discounts;
		}

		if ( array() !== $this->fees ) {
			$data['fees'] = $this->fees;
		}

		return $data;
	}

	/**
	 * Creates a Checkout Session from a WooCommerce Order.
	 *
	 * @param \WC_Order $order the WooCommerce Order.
	 */
	public static function from_wc( \WC_Order $order ): self {
		$id          = $order->get_transaction_id();
		$order_id    = $order->get_id();
		$success_url = add_query_arg(
			'key',
			$order->get_order_key(),
			get_rest_url(
				path: Controller::NAMESPACE . "/orders/$order_id/complete",
			),
		);

		// A map of item_id => LineItem. Bundled children whose price is rolled into
		// their container are skipped: they duplicate the bundle in the Flex checkout
		// and would show as free. See is_bundled_free_item() for how they're detected.
		$product_items = array_filter(
			$order->get_items(),
			static fn( $item ) => $item instanceof \WC_Order_Item_Product && ! self::is_bundled_free_item( $item ),
		);
		$line_items    = array_map( static fn( \WC_Order_Item_Product $item ) => LineItem::from_wc( $item ), $product_items );

		$tax_rate = TaxRate::from_wc( $order );

		// Recreate the discounts so we can get a line-item level discount.
		$wc_discounts = new \WC_Discounts( $order );
		foreach ( $order->get_coupons() as $applied ) {
			// We do *not* verify the coupons because they have already been verified when they were placed on the order.
			// Applying the coupons again _should_ be deterministic.
			$wc_discounts->apply_coupon( new \WC_Coupon( $applied->get_code() ), false );
		}

		// Group the discounts by the code → amount → line item
		// If the discount is spread evenly across line items, we can share the discount in Flex.
		$discounts_grouped = array();
		/**
		 * Discount amounts grouped by coupon code and line item.
		 *
		 * @var array<string, array<string|int, float|string>> $wc_discount_items
		 */
		$wc_discount_items = $wc_discounts->get_discounts();
		foreach ( $wc_discount_items as $code => $items ) {
			foreach ( $items as $item_id => $amount ) {
				$discounts_grouped[ $code ][ self::currency_to_unit_amount( $amount ) ][] = $item_id;
			}
		}

		$discounts = array();
		foreach ( $discounts_grouped as $code => $group ) {
			foreach ( $group as $per_item_amount => $item_ids ) {
				$amount_off = $per_item_amount * count( $item_ids );
				if ( 0 === $amount_off ) {
					continue;
				}

				$discounts[] = new Discount(
					new Coupon(
						name: $code,
						amount_off: $amount_off,
						applies_to: array_map( static fn( string|int $item_id ) => $line_items[ $item_id ]->price(), $item_ids ),
					)
				);
			}
		}

		// Add the sale price discounts.
		foreach ( $product_items as $item ) {
			$product = $item->get_product();
			if ( ! $product instanceof \WC_Product || $product->get_price() !== $product->get_sale_price() ) {
				continue;
			}

			$coupon = Coupon::from_wc( $product );
			if ( null === $coupon->amount_off() || 0 === $coupon->amount_off() ) {
				continue;
			}

			$quantity = $item->get_quantity();

			// Add a discount for each individual item.
			for ( $i = 0; $i < $quantity; $i++ ) {
				$discounts[] = new Discount( $coupon );
			}
		}

		$fees = array_map( static fn( $f ) => Fee::from_wc( $f ), array_values( $order->get_fees() ) );

		$redirect_meta  = $order->get_meta( self::META_PREFIX . self::KEY_REDIRECT_URL );
		$status_meta    = $order->get_meta( self::META_PREFIX . self::KEY_STATUS );
		$test_mode_meta = $order->get_meta( self::META_PREFIX . self::KEY_TEST_MODE );

		$checkout_session = new self(
			success_url: $success_url,
			defaults: CustomerDefaults::from_wc( $order ),
			redirect_url: $order->meta_exists( self::META_PREFIX . self::KEY_REDIRECT_URL ) && is_string( $redirect_meta ) ? $redirect_meta : null,
			id: '' !== $id ? $id : null,
			client_reference_id: (string) $order->get_id(),
			status: is_string( $status_meta ) ? Status::tryFrom( $status_meta ) : null,
			mode: Mode::PAYMENT,
			line_items: array_values( $line_items ),
			amount_total: self::currency_to_unit_amount( $order->get_total() ),
			test_mode: $order->meta_exists( self::META_PREFIX . self::KEY_TEST_MODE ) && is_string( $test_mode_meta ) ? wc_string_to_bool( $test_mode_meta ) : self::payment_gateway()->is_in_test_mode(),
			shipping_options: array() !== $order->get_shipping_methods() ? ShippingOptions::from_wc( $order ) : null,
			tax_rate: $tax_rate->amount() > 0 ? $tax_rate : null,
			cancel_url: wc_get_checkout_url(),
			discounts: $discounts,
			fees: $fees,
		);

		$checkout_session->wc = $order;

		return $checkout_session;
	}

	/**
	 * Whether an order item is a bundled child whose price is rolled into its
	 * bundle container, leaving it with a $0 line subtotal.
	 *
	 * WooCommerce Product Bundles adds a bundle's children to the order as their own
	 * line items; children that are not priced individually have their price counted
	 * in the container, so their subtotal is $0. Those duplicate the container in the
	 * Flex checkout and would appear as free, so they are skipped. Individually-priced
	 * children carry a real subtotal and are kept, so the line items still sum to the
	 * order total — keying on the *subtotal* (pre-discount) guarantees that dropping
	 * an item never changes any total.
	 *
	 * Detection uses Product Bundles' public API behind a function_exists() guard, so
	 * the branch is inert when the (proprietary) plugin is not active — matching how
	 * WooCommerce's own first-party integrations gate on a partner plugin. Standalone
	 * free products are therefore kept; only genuine bundle children are dropped.
	 *
	 * @link https://woocommerce.com/document/bundles/bundles-functions-reference/#h-wc-pb-is-bundled-order-item
	 *       bool wc_pb_is_bundled_order_item( WC_Order_Item $order_item, WC_Order $order = false )
	 *
	 * @param \WC_Order_Item_Product $item The WooCommerce Order Item.
	 */
	private static function is_bundled_free_item( \WC_Order_Item_Product $item ): bool {
		return function_exists( 'wc_pb_is_bundled_order_item' )
			&& wc_pb_is_bundled_order_item( $item )
			&& 0 === self::currency_to_unit_amount( $item->get_subtotal() );
	}

	/**
	 * Returns the WooCommerce order associated with this Checkout Session, if there is one.
	 */
	public function wc(): ?\WC_Order {
		if ( null === $this->wc && null !== $this->id ) {
			$orders = wc_get_orders(
				array(
					'transaction_id' => $this->id,
				)
			);

			if ( is_array( $orders ) && array() !== $orders ) {
				$this->wc = array_shift( $orders );
			}
		}

		return $this->wc;
	}

	/**
	 * Applies the product data onto a WooCommerce Order.
	 * This is effectively the opposite of {@link Order::from_wc}.
	 *
	 * @param \WC_Order $order The WooCommerce Order.
	 */
	public function apply_to( \WC_Order $order ): void {
		$order->set_transaction_id( $this->id ?? '' );

		if ( null === $this->redirect_url ) {
			$order->delete_meta_data( self::META_PREFIX . self::KEY_REDIRECT_URL );
		} else {
			$order->update_meta_data( self::META_PREFIX . self::KEY_REDIRECT_URL, $this->redirect_url );
		}

		$status = $this->status?->value;
		if ( null === $status ) {
			$order->delete_meta_data( self::META_PREFIX . self::KEY_STATUS );
		} else {
			$order->update_meta_data( self::META_PREFIX . self::KEY_STATUS, $status );
		}

		if ( null === $this->test_mode ) {
			$order->delete_meta_data( self::META_PREFIX . self::KEY_TEST_MODE );
		} else {
			$order->update_meta_data( self::META_PREFIX . self::KEY_TEST_MODE, wc_bool_to_string( $this->test_mode ) );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function needs(): ResourceAction {
		// Handle any dependency actions first.
		if ( array_any( $this->line_items, static fn( $line_item ) => $line_item->needs() !== ResourceAction::NONE ) ) {
			return ResourceAction::DEPENDENCY;
		}

		if ( array_any( $this->discounts, static fn( $discount ) => $discount->needs() !== ResourceAction::NONE ) ) {
			return ResourceAction::DEPENDENCY;
		}

		if ( Status::COMPLETE === $this->status ) {
			return ResourceAction::NONE;
		}

		return ResourceAction::CREATE;
	}

	/**
	 * Extract the values from a checkout session API response.
	 *
	 * @param array<string, mixed> $checkout_session The checkout session object returned from the API.
	 *
	 * @throws FlexException If data is missing.
	 */
	public static function from_flex( array $checkout_session ): self {
		if ( ! isset( $checkout_session['success_url'] ) || ! is_string( $checkout_session['success_url'] ) ) {
			throw new FlexException( 'Success URL missing from checkout session.' );
		}

		$cs = new self( success_url: $checkout_session['success_url'] );
		$cs->extract( $checkout_session );
		return $cs;
	}

	/**
	 * Extract the values from a checkout session API response.
	 *
	 * @param array<string, mixed> $checkout_session The checkout session object returned from the API.
	 *
	 * @throws \Exception If data is missing.
	 */
	protected function extract( array $checkout_session ): void {
		$this->id                  = isset( $checkout_session['checkout_session_id'] ) && is_string( $checkout_session['checkout_session_id'] ) ? $checkout_session['checkout_session_id'] : $this->id;
		$this->success_url         = isset( $checkout_session['success_url'] ) && is_string( $checkout_session['success_url'] ) ? $checkout_session['success_url'] : $this->success_url;
		$this->redirect_url        = isset( $checkout_session['redirect_url'] ) && is_string( $checkout_session['redirect_url'] ) ? $checkout_session['redirect_url'] : $this->redirect_url;
		$this->client_reference_id = isset( $checkout_session['client_reference_id'] ) && is_string( $checkout_session['client_reference_id'] ) ? $checkout_session['client_reference_id'] : $this->client_reference_id;
		$this->amount_total        = isset( $checkout_session['amount_total'] ) && is_int( $checkout_session['amount_total'] ) ? $checkout_session['amount_total'] : $this->amount_total;
		$this->test_mode           = isset( $checkout_session['test_mode'] ) && is_bool( $checkout_session['test_mode'] ) ? $checkout_session['test_mode'] : $this->test_mode;
		$this->cancel_url          = isset( $checkout_session['cancel_url'] ) && is_string( $checkout_session['cancel_url'] ) ? $checkout_session['cancel_url'] : $this->cancel_url;

		$mode       = $checkout_session['mode'] ?? '';
		$this->mode = is_string( $mode ) ? ( Mode::tryFrom( $mode ) ?? $this->mode ) : $this->mode;

		$status       = $checkout_session['status'] ?? '';
		$this->status = is_string( $status ) ? ( Status::tryFrom( $status ) ?? $this->status ) : $this->status;

		if ( isset( $checkout_session['defaults'] ) && is_array( $checkout_session['defaults'] ) ) {
			/**
			 * Customer defaults data.
			 *
			 * @var array<string, mixed> $defaults_data
			 */
			$defaults_data  = $checkout_session['defaults'];
			$this->defaults = CustomerDefaults::from_flex( $defaults_data );
		}

		if ( isset( $checkout_session['shipping_options'] ) && is_array( $checkout_session['shipping_options'] ) ) {
			/**
			 * Shipping options data.
			 *
			 * @var array<string, mixed> $shipping_data
			 */
			$shipping_data          = $checkout_session['shipping_options'];
			$this->shipping_options = ShippingOptions::from_flex( $shipping_data );
		}

		if ( isset( $checkout_session['tax_rate'] ) && is_array( $checkout_session['tax_rate'] ) ) {
			/**
			 * Tax rate data.
			 *
			 * @var array<string, mixed> $tax_data
			 */
			$tax_data       = $checkout_session['tax_rate'];
			$this->tax_rate = TaxRate::from_flex( $tax_data );
		}

		if ( isset( $checkout_session['fees'] ) && is_array( $checkout_session['fees'] ) ) {
			/**
			 * Fee line items data.
			 *
			 * @var list<array<string, mixed>> $fees_data
			 */
			$fees_data  = $checkout_session['fees'];
			$this->fees = array_map( static fn ( array $f ) => Fee::from_flex( $f ), $fees_data );
		} else {
			$this->fees = array();
		}
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param ResourceAction $action The action to check.
	 */
	public function can( ResourceAction $action ): bool {
		return match ( $action ) {
			ResourceAction::CREATE, ResourceAction::DEPENDENCY => true,
			ResourceAction::REFRESH => null !== $this->id,
			default => false,
		};
	}

	/**
	 * Creates or updates a Checkout Session with the Flex API.
	 *
	 * @param ResourceAction $action The operation to perform.
	 *
	 * @throws FlexException If anything goes wrong.
	 * @throws FlexResponseException If the API responds with an error that cannot be recovered.
	 */
	public function exec( ResourceAction $action ): void {
		if ( ! $this->can( $action ) ) {
			return;
		}

		// Save the dependencies before attempting to re-create the checkout session.
		if ( ResourceAction::DEPENDENCY === $action ) {
			foreach ( $this->line_items as $line_item ) {
				$line_item->exec( $line_item->needs() );
			}

			foreach ( $this->discounts as $discount ) {
				$discount->exec( $discount->needs() );
			}

			// Re-evaluate the action.
			$this->exec( $this->needs() );
			return;
		}

		$path = match ( $action ) {
			ResourceAction::CREATE => '/v1/checkout/sessions',
			default => '/v1/checkout/sessions/' . $this->id,
		};
		$args = array(
			'method' => match ( $action ) {
				ResourceAction::CREATE => 'POST',
				default => 'GET',
			},
			'flex'   => match ( $action ) {
				ResourceAction::CREATE => array( 'data' => array( 'checkout_session' => $this ) ),
				default => array(),
			},
		);

		try {
			$data = $this->remote_request( $path, $args );
		} catch ( FlexResponseException $e ) {
			// Recover from a stale price reference. A line item's stored price id no
			// longer resolves on the Flex side (e.g. the product was duplicated, or
			// its price was recreated and the old one deactivated), so the create
			// returns 422 price_not_found. needs() is local-only and never caught it.
			// The error does not name the offending price, so recreate every line
			// item price and retry the create once. A second failure propagates. See MER-1371.
			// code() returns wp_remote_retrieve_response_code() verbatim, which may be
			// a numeric string or an int, so compare as int.
			if ( ResourceAction::CREATE !== $action
				|| 422 !== (int) $e->code()
				|| 'price_not_found' !== $e->errorType() ) {
				throw $e;
			}

			foreach ( $this->line_items as $line_item ) {
				$line_item->price()->exec( ResourceAction::CREATE );
			}

			// $this re-serializes with the recreated price ids on this retry.
			$data = $this->remote_request( $path, $args );
		}

		if ( ! isset( $data['checkout_session'] ) || ! is_array( $data['checkout_session'] ) ) {
			throw new FlexException( 'Missing checkout_session in response.' );
		}

		/**
		 * The checkout session data from the API response.
		 *
		 * @var array<string, mixed> $cs_data
		 */
		$cs_data = $data['checkout_session'];
		$this->extract( $cs_data );

		if ( null !== $this->wc ) {
			$this->apply_to( $this->wc );

			foreach ( $this->line_items as $line_item ) {
				$line_item->apply();
			}

			$this->wc?->save();
		}
	}
}
