<?php
/**
 * Flex Data Operations.
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Resource\CheckoutSession;

enum Mode: string {
	case PAYMENT      = 'payment';
	case SUBSCRIPTION = 'subscription';
}
