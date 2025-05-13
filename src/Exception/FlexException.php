<?php
/**
 * Flex Exception
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Exception;

/**
 * Flex Exception
 */
class FlexException extends \Exception {

	/**
	 * {@inheritdoc}
	 *
	 * @param string      $message The error message.
	 * @param ?\Throwable $previous The previous error message that should be chained.
	 */
	public function __construct( string $message = '', ?\Throwable $previous = null ) {
		parent::__construct(
			message: trim( '[Flex] ' . $message ),
			previous: $previous,
		);
	}
}
