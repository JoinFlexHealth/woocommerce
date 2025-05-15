<?php
/**
 * Flex Checkout Session
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Resource;

use Flex\Controller\Controller;
use Flex\Exception\FlexException;

/**
 * Flex Checkout Session
 */
class CheckoutSession extends Resource implements ResourceInterface {

	protected const KEY_STATUS       = 'checkout_session_status';
	protected const KEY_REDIRECT_URL = 'checkout_session_redirect_url';
	protected const KEY_HASH         = 'checkout_session_hash';
	protected const KEY_AMOUNT_TOTAL = 'checkout_session_amount_total';
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
	 * @param string                          $success_url The url to redirect users back too upon success.
	 * @param ?CustomerDefaults               $defaults The customer defaults.
	 * @param string                          $redirect_url The url that WooCommerce needs to redirect the user to in order to complete payment.
	 * @param LineItem[]                      $line_items The line items for the checkout session.
	 * @param ?string                         $id The id of the checkout session.
	 * @param ?string                         $client_reference_id The client reference id, which is the WooCommmerce order id.
	 * @param ?int                            $amount_total The total amount of the checkout session.
	 * @param ?CheckoutSessionMode            $mode The mode of the checkout session.
	 * @param ?CheckoutSessionStatus          $status The status of the checkout session.
	 * @param ?bool                           $test_mode If the checkout session was created in test mode.
	 * @param ?CheckoutSessionShippingOptions $shipping_options The shipping options if there are any.
	 * @param ?CheckoutSessionTaxRate         $tax_rate The tax if there is one.
	 * @param ?string                         $cancel_url The url to use to cancel the checkout session.
	 */
	public function __construct(
		protected string $success_url,
		protected ?CustomerDefaults $defaults = null,
		protected ?string $redirect_url = null,
		protected array $line_items = array(),
		protected ?string $id = null,
		protected ?string $client_reference_id = null,
		protected ?int $amount_total = null,
		protected ?CheckoutSessionMode $mode = CheckoutSessionMode::PAYMENT,
		protected ?CheckoutSessionStatus $status = null,
		protected ?bool $test_mode = null,
		protected ?CheckoutSessionShippingOptions $shipping_options = null,
		protected ?CheckoutSessionTaxRate $tax_rate = null,
		protected ?string $cancel_url = null,
	) {}

	/**
	 * {@inheritdoc}
	 */
	public function id(): ?string {
		return $this->id;
	}

	/**
	 * Status of the checkout session.
	 */
	public function status(): ?CheckoutSessionStatus {
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
	 * Returns whether the checkout session is in test mode or not.
	 */
	public function test_mode(): ?bool {
		return $this->test_mode;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Only serialize properties where WooCommerce is the system of record.
	 */
	public function jsonSerialize(): array {
		$data = array(
			'defaults'            => $this->defaults,
			'success_url'         => $this->success_url,
			'line_items'          => $this->line_items,
			'client_reference_id' => $this->client_reference_id,
			'mode'                => $this->mode->value,
			'cancel_url'          => $this->cancel_url,
		);

		if ( null !== $this->shipping_options ) {
			$data['shipping_options'] = $this->shipping_options;
		}

		if ( null !== $this->tax_rate ) {
			$data['tax_rate'] = $this->tax_rate;
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
		$success_url = wp_nonce_url(
			actionurl: get_rest_url(
				path: Controller::NAMESPACE . "/orders/$order_id/complete",
			),
			action: 'wp_rest',
		);

		$tax_rate = CheckoutSessionTaxRate::from_wc( $order );

		$checkout_session = new self(
			success_url: $success_url,
			defaults: CustomerDefaults::from_wc( $order ),
			redirect_url: $order->meta_exists( self::META_PREFIX . self::KEY_REDIRECT_URL ) ? $order->get_meta( self::META_PREFIX . self::KEY_REDIRECT_URL ) : null,
			id: $id ? $id : null,
			client_reference_id: (string) $order->get_id(),
			status: CheckoutSessionStatus::tryFrom( $order->get_meta( self::META_PREFIX . self::KEY_STATUS ) ),
			mode: CheckoutSessionMode::PAYMENT,
			line_items: array_map( fn( $item ) => LineItem::from_wc( $item ), array_values( $order->get_items() ) ),
			amount_total: self::currency_to_unit_amount( $order->get_total() ),
			test_mode: $order->meta_exists( self::META_PREFIX . self::KEY_TEST_MODE ) ? wc_string_to_bool( $order->get_meta( self::META_PREFIX . self::KEY_TEST_MODE ) ) : self::payment_gateway()->is_in_test_mode(),
			shipping_options: ! empty( $order->get_shipping_methods() ) ? CheckoutSessionShippingOptions::from_wc( $order ) : null,
			tax_rate: $tax_rate->amount() > 0 ? $tax_rate : null,
			cancel_url: wc_get_checkout_url(),
		);

		$checkout_session->wc = $order;

		return $checkout_session;
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

			if ( ! empty( $orders ) ) {
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
		$order->update_meta_data( self::META_PREFIX . self::KEY_HASH, $this->hash() );

		if ( null === $this->redirect_url ) {
			$order->delete_meta_data( self::META_PREFIX . self::KEY_REDIRECT_URL );
		} else {
			$order->update_meta_data( self::META_PREFIX . self::KEY_REDIRECT_URL, $this->redirect_url );
		}

		if ( null === $this->amount_total ) {
			$order->delete_meta_data( self::META_PREFIX . self::KEY_AMOUNT_TOTAL );
		} else {
			$order->update_meta_data( self::META_PREFIX . self::KEY_AMOUNT_TOTAL, strval( $this->amount_total ) );
		}

		$status = $this->status?->value;
		if ( null === $status ) {
			$order->delete_meta_data( self::META_PREFIX . self::KEY_STATUS );
		} else {
			$order->update_meta_data( self::META_PREFIX . self::KEY_STATUS, $status, true );
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
		if ( array_any( $this->line_items, fn( $line_item ) => $line_item->needs() !== ResourceAction::NONE ) ) {
			return ResourceAction::DEPENDENCY;
		}

		if ( null === $this->id ) {
			return ResourceAction::CREATE;
		}

		if ( intval( $this->wc->get_meta( self::META_PREFIX . self::KEY_AMOUNT_TOTAL ) ) !== $this->amount_total ) {
			return ResourceAction::CREATE;
		}

		if ( $this->wc->get_meta( self::META_PREFIX . self::KEY_HASH ) !== $this->hash() ) {
			return ResourceAction::CREATE;
		}

		return ResourceAction::NONE;
	}

	/**
	 * Extract the values from a checkout session API response.
	 *
	 * @param array $checkout_session The checkout session object returned from the API.
	 *
	 * @throws FlexException If data is missing.
	 */
	public static function from_flex( array $checkout_session ): self {
		if ( ! isset( $checkout_session['success_url'] ) ) {
			throw new FlexException( 'Success URL missing from checkout session.' );
		}

		$cs = new self( success_url: $checkout_session['success_url'] );
		$cs->extract( $checkout_session );
		return $cs;
	}

	/**
	 * Extract the values from a checkout session API response.
	 *
	 * @param array $checkout_session The checkout session object returned from the API.
	 *
	 * @throws \Exception If data is missing.
	 */
	protected function extract( array $checkout_session ): void {
		$this->id                  = $checkout_session['checkout_session_id'] ?? $this->id;
		$this->defaults            = isset( $checkout_session['defaults'] ) ? CustomerDefaults::from_flex( $checkout_session['defaults'] ) : $this->defaults;
		$this->success_url         = $checkout_session['success_url'] ?? $this->success_url;
		$this->redirect_url        = $checkout_session['redirect_url'] ?? $this->redirect_url;
		$this->client_reference_id = $checkout_session['client_reference_id'] ?? $this->client_reference_id;
		$this->amount_total        = $checkout_session['amount_total'] ?? $this->amount_total;
		$this->mode                = CheckoutSessionMode::tryFrom( $checkout_session['mode'] ?? '' ) ?? $this->mode;
		$this->status              = CheckoutSessionStatus::tryFrom( $checkout_session['status'] ?? '' ) ?? $this->status;
		$this->test_mode           = $checkout_session['test_mode'] ?? $this->test_mode;
		$this->shipping_options    = isset( $checkout_session['shipping_options'] ) ? CheckoutSessionShippingOptions::from_flex( $checkout_session['shipping_options'] ) : $this->shipping_options;
		$this->tax_rate            = isset( $checkout_session['tax_rate'] ) ? CheckoutSessionTaxRate::from_flex( $checkout_session['tax_rate'] ) : $this->tax_rate;
		$this->cancel_url          = $checkout_session['cancel_url'] ?? $this->cancel_url;
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
		}

		$data = $this->remote_request(
			match ( $action ) {
				ResourceAction::CREATE, ResourceAction::DEPENDENCY => '/v1/checkout/sessions',
				ResourceAction::REFRESH => '/v1/checkout/sessions/' . $this->id,
			},
			array(
				'method' => match ( $action ) {
					ResourceAction::CREATE, ResourceAction::DEPENDENCY => 'POST',
					ResourceAction::REFRESH => 'GET',
				},
				'flex'   => match ( $action ) {
					ResourceAction::CREATE, ResourceAction::DEPENDENCY => array( 'data' => array( 'checkout_session' => $this ) ),
					ResourceAction::REFRESH => array(),
				},
			),
		);

		if ( ! isset( $data['checkout_session'] ) ) {
			throw new FlexException( 'Missing checkout_session in response.' );
		}

		$this->extract( $data['checkout_session'] );

		if ( null !== $this->wc ) {
			$this->apply_to( $this->wc );

			foreach ( $this->line_items as $line_item ) {
				$line_item->apply();
			}

			$this->wc->save();
		}
	}
}
