<?php
/**
 * Tests for FlexResponseException.
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Tests\Exception;

use Flex\Exception\FlexResponseException;

/**
 * Tests for FlexResponseException::errorType().
 */
class FlexResponseExceptionTest extends \WP_UnitTestCase {

	/**
	 * Build a wp_remote_request-shaped response with a JSON body.
	 *
	 * @param int                  $code The HTTP status code.
	 * @param array<string, mixed> $body The decoded body to encode.
	 *
	 * @return array<string, mixed>
	 */
	private function response( int $code, array $body ): array {
		return array(
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'body'     => (string) wp_json_encode( $body ),
			'headers'  => array(),
		);
	}

	/**
	 * Returns the machine-readable `type` from the first detail entry.
	 */
	public function test_error_type_returns_first_detail_type(): void {
		$e = new FlexResponseException(
			$this->response(
				422,
				array(
					'detail' => array(
						array(
							'loc'  => array( 'price' ),
							'msg'  => 'price_not_found: One or more prices are not valid',
							'type' => 'price_not_found',
						),
					),
				)
			),
			'Response Failed POST /v1/checkout/sessions 422'
		);

		self::assertSame( 'price_not_found', $e->errorType() );
	}

	/**
	 * Returns null when the body has no detail entries.
	 */
	public function test_error_type_returns_null_without_detail(): void {
		$e = new FlexResponseException(
			$this->response( 500, array( 'message' => 'Internal Server Error' ) ),
			'Response Failed'
		);

		self::assertNull( $e->errorType() );
	}

	/**
	 * Returns null when the body is not valid JSON.
	 */
	public function test_error_type_returns_null_for_non_json_body(): void {
		$e = new FlexResponseException(
			array(
				'response' => array(
					'code'    => 502,
					'message' => '',
				),
				'body'     => '<html>Bad Gateway</html>',
				'headers'  => array(),
			),
			'Response Failed'
		);

		self::assertNull( $e->errorType() );
	}

	/**
	 * A standard error carries its explanation as a string `detail`.
	 */
	public function test_detail_reads_standard_error(): void {
		$e = new FlexResponseException(
			$this->response(
				400,
				array(
					'code'   => 'refund_amount_too_large',
					'detail' => 'Refund amount exceeds the amount captured',
				)
			)
		);

		self::assertSame( 'Refund amount exceeds the amount captured', $e->detail() );
	}

	/**
	 * A validation error joins the `msg` of every entry.
	 */
	public function test_detail_joins_validation_messages(): void {
		$e = new FlexResponseException(
			$this->response(
				422,
				array(
					'detail' => array(
						array(
							'loc'  => array( 'a' ),
							'msg'  => 'first problem',
							'type' => 'value_error',
						),
						array(
							'loc'  => array( 'b' ),
							'msg'  => 'second problem',
							'type' => 'value_error',
						),
					),
				)
			)
		);

		self::assertSame( 'first problem; second problem', $e->detail() );
	}

	/**
	 * A body with no usable explanation yields null, so callers fall back to their own wording.
	 */
	public function test_detail_returns_null_without_explanation(): void {
		$html = new FlexResponseException(
			array(
				'response' => array(
					'code'    => 502,
					'message' => '',
				),
				'body'     => '<html>Bad Gateway</html>',
				'headers'  => array(),
			)
		);

		self::assertNull( $html->detail() );
		self::assertNull( ( new FlexResponseException( $this->response( 500, array( 'detail' => '' ) ) ) )->detail() );
		self::assertNull( ( new FlexResponseException( $this->response( 500, array( 'detail' => '   ' ) ) ) )->detail() );
		self::assertNull(
			( new FlexResponseException(
				$this->response(
					422,
					array(
						'detail' => array(
							array(
								'loc'  => array( 'a' ),
								'msg'  => " \n",
								'type' => 'value_error',
							),
						),
					)
				)
			) )->detail()
		);
	}

	/**
	 * Surrounding whitespace is trimmed from each explanation.
	 */
	public function test_detail_trims_whitespace(): void {
		self::assertSame( 'Refund too large', ( new FlexResponseException( $this->response( 400, array( 'detail' => '  Refund too large ' ) ) ) )->detail() );
	}
}
