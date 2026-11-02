<?php
/**
 * Tests for PaymentGateway::process_refund() failure reporting.
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Tests;

use Flex\PaymentGateway;
use Sentry\ClientBuilder;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

use function Flex\sentry;

/**
 * A refund failure must tell the merchant why it failed, in the refund error and the order
 * notes, and must only reach Sentry when Flex can act on it.
 */
class ProcessRefundTest extends \WP_UnitTestCase {

	/**
	 * Events sent through the stub Sentry transport.
	 *
	 * @var Event[]
	 */
	private static array $events = array();

	/**
	 * Messages written to the stub WooCommerce logger, as [level, message] pairs.
	 *
	 * @var array<array{string, string}>
	 */
	private static array $logs = array();

	/**
	 * The registered pre_http_request callback.
	 *
	 * @var ?callable
	 */
	private $http_filter = null;

	/**
	 * The woocommerce_logging_class filter callback.
	 *
	 * @var ?\Closure(): \WC_Logger_Interface
	 */
	private ?\Closure $logger_filter = null;

	/**
	 * The plugin's real Sentry client, restored after each test.
	 *
	 * @var ?ClientInterface
	 */
	private ?ClientInterface $original_client = null;

	/**
	 * Stub the Sentry transport and the Woo logger, and configure an API key.
	 */
	public function set_up(): void {
		parent::set_up();

		update_option(
			'woocommerce_flex_settings',
			array(
				'enabled' => 'no',
				'api_key' => 'sk_test_refund',
			)
		);

		$this->original_client = sentry()->getClient();
		sentry()->bindClient(
			ClientBuilder::create( array() )->setTransport(
				new class() implements TransportInterface {
					// phpcs:ignore Squiz.Commenting.FunctionComment.Missing
					public function send( Event $event ): Result {
						ProcessRefundTest::record( $event );
						return new Result( ResultStatus::success(), $event );
					}

					// phpcs:ignore Squiz.Commenting.FunctionComment.Missing
					public function close( ?int $timeout = null ): Result {
						return new Result( ResultStatus::success() );
					}
				}
			)->getClient()
		);

		$logger = $this->createMock( \WC_Logger_Interface::class );
		$logger->method( 'log' )->willReturnCallback(
			function ( string $level, string $message ): void {
				self::$logs[] = array( $level, $message );
			}
		);
		$logger->method( 'error' )->willReturnCallback(
			function ( string $message ): void {
				self::$logs[] = array( 'error', $message );
			}
		);
		$this->logger_filter = fn (): \WC_Logger_Interface => $logger;
		add_filter( 'woocommerce_logging_class', $this->logger_filter );

		self::$events = array();
		self::$logs   = array();
	}

	/**
	 * Restore the real Sentry client, logger and HTTP transport.
	 */
	public function tear_down(): void {
		if ( null !== $this->http_filter ) {
			remove_filter( 'pre_http_request', $this->http_filter, 10 );
			$this->http_filter = null;
		}
		if ( null !== $this->logger_filter ) {
			remove_filter( 'woocommerce_logging_class', $this->logger_filter );
		}
		if ( null !== $this->original_client ) {
			sentry()->bindClient( $this->original_client );
		}
		delete_option( 'woocommerce_flex_settings' );
		parent::tear_down();
	}

	/**
	 * Record an event sent through the stub transport.
	 *
	 * @param Event $event The event.
	 */
	public static function record( Event $event ): void {
		self::$events[] = $event;
	}

	/**
	 * Answer the Flex refund endpoint with the given status and JSON body.
	 *
	 * @param int                  $code The HTTP status code.
	 * @param array<string, mixed> $body The decoded body to encode.
	 */
	private function mock_refund_response( int $code, array $body ): void {
		$this->http_filter = static function ( $pre, array $args, string $url ) use ( $code, $body ) {
			if ( ! str_contains( $url, '/refund' ) ) {
				return $pre;
			}

			return array(
				'response' => array(
					'code'    => $code,
					'message' => '',
				),
				'body'     => (string) wp_json_encode( $body ),
				'headers'  => array(),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
	}

	/**
	 * A completed Flex order for $10.00 with one product line.
	 */
	private function flex_order(): \WC_Order {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Item' );
		$product->set_regular_price( '10.00' );
		$product->set_status( 'publish' );
		$product->save();

		$order = wc_create_order();
		self::assertInstanceOf( \WC_Order::class, $order );
		$order->add_product( $product, 1 );
		$order->set_payment_method( PaymentGateway::ID );
		$order->set_transaction_id( 'fcs_refund_test' );
		$order->calculate_totals();
		$order->set_status( 'completed' );
		$order->save();

		return $order;
	}

	/**
	 * The text of every note on the order.
	 *
	 * @param \WC_Order $order The order.
	 * @return string[]
	 */
	private function order_notes( \WC_Order $order ): array {
		return array_map(
			static fn ( $note ): string => is_string( $note->content ) ? $note->content : '',
			wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
		);
	}

	/**
	 * When Flex rejects the refund, the merchant sees Flex's reason — not the raw response
	 * and not "try again later" — in the refund error and the order notes.
	 */
	public function test_api_rejection_detail_reaches_the_merchant(): void {
		$this->mock_refund_response(
			422,
			array(
				'detail' => array(
					array(
						'loc'  => array( 'checkout_session', 'status' ),
						'msg'  => 'checkout_session_not_complete: Checkout session must be complete',
						'type' => 'checkout_session_not_complete',
					),
				),
			)
		);

		$order  = $this->flex_order();
		$result = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => '10.00',
				'reason'         => 'Customer return',
				'refund_payment' => true,
			)
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		$message = $result->get_error_message();
		self::assertStringContainsString( 'Checkout session must be complete', $message );
		self::assertStringNotContainsString( '{"detail"', $message, 'The merchant should see the reason, not the raw response body.' );
		self::assertStringNotContainsString( 'try again later', $message, 'A rejection will fail the same way on retry.' );

		self::assertNotEmpty(
			array_filter( $this->order_notes( $order ), static fn ( string $note ): bool => str_contains( $note, 'Checkout session must be complete' ) ),
			'The order notes should record why the Flex refund failed.',
		);
	}

	/**
	 * Calling process_refund() without first creating the WooCommerce refund is an
	 * integration mistake on the merchant's side: say how to fix it, log it for the site
	 * operator, and keep it out of Sentry.
	 */
	public function test_missing_woocommerce_refund_is_explained_and_not_reported(): void {
		$order   = $this->flex_order();
		$gateway = new PaymentGateway( actions: false );

		try {
			$gateway->process_refund( $order->get_id(), 10.0, 'Customer return' );
			self::fail( 'process_refund() should fail when the order has no WooCommerce refund.' );
		} catch ( \Exception $e ) {
			self::assertStringContainsString( 'wc_create_refund', $e->getMessage() );
		}

		self::assertSame( array(), self::$events, 'A merchant integration mistake is not actionable by Flex.' );
		self::assertNotEmpty(
			array_filter( self::$logs, static fn ( array $log ): bool => 'error' === $log[0] && str_contains( $log[1], 'wc_create_refund' ) ),
			'The WooCommerce log should say why the refund failed.',
		);
		self::assertNotEmpty(
			array_filter( $this->order_notes( $order ), static fn ( string $note ): bool => str_contains( $note, 'wc_create_refund' ) ),
			'The order notes should say why the refund failed.',
		);
	}

	/**
	 * A 400 is Flex refusing the request as made: the merchant sees why, and Sentry stays quiet.
	 */
	public function test_client_rejection_is_explained_and_not_reported(): void {
		$this->mock_refund_response(
			400,
			array(
				'code'   => 'refund_amount_too_large',
				'detail' => 'Refund amount exceeds the amount captured',
			)
		);

		$order  = $this->flex_order();
		$result = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => '10.00',
				'refund_payment' => true,
			)
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'Flex could not process this refund: Refund amount exceeds the amount captured', $result->get_error_message() );
		self::assertSame( array(), self::$events );
	}

	/**
	 * Merchant-side failures before any API call each explain themselves and stay out of Sentry.
	 */
	public function test_unknown_order_is_explained_and_not_reported(): void {
		$gateway = new PaymentGateway( actions: false );

		try {
			$gateway->process_refund( 987654321, 10.0, '' );
			self::fail( 'process_refund() should fail for an order that does not exist.' );
		} catch ( \Exception $e ) {
			self::assertSame( 'Flex could not process this refund: Order 987654321 not found.', $e->getMessage() );
		}

		self::assertSame( array(), self::$events );
	}

	/**
	 * A call whose amount or reason differs from the order's latest refund names both sides.
	 */
	public function test_mismatched_refund_is_explained_and_not_reported(): void {
		$order  = $this->flex_order();
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => '5.00',
				'reason'   => 'Damaged',
			)
		);
		self::assertInstanceOf( \WC_Order_Refund::class, $refund );

		try {
			( new PaymentGateway( actions: false ) )->process_refund( $order->get_id(), 4.0, 'Damaged' );
			self::fail( 'process_refund() should fail when the amount does not match the latest refund.' );
		} catch ( \Exception $e ) {
			self::assertStringContainsString( '(4, "Damaged") does not match the latest WooCommerce refund on this order (5.00, "Damaged")', $e->getMessage() );
		}

		self::assertSame( array(), self::$events );
	}

	/**
	 * Flex refunds against product lines, so an order without any cannot be refunded through Flex.
	 */
	public function test_order_without_product_lines_is_explained_and_not_reported(): void {
		$order = wc_create_order();
		self::assertInstanceOf( \WC_Order::class, $order );
		$fee = new \WC_Order_Item_Fee();
		$fee->set_name( 'Service' );
		$fee->set_total( '10.00' );
		$order->add_item( $fee );
		$order->set_payment_method( PaymentGateway::ID );
		$order->set_transaction_id( 'fcs_refund_test' );
		$order->calculate_totals();
		$order->set_status( 'completed' );
		$order->save();

		$result = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => '10.00',
				'refund_payment' => true,
			)
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertStringContainsString( 'this order has no product lines', $result->get_error_message() );
		self::assertSame( array(), self::$events );
	}

	/**
	 * A missing API key is merchant configuration: the merchant sees it, and Sentry stays quiet.
	 */
	public function test_unset_api_key_is_explained_and_not_reported(): void {
		update_option( 'woocommerce_flex_settings', array( 'enabled' => 'no' ) );

		$order  = $this->flex_order();
		$result = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => '10.00',
				'refund_payment' => true,
			)
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'Flex could not process this refund: API Key is not set', $result->get_error_message() );
		self::assertSame( array(), self::$events );
	}

	/**
	 * A Flex server error is ours to fix: it still reaches Sentry, and the event names the
	 * underlying failure rather than a generic wrapper.
	 */
	public function test_server_error_is_reported_with_its_cause(): void {
		$this->mock_refund_response(
			500,
			array(
				'code'   => 'internal_server_error',
				'detail' => 'Something went wrong',
			)
		);

		$order  = $this->flex_order();
		$result = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => '10.00',
				'refund_payment' => true,
			)
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'Flex could not process this refund: Something went wrong. Please try again later.', $result->get_error_message() );
		self::assertContains( $result->get_error_message(), $this->order_notes( $order ) );
		self::assertCount( 1, self::$events );

		$exceptions = self::$events[0]->getExceptions();
		$titles     = array_map( static fn ( $exception ): string => $exception->getValue(), $exceptions );
		self::assertNotEmpty(
			array_filter( $titles, static fn ( string $title ): bool => 'Refund processing failure: HTTP 500: Something went wrong' === $title ),
			'The Sentry event title should carry the status and Flex\'s explanation: ' . implode( ' | ', $titles ),
		);
	}

	/**
	 * The Sentry title names the status and Flex's explanation, never the raw response body.
	 */
	public function test_server_error_title_omits_the_raw_body(): void {
		$this->mock_refund_response(
			502,
			array(
				'message' => 'Upstream unavailable',
				'trace'   => 'internal-trace-id',
			)
		);

		$order  = $this->flex_order();
		$result = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => '10.00',
				'refund_payment' => true,
			)
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'Flex could not process this refund: Flex returned an unexpected error (HTTP 502). Please try again later.', $result->get_error_message() );
		self::assertCount( 1, self::$events );
		self::assertSame( 'Refund processing failure: HTTP 502', self::$events[0]->getExceptions()[0]->getValue() );
	}

	/**
	 * A rejection without an explanation will fail the same way on retry, so don't suggest one.
	 */
	public function test_client_rejection_without_detail_does_not_suggest_retry(): void {
		$this->mock_refund_response( 404, array() );

		$order  = $this->flex_order();
		$result = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => '10.00',
				'refund_payment' => true,
			)
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'Flex could not process this refund: Flex rejected the refund (HTTP 404).', $result->get_error_message() );
		self::assertSame( array(), self::$events );
	}
}
