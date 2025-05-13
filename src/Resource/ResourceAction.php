<?php
/**
 * Flex Data Operations.
 *
 * @package Flex
 */

declare(strict_types=1);

namespace Flex\Resource;

enum ResourceAction {
	// The resource needs to be created.
	case CREATE;
	// The resource needs to be updated.
	case UPDATE;
	// The resource needs to be refreshed from the Flex API.
	case REFRESH;
	// The resource needs a resolution on a dependency before any other action may be performed.
	case DEPENDENCY;
	// The resource needs to be deleted.
	case DELETE;
	// The resource does not need to perform any action.
	case NONE;
}
