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
class FlexResponseException extends FlexException {

	/**
	 * {@inheritdoc}
	 *
	 * @param array       $response The response from wp_remote_request.
	 * @param string      $message The error message.
	 * @param ?\Throwable $previous The previous error message that should be chained.
	 */
	public function __construct(
		protected array $response,
		string $message = '',
		?\Throwable $previous = null
	) {
		parent::__construct(
			message: $message,
			previous: $previous,
		);
	}

	/**
	 * Get the status code of the response.
	 */
	public function code(): string|int {
		return wp_remote_retrieve_response_code( $this->response );
	}
}
