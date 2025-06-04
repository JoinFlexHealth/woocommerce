<?php
/**
 * Flex Checkout Session Status.
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Resource\CheckoutSession;

enum Status: string {
	case OPEN     = 'open';
	case COMPLETE = 'complete';
}
