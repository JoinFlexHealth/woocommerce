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
