<?php
/**
 * Flex Refund Status.
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Resource;

enum RefundStatus: string {
	case PENDING         = 'pending';
	case REQUIRES_ACTION = 'requires_action';
	case SUCCEEDED       = 'succeeded';
	case FAILED          = 'failed';
	case CANCELED        = 'canceled';

	/**
	 * Determine if the refund should be considered a failure.
	 */
	public function failure(): bool {
		return match ( $this ) {
			self::FAILED, self::CANCELED => true,
			default => false,
		};
	}
}
