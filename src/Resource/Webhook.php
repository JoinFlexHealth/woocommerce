<?php
/**
 * Flex Checkout Session Line Item
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Resource;

use Flex\Controller\WebhookController;
use Flex\Exception\FlexResponseException;
use Flex\Exception\FlexException;
use Flex\PaymentGateway;

/**
 * Flex Checkout Session Line Item
 */
class Webhook extends Resource {

	protected const KEY_ID             = 'webhook_id';
	protected const KEY_URL            = 'webhoook_url';
	protected const KEY_HASH           = 'webhoook_hash';
	protected const KEY_SIGNING_SECRET = 'webhoook_signing_secret';
	protected const EVENTS             = array( 'checkout.session.completed' );

	/**
	 * WooCommerce Flex Gateway
	 *
	 * @var ?PaymentGateway
	 */
	protected ?PaymentGateway $wc = null;

	/**
	 * Creates a checkout session line item
	 *
	 * @param string   $url The url the webhooks should subscribe too.
	 * @param ?string  $id The id of the webhook.
	 * @param ?string  $signing_secret The signing secret of the webhook.
	 * @param string[] $events The events the webhook is subscribed too.
	 * @param ?bool    $test_mode Whether the webhook was created in test mode.
	 */
	public function __construct(
		protected string $url,
		protected ?string $id = null,
		protected ?string $signing_secret = null,
		protected array $events = self::EVENTS,
		protected ?bool $test_mode = null,
	) {}

	/**
	 * {@inheritdoc}
	 */
	public function id(): ?string {
		return $this->id;
	}

	/**
	 * Return the base64 decoded secret.
	 *
	 * @throws FlexException If the signing secret is not present or could not be decoded.
	 */
	public function secret(): string {
		$split = explode( '_', $this->signing_secret ?? '' );

		if ( empty( $split[1] ) || ! is_string( $split[1] ) ) {
			throw new FlexException( 'Webhook was received, but secret is not present.' );
		}

		$decoded = base64_decode( $split[1], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $decoded ) {
			throw new FlexException( 'Failed to base64 decode the signing secret.' );
		}

		return $decoded;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Only serialize properties where WooCommerce is the system of record.
	 */
	public function jsonSerialize(): array {
		return array(
			'url'    => $this->url,
			'events' => $this->events,
		);
	}

	/**
	 * Create a new Webhook from the Flex API response.
	 *
	 * @param array $webhook A single webhook from the API response.
	 * @throws FlexException If the url is missing.
	 */
	public static function from_flex( array $webhook ): self {
		if ( ! isset( $webhook['url'] ) ) {
			throw new FlexException( 'URL missing from webhook.' );
		}

		$w = new self( url: $webhook['url'] );
		$w->extract( $webhook );
		return $w;
	}

	/**
	 * Extracts the data from a Flex API response.
	 *
	 * @param array $webhook A single webhook from the API response.
	 */
	protected function extract( array $webhook ) {
		$this->id             = $webhook['webhook_id'] ?? $this->id;
		$this->url            = $webhook['url'] ?? $this->url;
		$this->events         = $webhook['events'] ?? $this->events;
		$this->signing_secret = $webhook['signing_secret'] ?? $this->signing_secret;
		$this->test_mode      = $webhook['test_mode'] ?? $this->test_mode;
	}

	/**
	 * Create a Webhook from a WooCommerce Flex gateway object.
	 *
	 * @param PaymentGateway $flex The WooCommerce Flex gateway object.
	 * @param ?bool          $test_mode Forces test mode to either be disabled or enabled.
	 */
	public static function from_wc( PaymentGateway $flex, ?bool $test_mode = null ): self {
		if ( null === $test_mode ) {
			$test_mode = $flex->is_in_test_mode();
		}

		$prefix = self::key_prefix( $flex, $test_mode );

		$id             = $flex->get_option( $prefix . self::KEY_ID );
		$signing_secret = $flex->get_option( $prefix . self::KEY_SIGNING_SECRET );

		$webhook = new self(
			id: empty( $id ) ? null : $id,
			url: get_rest_url(
				path: WebhookController::NAMESPACE . ( $test_mode ? '/test/webhooks' : '/webhooks' ),
			),
			signing_secret: empty( $signing_secret ) ? null : $signing_secret,
			test_mode: $test_mode,
		);

		$webhook->wc = $flex;

		return $webhook;
	}

	/**
	 * Gets the meta key prefix.
	 *
	 * @param PaymentGateway $flex The Flex Gateway.
	 * @param ?bool          $test_mode Forces the prefix into test or live mode if specified.
	 */
	protected static function key_prefix( PaymentGateway $flex, ?bool $test_mode = null ): string {
		if ( null === $test_mode ) {
			$test_mode = $flex->is_in_test_mode();
		}

		return $test_mode ? 'test_' : '';
	}

	/**
	 * Applies the webhook data onto a Flex Gateway object.
	 * This is effectively the opposite of {@link Webhook::from_wc}.
	 *
	 * @param PaymentGateway $flex The Flex Gateway.
	 */
	public function apply_to( PaymentGateway $flex ): void {
		$prefix = self::key_prefix( $flex );

		$flex->update_options(
			array(
				$prefix . self::KEY_ID             => $this->id,
				$prefix . self::KEY_URL            => $this->url,
				$prefix . self::KEY_SIGNING_SECRET => $this->signing_secret,
				$prefix . self::KEY_HASH           => $this->hash(),
			)
		);
	}

	/**
	 * Removes the webhook data from a Flex Gateway object.
	 *
	 * @param PaymentGateway $flex The Flex Gateway.
	 */
	public function remove_from( PaymentGateway $flex ): void {
		$prefix = self::key_prefix( $flex );

		$flex->remove_options(
			array(
				$prefix . self::KEY_ID,
				$prefix . self::KEY_URL,
				$prefix . self::KEY_SIGNING_SECRET,
				$prefix . self::KEY_HASH,
			)
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * Since this item gets created as part of the checkout session, the only thing that needs to be checked is the
	 * dependencies.
	 */
	public function needs(): ResourceAction {
		// If the line item was not created from WooCommerce, then there is nothing that needs to be done.
		if ( null === $this->wc ) {
			return ResourceAction::NONE;
		}

		// If the gateway is not enabled, delete the current webhook, or do nothing.
		if ( 'no' === $this->wc->enabled ) {
			if ( null !== $this->id ) {
				return ResourceAction::DELETE;
			}

			return ResourceAction::NONE;
		}

		// Refuse to do anything with webhooks that are not https unless instructed to override.
		if ( 'https' !== wp_parse_url( $this->url, PHP_URL_SCHEME ) ) {
			if ( ! defined( 'FLEX_HTTP_WEBHOOKS' ) || \FLEX_HTTP_WEBHOOKS === false ) {
				return ResourceAction::NONE;
			}
		}

		if ( null === $this->id ) {
			return ResourceAction::CREATE;
		}

		$prefix = self::key_prefix( $this->wc, $this->test_mode );

		if ( $this->wc->get_option( $prefix . self::KEY_URL ) !== $this->url ) {
			return ResourceAction::CREATE;
		}

		if ( $this->wc->get_option( $prefix . self::KEY_HASH ) !== $this->hash() ) {
			return ResourceAction::UPDATE;
		}

		return ResourceAction::NONE;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param ResourceAction $action The action to check.
	 */
	public function can( ResourceAction $action ): bool {
		return match ( $action ) {
			ResourceAction::CREATE => true,
			ResourceAction::UPDATE, ResourceAction::DELETE => null !== $this->id,
			default => false,
		};
	}

	/**
	 * Creates or Updates the Webhook.
	 *
	 * @param ResourceAction $action The operation to perform.
	 *
	 * @throws FlexException If anything goes wrong.
	 * @throws FlexResponseException If the response is something other than a 404.
	 */
	public function exec( ResourceAction $action ): void {
		if ( ! $this->can( $action ) ) {
			return;
		}

		// If the Webhook is being re-created, deactivate the existing one.
		$existing = null;
		$webhook  = $this;
		if ( null !== $this->id && ResourceAction::CREATE === $action ) {
			// Retrieve the existing webhook so we do not drop any existing values on re-creation.
			try {
				$existing = new self(
					url: $this->url,
					id: $this->id,
				);

				$data = $this->remote_request(
					'/v1/webhooks/' . $this->id,
				);

				if ( isset( $data['webhook'] ) && is_array( $data['webhook'] ) ) {
					// Remove fields that we no longer care about.
					unset( $data['webhook']['webhook_id'] );
					unset( $data['webhook']['signing_secret'] );

					$webhook = array_merge( $data['webhook'], $this->jsonSerialize() );
				}
			} catch ( FlexResponseException $e ) {
				if ( 404 !== $e->code() ) {
					throw $e;
				}

				// The existing resource does not exist, so we can continue with a new creation by resetting the values.
				$existing = null;
				$webhook  = $this;
			}
		}

		try {
			$data = $this->remote_request(
				match ( $action ) {
					ResourceAction::CREATE =>  '/v1/webhooks',
					ResourceAction::UPDATE, ResourceAction::DELETE =>  '/v1/webhooks/' . $this->id,
				},
				array(
					'method' => match ( $action ) {
						ResourceAction::CREATE, ResourceAction::UPDATE => 'POST',
						ResourceAction::DELETE => 'DELETE',
					},
					'flex'   => array( 'data' => array( 'webhook' => $webhook ) ),
				),
			);

			if ( ResourceAction::DELETE === $action ) {
				if ( null !== $this->wc ) {
					$this->remove_from( $this->wc );
				}
				return;
			}

			if ( ! isset( $data['webhook'] ) ) {
				throw new FlexException( 'Missing webhook in response.' );
			}

			$this->extract( $data['webhook'] );

			if ( null !== $this->wc ) {
				$this->apply_to( $this->wc );
			}
		} catch ( FlexResponseException $e ) {
			if ( 404 === $e->code() ) {
				// The update failed because it no longer exists, force the resource to be recreated.
				if ( ResourceAction::UPDATE === $action ) {
					$this->exec( ResourceAction::CREATE );
					return;
				}

				// If the delete failed because it no longer exists, update the metadata.
				if ( ResourceAction::DELETE === $action ) {
					if ( null !== $this->wc ) {
						$this->remove_from( $this->wc );
					}
					return;
				}
			}

			throw $e;
		}

		// Deactivate the existing Webhook.
		if ( $existing ) {
			$existing->exec( ResourceAction::UPDATE );
		}
	}
}
