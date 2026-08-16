<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Exceptions;

use Emeq\HubSdk\Booking\Contracts\ResolvesBookableDocument;
use RuntimeException;

/**
 * The current user may not book this document.
 *
 * The SDK defines its own word for this rather than catching the framework's
 * `AuthorizationException`, so authorising stays entirely the consumer's
 * business — a gate, a policy, a role check, whatever it uses. Throw this from
 * {@see ResolvesBookableDocument::resolve()}
 * once that check fails.
 */
class DocumentNotAuthorized extends RuntimeException {}
