<?php
/**
 * Tests for the PaymentGateway class.
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Tests;

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
}
