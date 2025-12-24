<?php
/**
 * Tests for the CheckoutSession::needs() method
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Tests\Resource\CheckoutSession;

use Flex\Resource\CheckoutSession\CheckoutSession;
use Flex\Resource\CheckoutSession\Discount;
use Flex\Resource\CheckoutSession\LineItem;
use Flex\Resource\CheckoutSession\Status;
use Flex\Resource\ResourceAction;

/**
 * Test the CheckoutSession::needs() method.
 *
 * The needs() method determines what action should be taken for a checkout session:
 * - DEPENDENCY: If any line items or discounts need action
 * - NONE: If the status is COMPLETE
 * - CREATE: Otherwise (status is OPEN, null, or any other non-COMPLETE value)
 */
class CheckoutSessionTest extends \WP_UnitTestCase {

	/**
	 * Test that needs() returns NONE when status is COMPLETE.
	 */
	public function test_needs_returns_none_when_status_is_complete(): void {
		$checkout_session = new CheckoutSession(
			success_url: 'https://example.com/success',
			status: Status::COMPLETE,
		);

		$this->assertSame( ResourceAction::NONE, $checkout_session->needs() );
	}

	/**
	 * Test that needs() returns CREATE when status is OPEN.
	 */
	public function test_needs_returns_create_when_status_is_open(): void {
		$checkout_session = new CheckoutSession(
			success_url: 'https://example.com/success',
			status: Status::OPEN,
		);

		$this->assertSame( ResourceAction::CREATE, $checkout_session->needs() );
	}

	/**
	 * Test that needs() returns CREATE when status is null.
	 */
	public function test_needs_returns_create_when_status_is_null(): void {
		$checkout_session = new CheckoutSession(
			success_url: 'https://example.com/success',
			status: null,
		);

		$this->assertSame( ResourceAction::CREATE, $checkout_session->needs() );
	}

	/**
	 * Test that needs() returns DEPENDENCY when a line item needs action.
	 */
	public function test_needs_returns_dependency_when_line_item_needs_action(): void {
		$line_item = $this->createStub( LineItem::class );
		$line_item->method( 'needs' )->willReturn( ResourceAction::CREATE );

		$checkout_session = new CheckoutSession(
			success_url: 'https://example.com/success',
			line_items: array( $line_item ),
			status: Status::OPEN,
		);

		$this->assertSame( ResourceAction::DEPENDENCY, $checkout_session->needs() );
	}

	/**
	 * Test that needs() returns DEPENDENCY when a discount needs action.
	 */
	public function test_needs_returns_dependency_when_discount_needs_action(): void {
		$discount = $this->createStub( Discount::class );
		$discount->method( 'needs' )->willReturn( ResourceAction::DEPENDENCY );

		$checkout_session = new CheckoutSession(
			success_url: 'https://example.com/success',
			status: Status::OPEN,
			discounts: array( $discount ),
		);

		$this->assertSame( ResourceAction::DEPENDENCY, $checkout_session->needs() );
	}

	/**
	 * Test that dependencies are checked before status.
	 *
	 * Even if the status is COMPLETE, if a line item needs action,
	 * the result should be DEPENDENCY (not NONE).
	 */
	public function test_needs_checks_dependencies_before_status(): void {
		$line_item = $this->createStub( LineItem::class );
		$line_item->method( 'needs' )->willReturn( ResourceAction::DEPENDENCY );

		$checkout_session = new CheckoutSession(
			success_url: 'https://example.com/success',
			line_items: array( $line_item ),
			status: Status::COMPLETE,
		);

		$this->assertSame(
			ResourceAction::DEPENDENCY,
			$checkout_session->needs(),
			'Dependencies should be checked before status'
		);
	}

	/**
	 * Test that needs() returns NONE when status is COMPLETE and no dependencies need action.
	 */
	public function test_needs_returns_none_when_complete_with_satisfied_dependencies(): void {
		$line_item = $this->createStub( LineItem::class );
		$line_item->method( 'needs' )->willReturn( ResourceAction::NONE );

		$discount = $this->createStub( Discount::class );
		$discount->method( 'needs' )->willReturn( ResourceAction::NONE );

		$checkout_session = new CheckoutSession(
			success_url: 'https://example.com/success',
			line_items: array( $line_item ),
			status: Status::COMPLETE,
			discounts: array( $discount ),
		);

		$this->assertSame( ResourceAction::NONE, $checkout_session->needs() );
	}
}
