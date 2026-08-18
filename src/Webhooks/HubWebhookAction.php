<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Webhooks;

enum HubWebhookAction: string
{
    case CREATED = 'created';

    case UPDATED = 'updated';

    case DELETED = 'deleted';

    case UNMAPPED = 'unmapped';

    public static function tryFromWire(mixed $value): ?self
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return self::tryFrom($value) ?? self::UNMAPPED;
    }
}
