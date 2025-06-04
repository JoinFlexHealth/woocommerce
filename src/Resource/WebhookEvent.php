<?php
/**
 * Flex Webhook Event
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Resource;

enum WebhookEvent: string {
	case CHECKOUT_SESSION_COMPLETED = 'checkout.session.completed';
	case REFUND_UPDATED             = 'refund.updated';
}
