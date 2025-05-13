<?php
/**
 * Flex Resource Interface
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Resource;

/**
 * Flex Resource
 */
interface ResourceInterface {
	/**
	 * Returns the id of the resource.
	 */
	public function id(): ?string;

	/**
	 * Determine if any action needs to be taken.
	 */
	public function needs(): ResourceAction;

	/**
	 * Determines if an action can be performed on a resource or not.
	 *
	 * @param ResourceAction $action The action to check.
	 */
	public function can( ResourceAction $action ): bool;

	/**
	 * Executes an operation against the resource.
	 *
	 * @param ResourceAction $action The action to perform.
	 */
	public function exec( ResourceAction $action ): void;
}
