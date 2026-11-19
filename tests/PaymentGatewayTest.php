<?php
/**
 * Tests for the PaymentGateway class.
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Tests;

use Flex\Exception\FlexException;
use Flex\Exception\FlexResponseException;
use Flex\PaymentGateway;

/**
 * Tests for the PaymentGateway class.
 */
class PaymentGatewayTest extends \WP_UnitTestCase {

	/**
	 * The registered pre_http_request callback.
	 *
	 * @var ?callable
	 */
	private $http_filter = null;

	/**
	 * Unit amounts of the prices created through the stub API, keyed by price id.
	 *
	 * @var array<string, int>
	 */
	private array $prices = array();

	/**
	 * Remove the HTTP stub and the gateway settings.
	 */
	public function tear_down(): void {
		if ( null !== $this->http_filter ) {
			remove_filter( 'pre_http_request', $this->http_filter, 10 );
			$this->http_filter = null;
		}
		$this->prices = array();
		delete_option( 'woocommerce_flex_settings' );
		parent::tear_down();
	}

	/**
	 * Answer the Flex API the way the backend does: a session totals the prices it was given.
	 */
	private function stub_flex_api(): void {
		$this->http_filter = function ( $pre, array $args, string $url ) {
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			/**
			 * The decoded request body.
			 *
			 * @var array{price?: array{unit_amount?: int}, checkout_session?: array{line_items?: list<array{price: string, quantity: int}>}} $request
			 */
			$request = is_string( $args['body'] ?? null ) ? json_decode( $args['body'], true ) : array();

			if ( str_starts_with( $path, '/v1/products' ) ) {
				$data = array( 'product' => array( 'product_id' => 'fprod_test' ) );
			} elseif ( str_starts_with( $path, '/v1/prices' ) ) {
				$id                  = 'fprice_test_' . count( $this->prices );
				$this->prices[ $id ] = $request['price']['unit_amount'] ?? 0;
				$data                = array(
					'price' => array(
						'price_id'    => $id,
						'unit_amount' => $this->prices[ $id ],
					),
				);
			} elseif ( '/v1/checkout/sessions' === $path ) {
				$total = 0;
				foreach ( $request['checkout_session']['line_items'] ?? array() as $line_item ) {
					$total += ( $this->prices[ $line_item['price'] ] ?? 0 ) * $line_item['quantity'];
				}
				$data = array(
					'checkout_session' => array(
						'checkout_session_id' => 'fcs_test',
						'redirect_url'        => 'https://checkout.withflex.com/c/fcs_test',
						'amount_total'        => $total,
						'status'              => 'open',
					),
				);
			} else {
				return new \WP_Error( 'unexpected_request', $url );
			}

			return array(
				'response' => array(
					'code'    => 200,
					'message' => '',
				),
				'body'     => (string) wp_json_encode( $data ),
				'headers'  => array(),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
	}

	/**
	 * Test process_payment on a store that displays ',' as the decimal separator.
	 *
	 * @dataProvider comma_decimal_store_price_provider
	 *
	 * @param string $regular_price The product's regular price.
	 * @param int    $unit_amount   The unit amount Flex must be asked to charge.
	 */
	public function test_process_payment_charges_the_order_total_on_comma_decimal_store( string $regular_price, int $unit_amount ): void {
		update_option( 'woocommerce_price_decimal_sep', ',' );
		update_option( 'woocommerce_price_thousand_sep', '.' );
		update_option(
			'woocommerce_flex_settings',
			array(
				'enabled' => 'no',
				'api_key' => 'sk_test_payment',
			)
		);
		$this->stub_flex_api();

		$product = new \WC_Product_Simple();
		$product->set_name( 'Item' );
		$product->set_regular_price( $regular_price );
		$product->set_status( 'publish' );
		$product->save();

		$order = wc_create_order();
		self::assertInstanceOf( \WC_Order::class, $order );
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->save();

		$result = ( new PaymentGateway( actions: false ) )->process_payment( $order->get_id() );

		self::assertSame( 'success', $result['result'] );
		self::assertSame( 'https://checkout.withflex.com/c/fcs_test', $result['redirect'] );
		self::assertSame( array( $unit_amount ), array_values( $this->prices ) );
	}

	/**
	 * Regular prices and the unit amount each must be charged at.
	 *
	 * @return array<string, array{string, int}>
	 */
	public static function comma_decimal_store_price_provider(): array {
		return array(
			'whole dollars' => array( '1249.00', 124900 ),
			'with cents'    => array( '49.99', 4999 ),
		);
	}

	/**
	 * The gateway description must be a string so that wp_kses_post() does not
	 * receive null on PHP 8.4+, which would break checkout rendering.
	 */
	public function test_description_is_string_after_init(): void {
		$gateway = new PaymentGateway( actions: false );

		self::assertSame( 'Accept HSA/FSA payments directly in the checkout flow.', $gateway->description );
	}

	/**
	 * Only client-side rejections (400, 404) count as client errors, so process_payment
	 * surfaces the reason and skips Sentry (WOOCOMMERCE-86). Every other status — auth (401),
	 * validation (422), rate limits (429), and 5xx — stays server-side and is reported.
	 *
	 * @param int  $status   The HTTP status carried by the FlexResponseException.
	 * @param bool $expected Whether that status counts as a client error.
	 *
	 * @dataProvider client_error_status_provider
	 */
	public function test_is_client_error_only_for_client_statuses( int $status, bool $expected ): void {
		$exception = new FlexResponseException( array( 'response' => array( 'code' => $status ) ) );

		self::assertSame( $expected, $this->is_client_error( $exception ) );
	}

	/**
	 * HTTP statuses paired with whether they count as client errors.
	 *
	 * @return array<string, array{int, bool}>
	 */
	public static function client_error_status_provider(): array {
		return array(
			'400 bad request'   => array( 400, true ),
			'404 not found'     => array( 404, true ),
			'401 unauthorized'  => array( 401, false ),
			'422 unprocessable' => array( 422, false ),
			'429 rate limited'  => array( 429, false ),
			'500 server error'  => array( 500, false ),
			'200 ok'            => array( 200, false ),
		);
	}

	/**
	 * A non-response failure (network error, invariant violation) is not a client error,
	 * so it is still reported to Sentry rather than shown to the shopper as actionable.
	 */
	public function test_is_client_error_false_for_non_response_exception(): void {
		self::assertFalse( $this->is_client_error( new FlexException( 'network down' ) ) );
	}

	/**
	 * Invokes PaymentGateway's protected {@see PaymentGateway::is_client_error()} discriminator.
	 *
	 * @param \Throwable $previous The caught exception.
	 */
	private function is_client_error( \Throwable $previous ): bool {
		return (bool) ( new \ReflectionMethod( PaymentGateway::class, 'is_client_error' ) )->invoke( null, $previous );
	}
}
