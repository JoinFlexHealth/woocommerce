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
	 * @param string              $message The error message.
	 * @param ?\Throwable         $previous The previous error message that should be chained.
	 * @param array<string,mixed> $context Additional context for the exception.
	 */
	public function __construct( string $message = '', ?\Throwable $previous = null, protected array $context = array() ) {
		parent::__construct(
			message: trim( '[Flex] ' . $message ),
			previous: $previous,
		);
	}

	/**
	 * Returns the context of the exception.
	 */
	public function getContext(): array {
		return $this->context;
	}
}
