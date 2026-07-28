<?php
/**
 * Tests for the registration of the Flex async action hooks.
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Tests;

/**
 * Guards the arity of the Flex async action hooks.
 *
 * Action Scheduler stores a hook's arguments and replays them positionally, but
 * WordPress only forwards as many of them as the registration's `accepted_args`
 * allows, and that value defaults to 1. A handler declared as
 * `handler( int $thing, int $retries = 0 )` but registered without
 * `accepted_args: 2` therefore never receives `$retries`: it reads 0 on every
 * run, so `flex_enqueue_async_action()` pins the back-off at `2 ** 1` seconds and
 * its `$retries >= 10` ceiling can never be reached. The action re-enqueues
 * itself forever and the retry-exhaustion Sentry alert never fires.
 *
 * This is invisible to both PHPStan and any test that calls the handler
 * directly, because the defect lives in the registration rather than in the
 * function, so assert that the two agree.
 */
class AsyncActionHooksTest extends \WP_UnitTestCase {

	/**
	 * Every Flex async action hook, paired with the function it dispatches to.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function async_action_provider(): array {
		return array(
			'flex_update_product' => array( 'flex_update_product', 'Flex\flex_update_product' ),
			'flex_update_price'   => array( 'flex_update_price', 'Flex\flex_update_price' ),
			'flex_update_coupon'  => array( 'flex_update_coupon', 'Flex\flex_update_coupon' ),
			'flex_update_webhook' => array( 'flex_update_webhook', 'Flex\flex_update_webhook' ),
			'flex_product_sync'   => array( 'flex_product_sync', 'Flex\flex_product_sync' ),
		);
	}

	/**
	 * The registered `accepted_args` must match the handler's parameter count, so
	 * that a retry counter in the final parameter is actually delivered.
	 *
	 * @param string $hook    The action hook name.
	 * @param string $handler The fully-qualified handler function.
	 *
	 * @dataProvider async_action_provider
	 */
	public function test_accepted_args_matches_handler_arity( string $hook, string $handler ): void {
		$arity = ( new \ReflectionFunction( $handler ) )->getNumberOfParameters();

		self::assertSame(
			$arity,
			self::accepted_args( $hook, $handler ),
			"{$hook} is registered with an accepted_args that does not match {$handler}()'s {$arity} parameter(s), so Action Scheduler's stored arguments are silently truncated.",
		);
	}

	/**
	 * Reads the `accepted_args` that a callback was registered with.
	 *
	 * @param string $hook    The action hook name.
	 * @param string $handler The fully-qualified handler function.
	 */
	private static function accepted_args( string $hook, string $handler ): int {
		/**
		 * WordPress exposes no accessor for a registration's `accepted_args`, so
		 * read the filter registry directly.
		 *
		 * @var array<string, \WP_Hook> $registry
		 */
		$registry = $GLOBALS['wp_filter'];

		self::assertArrayHasKey( $hook, $registry, "No callback is registered on {$hook}." );

		/**
		 * The registered callbacks, keyed by priority and then by callback id.
		 *
		 * @var array<int, array<string, array{function: string|array<mixed>|\Closure, accepted_args: int}>> $by_priority
		 */
		$by_priority = $registry[ $hook ]->callbacks;

		foreach ( $by_priority as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( $handler === $callback['function'] ) {
					return $callback['accepted_args'];
				}
			}
		}

		self::fail( "{$handler}() is not registered on {$hook}." );
	}
}
