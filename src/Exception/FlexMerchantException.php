<?php
/**
 * Flex Merchant Exception
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Exception;

/**
 * A failure only the merchant can fix: their store data, or how their code calls the plugin.
 *
 * The message is written for the merchant. process_refund() logs these to WooCommerce and keeps them out of Sentry.
 */
class FlexMerchantException extends FlexException {
}
