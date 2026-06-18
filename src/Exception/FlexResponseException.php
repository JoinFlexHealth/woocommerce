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
	 * @param array<string, mixed> $response The response from wp_remote_request.
	 * @param string               $message  The error message.
	 * @param ?\Throwable          $previous The previous error message that should be chained.
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

	/**
	 * Get the machine-readable error type from the response body.
	 *
	 * Flex validation errors are shaped as
	 * `{"detail":[{"loc":[...],"msg":"...","type":"price_not_found"}]}`. Returns
	 * the `type` of the first detail entry, or null if the body has none or is
	 * not JSON. Lets callers branch on a specific error (e.g. `price_not_found`)
	 * rather than the overloaded 422 status.
	 */
	public function errorType(): ?string {
		$body = wp_remote_retrieve_body( $this->response );
		if ( '' === $body ) {
			return null;
		}

		try {
			$data = json_decode( $body, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			return null;
		}

		if ( ! is_array( $data ) || ! isset( $data['detail'] ) || ! is_array( $data['detail'] ) ) {
			return null;
		}

		foreach ( $data['detail'] as $item ) {
			if ( is_array( $item ) && isset( $item['type'] ) && is_string( $item['type'] ) ) {
				return $item['type'];
			}
		}

		return null;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 */
	public function getContext(): array {
		return $this->response;
	}
}
