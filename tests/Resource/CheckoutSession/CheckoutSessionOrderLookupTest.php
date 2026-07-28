<?php
/**
 * Tests for CheckoutSession::wc() order resolution.
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Tests\Resource\CheckoutSession;

use Flex\Resource\CheckoutSession\CheckoutSession;

/**
 * Tests how a Checkout Session resolves back to its WooCommerce order.
 *
 * `needs()` returns CREATE for any non-complete session, so editing a pending
 * order mints a second Flex session and `apply_to()` overwrites the order's
 * `transaction_id` with the newer id. The first session stays open and payable.
 *
 * When that superseded session completes, the webhook controller resolves the
 * order purely through `wc()`. A `transaction_id` lookup cannot find it -- the
 * order now points at the newer session -- so the handler returned 422 and the
 * order stayed pending forever, even though the customer had paid. The order id
 * was on the wire the whole time as `client_reference_id`, just never read.
 */
class CheckoutSessionOrderLookupTest extends \WP_UnitTestCase {

	/**
	 * Builds an order paid with a given gateway and transaction id.
	 *
	 * @param string $payment_method The gateway id to record on the order.
	 * @param string $transaction_id The Flex checkout session id to record.
	 */
	private function order( string $payment_method = 'flex', string $transaction_id = '' ): \WC_Order {
		$order = wc_create_order();
		self::assertInstanceOf( \WC_Order::class, $order );

		$order->set_payment_method( $payment_method );
		$order->set_transaction_id( $transaction_id );
		$order->save();

		return $order;
	}

	/**
	 * A superseded session must still resolve to its order via client_reference_id.
	 *
	 * This is the charged-but-unfulfilled case: the order has moved on to a newer
	 * session, so nothing matches on transaction_id.
	 */
	public function test_falls_back_to_client_reference_id(): void {
		$order = $this->order( transaction_id: 'cs_current' );

		$superseded = new CheckoutSession(
			success_url: 'https://example.com/success',
			id: 'cs_superseded',
			client_reference_id: (string) $order->get_id(),
		);

		$resolved = $superseded->wc();

		self::assertInstanceOf( \WC_Order::class, $resolved );
		self::assertSame( $order->get_id(), $resolved->get_id() );
	}

	/**
	 * A transaction_id match must win over client_reference_id.
	 *
	 * The stored transaction id is the more specific signal, so the fallback must
	 * not shadow it when both could resolve.
	 */
	public function test_prefers_the_transaction_id_match(): void {
		$matching = $this->order( transaction_id: 'cs_matching' );
		$other    = $this->order( transaction_id: 'cs_other' );

		$session = new CheckoutSession(
			success_url: 'https://example.com/success',
			id: 'cs_matching',
			client_reference_id: (string) $other->get_id(),
		);

		$resolved = $session->wc();

		self::assertInstanceOf( \WC_Order::class, $resolved );
		self::assertSame( $matching->get_id(), $resolved->get_id() );
	}

	/**
	 * An order that was not paid through Flex must never be resolved.
	 *
	 * `client_reference_id` arrives in a webhook payload. Signature verification
	 * runs before the handler, so it is authenticated -- but the fallback should
	 * still refuse to hand back an order belonging to another gateway rather than
	 * relying on that check alone.
	 */
	public function test_ignores_an_order_from_another_gateway(): void {
		$order = $this->order( payment_method: 'cod' );

		$session = new CheckoutSession(
			success_url: 'https://example.com/success',
			id: 'cs_superseded',
			client_reference_id: (string) $order->get_id(),
		);

		self::assertNull( $session->wc() );
	}

	/**
	 * A client_reference_id that resolves to nothing must not throw.
	 */
	public function test_returns_null_for_an_unknown_client_reference_id(): void {
		$session = new CheckoutSession(
			success_url: 'https://example.com/success',
			id: 'cs_superseded',
			client_reference_id: '99999999',
		);

		self::assertNull( $session->wc() );
	}

	/**
	 * A non-numeric client_reference_id must not throw.
	 *
	 * The plugin always sends an order id, but the value is read back off an API
	 * response, so it is not the plugin's invariant to rely on.
	 */
	public function test_returns_null_for_a_non_numeric_client_reference_id(): void {
		$session = new CheckoutSession(
			success_url: 'https://example.com/success',
			id: 'cs_superseded',
			client_reference_id: 'not-an-order-id',
		);

		self::assertNull( $session->wc() );
	}
}
