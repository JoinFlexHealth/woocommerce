<?php
/**
 * Flex Abstract Resource
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Resource;

use Flex\Exception\FlexException;
use Flex\Exception\FlexResponseException;
use Flex\PaymentGateway;
use Sentry\Breadcrumb;

use function Flex\payment_gateway;
use function Flex\sentry;

/**
 * Flex Product
 */
abstract class Resource implements ResourceInterface, \JsonSerializable {

	protected const META_PREFIX      = '_wc_flex_';
	protected const META_PREFIX_TEST = self::META_PREFIX . 'test_';

	/**
	 * {@inheritdoc}
	 *
	 * @throws FlexException If JSON encoding fails.
	 */
	protected function hash(): string {
		$data = wp_json_encode( $this );
		if ( false === $data ) {
			throw new FlexException( 'JSON Encode Failed' );
		}

		return wp_hash( $data );
	}

	/**
	 * Safely convert a currency amount (from WooCommerce) to a Flex unit amount.
	 *
	 * @param int|float|string $value The currency value to convert to a unit amount.
	 */
	public static function currency_to_unit_amount( int|float|string $value ): int {
		if ( is_string( $value ) ) {
			// Remove a negative sign, currency symbols, etc.
			$currency_symbol = html_entity_decode( get_woocommerce_currency_symbol() );
			$value           = trim( $value, $currency_symbol . "- \n\r\t\v\0" );
		} else {
			$value = abs( $value );
		}

		// Split the string based on the decimal separator.
		$parts = explode( wc_get_price_decimal_separator(), (string) $value );

		return intval(
			// Remove the thousand separator and concatenate the dollars with the padded cents.
			str_replace( wc_get_price_thousand_separator(), '', $parts[0] ) . str_pad( $parts[1] ?? '', wc_get_price_decimals(), '0' )
		);
	}

	/**
	 * Retrieves the Flex payment gateway for configuration.
	 */
	protected static function payment_gateway(): PaymentGateway {
		return payment_gateway();
	}

	/**
	 * Request to the Flex API.
	 *
	 * @param string $path The path, starting with a forward slash, to the resource.
	 * @param array  $args The arguments to pass to {@link wp_remote_request}.
	 *
	 * @throws FlexException When things don't go well.
	 * @throws FlexResponseException When Flex responds with something other than OK.
	 * @throws \Throwable If the JSON decoding fails.
	 */
	protected static function remote_request( string $path, array $args = array() ): array {
		$api_key = self::payment_gateway()->api_key();
		if ( empty( $api_key ) ) {
			throw new FlexException( 'API Key is not set' );
		}

		$base = defined( 'FLEX_API_URL' ) ? \FLEX_API_URL : 'https://api.withflex.com';

		$headers = array(
			'Authorization' => 'Bearer ' . $api_key,
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
			'Origin'        => home_url(),
			'Referer'       => home_url( add_query_arg( array() ) ),
		);

		$span = sentry()->getSpan();
		if ( null !== $span ) {
			$headers['traceparent'] = $span->toW3CTraceparent();
		}

		/**
		 * The metadata to add to the breadcrumb
		 * https://develop.sentry.dev/sdk/data-model/event-payloads/breadcrumbs/#breadcrumb-types
		 */
		$meta = array(
			'method' => $args['method'] ?? 'GET',
			'url'    => $base . $path,
		);

		if ( isset( $args['flex']['data'] ) ) {
			$meta['request'] = $args['flex']['data'];
		}

		$response = wp_remote_request(
			$base . $path,
			array_merge(
				array(
					'headers' => array_merge(
						$headers,
						$args['headers'] ?? array(),
					),
					'body'    => $args['body'] ?? isset( $args['flex']['data'] ) ? wp_json_encode( $args['flex']['data'] ) : null,
				),
				$args,
			),
		);

		if ( is_wp_error( $response ) ) {
			sentry()->addBreadcrumb(
				new Breadcrumb(
					category: 'request',
					level: Breadcrumb::LEVEL_ERROR,
					type: Breadcrumb::TYPE_HTTP,
					metadata: $meta,
				)
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new FlexException( $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );

		$meta['status_code'] = $code;

		if ( $code < 200 || $code >= 300 ) {
			sentry()->addBreadcrumb(
				new Breadcrumb(
					category: 'request',
					level: Breadcrumb::LEVEL_ERROR,
					type: Breadcrumb::TYPE_HTTP,
					metadata: $meta,
				)
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new FlexResponseException( $response, 'Flex responded with a ' . $code );
		}

		$body = wp_remote_retrieve_body( $response );

		if ( ! $body ) {
			sentry()->addBreadcrumb(
				new Breadcrumb(
					category: 'request',
					level: Breadcrumb::LEVEL_ERROR,
					type: Breadcrumb::TYPE_HTTP,
					metadata: $meta,
				),
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new FlexResponseException( $response, 'Missing response body.' );
		}

		try {
			$data = json_decode(
				json: $body,
				associative: true,
				flags: JSON_THROW_ON_ERROR
			);

			$meta['response'] = $data;

			sentry()->addBreadcrumb(
				new Breadcrumb(
					category: 'request',
					level: Breadcrumb::LEVEL_INFO,
					type: Breadcrumb::TYPE_HTTP,
					metadata: $meta,
				),
			);

			return $data;
		} catch ( \Throwable $e ) {

			$meta['response'] = $body;

			sentry()->addBreadcrumb(
				new Breadcrumb(
					category: 'request',
					level: Breadcrumb::LEVEL_ERROR,
					type: Breadcrumb::TYPE_HTTP,
					metadata: $meta,
				),
			);

			throw $e;
		}
	}

	/**
	 * Gets the meta key prefix.
	 */
	protected static function meta_prefix(): string {
		return self::payment_gateway()->is_in_test_mode() ? self::META_PREFIX_TEST : self::META_PREFIX;
	}

	/**
	 * {@inheritdoc}
	 */
	public function needs(): ResourceAction {
		return ResourceAction::NONE;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param ResourceAction $action The action to check.
	 */
	public function can( ResourceAction $action ): bool {
		return false;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param ResourceAction $action The operation to perform.
	 *
	 * @throws \Exception If anything goes wrong.
	 */
	public function exec( ResourceAction $action ): void {}
}
