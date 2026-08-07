<?php
/**
 * Tests for the async-retry classification (flex_is_retryable).
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Tests;

use Flex\Exception\FlexException;
use Flex\Exception\FlexResponseException;

use function Flex\flex_is_retryable;
use function Flex\flex_update_coupon;

/**
 * Tests for {@see \Flex\flex_is_retryable()}.
 */
class RetryTest extends \WP_UnitTestCase {

	/**
	 * Network/5xx and the transient 4xx (408, 429) are re-enqueued; every other 4xx is a
	 * permanent client error that fails identically on retry and must not be retried
	 * (WOOCOMMERCE-84).
	 *
	 * @param int  $status   The HTTP status carried by the FlexResponseException.
	 * @param bool $expected Whether that status should be retried.
	 *
	 * @dataProvider retryable_status_provider
	 */
	public function test_is_retryable_by_status( int $status, bool $expected ): void {
		$exception = new FlexResponseException( array( 'response' => array( 'code' => $status ) ) );

		self::assertSame( $expected, flex_is_retryable( $exception ) );
	}

	/**
	 * HTTP statuses paired with whether flex_is_retryable() re-enqueues them.
	 *
	 * @return array<string, array{int, bool}>
	 */
	public static function retryable_status_provider(): array {
		return array(
			'408 request timeout' => array( 408, true ),
			'429 rate limited'    => array( 429, true ),
			'500 server error'    => array( 500, true ),
			'503 unavailable'     => array( 503, true ),
			'400 bad request'     => array( 400, false ),
			'401 unauthorized'    => array( 401, false ),
			'403 forbidden'       => array( 403, false ),
			'404 not found'       => array( 404, false ),
			'422 unprocessable'   => array( 422, false ),
		);
	}

	/**
	 * A non-response failure (network error, invariant violation) carries no HTTP status,
	 * so it is treated as transient and retried.
	 */
	public function test_is_retryable_true_for_non_response_exception(): void {
		self::assertTrue( flex_is_retryable( new FlexException( 'network down' ) ) );
	}

	/**
	 * An unset API key is merchant misconfiguration, not a transient failure: flex_update_coupon
	 * logs and returns without throwing or scheduling a retry, so it never storms the retry
	 * budget or alerts Sentry on exhaustion (WOOCOMMERCE-84; woocommerce/CLAUDE.md logging policy).
	 */
	public function test_update_coupon_with_unset_api_key_does_not_reschedule(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			self::markTestSkipped( 'Action Scheduler is not loaded.' );
		}

		update_option(
			'woocommerce_flex_settings',
			array(
				'enabled' => 'no',
				'api_key' => '',
			)
		);

		// A real product on a genuine sale: with the API-key guard removed, the sync would
		// walk Coupon -> Price -> Product::exec() into remote_request, throw "API key not
		// set", and reschedule. So the assertion below holds because the guard short-circuits
		// first, not because the product is missing (which a bare ID like 4242 would mask).
		$product = new \WC_Product_Simple();
		$product->set_regular_price( '20.00' );
		$product->set_sale_price( '15.00' );
		$product->set_status( 'publish' );
		$product->save();
		$product_id = $product->get_id();

		flex_update_coupon( $product_id );

		// Scope to this product's action group so unrelated scheduled actions can't affect
		// the result, and match any retry count (args null).
		self::assertFalse(
			as_has_scheduled_action( 'flex_update_coupon', null, "product-{$product_id}" ),
			'An unset API key must not schedule a coupon retry.'
		);
	}
}
