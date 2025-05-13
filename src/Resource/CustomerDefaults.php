<?php
/**
 * Flex Checkout Session
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Resource;

use Flex\FlexException;

/**
 * Flex Customer Defaults
 */
class CustomerDefaults extends Resource implements ResourceInterface {

	/**
	 * WooCommerce Order
	 *
	 * @var ?\WC_Order
	 */
	protected ?\WC_Order $wc = null;

	/**
	 * Creates a checkout session defaults
	 *
	 * @param ?string $id The customer id.
	 * @param ?string $email The customer's email.
	 * @param ?string $first_name The customer's first name.
	 * @param ?string $last_name The customer's last name.
	 * @param ?string $phone The customer's phone.
	 */
	public function __construct(
		protected ?string $id = null,
		protected ?string $email = null,
		protected ?string $first_name = null,
		protected ?string $last_name = null,
		protected ?string $phone = null,
	) {}

	/**
	 * {@inheritdoc}
	 */
	public function id(): ?string {
		return $this->id;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Only serialize properties where WooCommerce is the system of record.
	 */
	public function jsonSerialize(): array {
		$data = array();

		if ( null !== $this->id ) {
			$data['customer_id'] = $this->id;
		}

		if ( null !== $this->email ) {
			$data['email'] = $this->email;
		}

		if ( null !== $this->first_name ) {
			$data['first_name'] = $this->first_name;
		}

		if ( null !== $this->last_name ) {
			$data['last_name'] = $this->last_name;
		}

		if ( null !== $this->phone ) {
			$data['phone'] = $this->phone;
		}

		return $data;
	}

	/**
	 * Creates a Checkout Session from a WooCommerce Order.
	 *
	 * @param \WC_Order $order the WooCommerce Order.
	 */
	public static function from_wc( \WC_Order $order ): self {
		$defaults = new self(
			email: $order->get_billing_email(),
			first_name: $order->get_billing_first_name(),
			last_name: $order->get_billing_last_name(),
			phone: $order->get_billing_phone(),
		);

		$defaults->wc = $order;

		return $defaults;
	}

	/**
	 * Extract the values from a checkout session defaults API response.
	 *
	 * @param array $defaults The checkout session object returned from the API.
	 *
	 * @throws FlexException If data is missing.
	 */
	public static function from_flex( array $defaults ): self {
		$cs = new self();
		$cs->extract( $defaults );
		return $cs;
	}

	/**
	 * Extract the values from a checkout session defaults API response.
	 *
	 * @param array $defaults The checkout session object returned from the API.
	 *
	 * @throws \Exception If data is missing.
	 */
	protected function extract( array $defaults ): void {
		$this->id         = $defaults['customer_id'] ?? $this->id;
		$this->email      = $defaults['email'] ?? $this->email;
		$this->first_name = $defaults['first_name'] ?? $this->first_name;
		$this->last_name  = $defaults['last_name'] ?? $this->last_name;
		$this->phone      = $defaults['phone'] ?? $this->phone;
	}
}
