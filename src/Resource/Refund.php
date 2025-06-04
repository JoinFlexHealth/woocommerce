<?php
/**
 * Flex Refund.
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Resource;

/**
 * Flex Shipping Rate
 */
class Refund extends Resource {

	/**
	 * WooCommerce Order Refund
	 *
	 * @var ?\WC_Order_Refund
	 */
	protected ?\WC_Order_Refund $wc = null;

	/**
	 * Creates a refund
	 *
	 * @param ?string               $id The id of the refund.
	 * @param ?RefundStatus         $status The status of the refund.
	 * @param ?array<string,string> $metadata Metadata to attach to the refund.
	 */
	public function __construct(
		protected ?string $id = null,
		protected ?RefundStatus $status = null,
		protected ?array $metadata = null,
	) {}

	/**
	 * {@inheritdoc}
	 */
	public function id(): ?string {
		return $this->id;
	}

	/**
	 * The status of the refund.
	 */
	public function status(): ?RefundStatus {
		return $this->status;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Only serialize properties where WooCommerce is the system of record.
	 */
	public function jsonSerialize(): array {
		return array();
	}

	/**
	 * Extract the values from a refund API response.
	 *
	 * @param array $refund The refund object returned from the API.
	 */
	protected function extract( array $refund ): void {
		$this->id       = $refund['refund_id'] ?? $this->id;
		$this->status   = RefundStatus::tryFrom( $refund['status'] ?? '' ) ?? $this->status;
		$this->metadata = isset( $refund['metadata'] ) && is_array( $refund['metadata'] ) ? $refund['metadata'] : $this->metadata;
	}

	/**
	 * Extract the values from a refund API response.
	 *
	 * @param array $refund The refund object returned from the API.
	 *
	 * @throws FlexException If data is missing.
	 */
	public static function from_flex( array $refund ): self {
		$r = new self();
		$r->extract( $refund );
		return $r;
	}

	/**
	 * Returns the WooCommerce order associated with this Checkout Session, if there is one.
	 */
	public function wc(): ?\WC_Order_Refund {
		if ( null === $this->wc && isset( $this->metadata['refund_id'] ) ) {
			$order = wc_get_order( $this->metadata['refund_id'] );
			if ( $order instanceof \WC_Order_Refund ) {
				$this->wc = $order;
			}
		}

		return $this->wc;
	}
}
