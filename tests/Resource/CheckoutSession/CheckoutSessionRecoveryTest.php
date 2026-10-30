<?php
/**
 * Tests for CheckoutSession::exec() recovery from a stale price reference.
 *
 * When a checkout session is created with a `price` id that no longer resolves on
 * the Flex side (e.g. a product was duplicated, or its price was recreated and the
 * old one deactivated), the API returns 422 `price_not_found`. The session layer
 * should recreate the offending line-item prices and retry the create once, rather
 * than surfacing the failure to the customer (MER-1371).
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Tests\Resource\CheckoutSession;

use Flex\Exception\FlexResponseException;
use Flex\Resource\CheckoutSession\CheckoutSession;
use Flex\Resource\CheckoutSession\LineItem;
use Flex\Resource\CheckoutSession\Status;
use Flex\Resource\Price;
use Flex\Resource\Product;
use Flex\Resource\ResourceAction;

/**
 * Tests for CheckoutSession::exec() price_not_found recovery.
 */
class CheckoutSessionRecoveryTest extends \WP_UnitTestCase {

	/**
	 * The registered pre_http_request callback, so it can be removed in teardown.
	 *
	 * @var ?callable
	 */
	private $http_filter = null;

	/**
	 * {@inheritDoc}
	 */
	public function tear_down(): void {
		if ( null !== $this->http_filter ) {
			remove_filter( 'pre_http_request', $this->http_filter, 10 );
			$this->http_filter = null;
		}
		delete_option( 'woocommerce_flex_settings' );
		parent::tear_down();
	}

	/**
	 * Configure the gateway with an API key for the duration of the test.
	 */
	private function set_api_key(): void {
		update_option(
			'woocommerce_flex_settings',
			array(
				'enabled' => 'no',
				'api_key' => 'sk_test_recovery',
			)
		);
	}

	/**
	 * Build a wp_remote_request-shaped response with a JSON body.
	 *
	 * @param string|int           $code The HTTP status code.
	 * @param array<string, mixed> $body The decoded body to encode.
	 *
	 * @return array<string, mixed>
	 */
	private function response( string|int $code, array $body ): array {
		return array(
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'body'     => (string) wp_json_encode( $body ),
			'headers'  => array(),
			'cookies'  => array(),
		);
	}

	/**
	 * Intercept all outbound HTTP with the given callback.
	 *
	 * @param callable $cb Receives ($preempt, $args, $url) and returns a response.
	 */
	private function mock_http( callable $cb ): void {
		$this->http_filter = $cb;
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
	}

	/**
	 * HTTP status codes can arrive as an int or as a numeric string, since
	 * FlexResponseException::code() returns wp_remote_retrieve_response_code()
	 * verbatim (typed string|int). Recovery must trigger for both.
	 *
	 * @return array<string, array{0: string|int}>
	 */
	public static function price_not_found_code_provider(): array {
		return array(
			'integer code' => array( 422 ),
			'string code'  => array( '422' ),
		);
	}

	/**
	 * A 422 `price_not_found` on create triggers a price recreate and a single retry.
	 *
	 * @dataProvider price_not_found_code_provider
	 *
	 * @param string|int $code The HTTP status code returned on the first create.
	 */
	public function test_exec_recovers_from_price_not_found( string|int $code ): void {
		$this->set_api_key();

		$sessions = 0;
		$this->mock_http(
			function ( $pre, array $args, string $url ) use ( &$sessions, $code ) {
				$method = isset( $args['method'] ) && is_string( $args['method'] ) ? $args['method'] : 'GET';

				if ( str_contains( $url, '/v1/checkout/sessions' ) ) {
					++$sessions;
					if ( 1 === $sessions ) {
						return $this->response(
							$code,
							array(
								'detail' => array(
									array(
										'loc'  => array( 'price' ),
										'msg'  => 'price_not_found: One or more prices are not valid',
										'type' => 'price_not_found',
									),
								),
							)
						);
					}
					return $this->response(
						200,
						array(
							'checkout_session' => array(
								'checkout_session_id' => 'fcs_recovered',
								'success_url'         => 'https://example.com/success',
								'status'              => 'open',
								'redirect_url'        => 'https://pay.example/redirect',
							),
						)
					);
				}

				if ( str_contains( $url, '/v1/prices' ) ) {
					// The stale price no longer resolves; recreation POSTs a fresh one.
					if ( 'GET' === $method ) {
						return $this->response( 404, array( 'detail' => 'not_found' ) );
					}
					return $this->response(
						200,
						array(
							'price' => array(
								'price_id'    => 'fprice_new',
								'active'      => true,
								'unit_amount' => 6900,
								'product'     => 'fprod_x',
							),
						)
					);
				}

				return $pre;
			}
		);

		$line_item = new LineItem( price: new Price( id: 'fprice_stale' ), quantity: 1 );
		$session   = new CheckoutSession(
			success_url: 'https://example.com/success',
			line_items: array( $line_item ),
			status: Status::OPEN,
		);

		$session->exec( ResourceAction::CREATE );

		self::assertSame( 'fcs_recovered', $session->id(), 'Session should be created after recovering the stale price' );
		self::assertSame( 'fprice_new', $line_item->price()->id(), 'Stale price should have been recreated' );
		self::assertSame( 2, $sessions, 'Session create should be retried exactly once' );
	}

	/**
	 * Recovery must not deactivate a price recreated earlier in the same request,
	 * given the backend's (Idempotency-Key, URL) replay of successful responses.
	 */
	public function test_exec_recovery_does_not_deactivate_price_recreated_in_same_request(): void {
		$this->set_api_key();

		$idempotency_cache = array();
		$active            = array( 'fprice_old' => true );
		$created           = 0;
		$sessions          = 0;
		$retry_price       = null;
		$this->mock_http(
			function ( $pre, array $args, string $url ) use ( &$idempotency_cache, &$active, &$created, &$sessions, &$retry_price ) {
				$method    = isset( $args['method'] ) && is_string( $args['method'] ) ? $args['method'] : 'GET';
				$path      = (string) wp_parse_url( $url, PHP_URL_PATH );
				$headers   = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
				$key       = isset( $headers['Idempotency-Key'] ) && is_string( $headers['Idempotency-Key'] ) ? $headers['Idempotency-Key'] : '';
				$cache_key = $key . ' ' . $url;
				$raw_body  = isset( $args['body'] ) && is_string( $args['body'] ) ? $args['body'] : '';

				if ( 'POST' === $method ) {
					if ( isset( $idempotency_cache[ $cache_key ] ) ) {
						return $idempotency_cache[ $cache_key ];
					}
				}

				if ( '/v1/checkout/sessions' === $path ) {
					++$sessions;
					/**
					 * The checkout session create body.
					 *
					 * @var array{checkout_session: array{line_items: list<array{price: string}>}} $body
					 */
					$body      = json_decode( $raw_body, true );
					$sent      = array_column( $body['checkout_session']['line_items'], 'price' );
					$all_valid = array() === array_filter( $sent, fn ( string $id ) => ! ( $active[ $id ] ?? false ) );
					if ( ! $all_valid ) {
						$response = $this->response(
							422,
							array(
								'detail' => array(
									array(
										'loc'  => array( 'price' ),
										'msg'  => 'price_not_found: One or more prices are not valid',
										'type' => 'price_not_found',
									),
								),
							)
						);
					} else {
						$retry_price = $sent[0];
						$response    = $this->response(
							200,
							array(
								'checkout_session' => array(
									'checkout_session_id' => 'fcs_recovered',
									'success_url'         => 'https://example.com/success',
									'status'              => 'open',
									'redirect_url'        => 'https://pay.example/redirect',
								),
							)
						);
					}
				} elseif ( '/v1/prices' === $path ) {
					++$created;
					$id            = 'fprice_new_' . $created;
					$active[ $id ] = true;
					$response      = $this->response( 200, array( 'price' => array( 'price_id' => $id ) ) );
				} elseif ( str_starts_with( $path, '/v1/prices/' ) ) {
					$id = substr( $path, strlen( '/v1/prices/' ) );
					if ( ! isset( $active[ $id ] ) ) {
						return $this->response( 404, array( 'detail' => 'not_found' ) );
					}
					if ( 'POST' === $method ) {
						/**
						 * The price update body.
						 *
						 * @var array{price: array{active: bool}} $body
						 */
						$body          = json_decode( $raw_body, true );
						$active[ $id ] = $body['price']['active'];
					}
					$response = $this->response(
						200,
						array(
							'price' => array(
								'price_id'    => $id,
								'active'      => $active[ $id ],
								'unit_amount' => 2495,
								'product'     => 'fprod_x',
							),
						)
					);
				} else {
					return $pre;
				}

				// Like the backend, cache only successful responses.
				if ( 'POST' === $method && 200 === wp_remote_retrieve_response_code( $response ) ) {
					$idempotency_cache[ $cache_key ] = $response;
				}
				return $response;
			}
		);

		$price = new Price(
			product: new Product( id: 'fprod_x' ),
			id: 'fprice_old',
			unit_amount: 2495,
		);
		// The dependency pass recreates the price after a catalog price change.
		$price->exec( ResourceAction::CREATE );
		$recreated = $price->id();
		self::assertSame( 'fprice_new_1', $recreated );
		self::assertFalse( $active['fprice_old'] );

		// A sibling's stored price no longer resolves, so the first create returns 422.
		$sibling = new Price(
			product: new Product( id: 'fprod_y' ),
			id: 'fprice_sibling_stale',
			unit_amount: 1000,
		);
		$session = new CheckoutSession(
			success_url: 'https://example.com/success',
			line_items: array(
				new LineItem( price: $price, quantity: 1 ),
				new LineItem( price: $sibling, quantity: 1 ),
			),
			status: Status::OPEN,
		);
		$session->exec( ResourceAction::CREATE );

		self::assertSame( 'fcs_recovered', $session->id() );
		self::assertSame( 2, $sessions, 'Session create should be retried exactly once' );
		self::assertSame( $price->id(), $retry_price, 'Retry should send the price the recovery pass produced' );
		self::assertNotNull( $retry_price );
		self::assertTrue( $active[ $retry_price ], 'The price sent on retry must be active' );
	}

	/**
	 * A 422 that is not `price_not_found` must surface as-is, with no retry.
	 */
	public function test_exec_does_not_retry_other_422_errors(): void {
		$this->set_api_key();

		$sessions = 0;
		$this->mock_http(
			function ( $pre, array $args, string $url ) use ( &$sessions ) {
				if ( str_contains( $url, '/v1/checkout/sessions' ) ) {
					++$sessions;
					return $this->response(
						422,
						array(
							'detail' => array(
								array(
									'loc'  => array( 'customer' ),
									'msg'  => 'customer_required: customer is required',
									'type' => 'customer_required',
								),
							),
						)
					);
				}
				return $pre;
			}
		);

		$session = new CheckoutSession(
			success_url: 'https://example.com/success',
			line_items: array( new LineItem( price: new Price( id: 'fprice_x' ) ) ),
			status: Status::OPEN,
		);

		try {
			$session->exec( ResourceAction::CREATE );
			self::fail( 'Expected a FlexResponseException for a non-price_not_found 422' );
		} catch ( FlexResponseException $e ) {
			self::assertSame( 'customer_required', $e->errorType() );
		}

		self::assertSame( 1, $sessions, 'A non-price_not_found 422 must not be retried' );
	}
}
