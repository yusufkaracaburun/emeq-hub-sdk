<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Webhooks;

/**
 * What happened to the entity, as opposed to which kind of entity it is —
 * that part is {@see HubWebhookEvent}.
 *
 * Keep in sync with Hub `App\Integrations\Webhooks\CanonicalAction`.
 *
 * Absent and {@see self::UNMAPPED} are different answers. `$envelope->action`
 * is `null` when the provider sends no action at all (Mollie's notification is
 * a bare resource id); it is `UNMAPPED` when the provider did send one and this
 * SDK release has no case for it.
 */
enum HubWebhookAction: string
{
    case CREATED = 'created';

    case UPDATED = 'updated';

    case DELETED = 'deleted';

    case UNMAPPED = 'unmapped';

    /**
     * Never throws: an unknown wire value is an action this SDK release does
     * not know about yet, not a broken payload. A missing value stays `null`.
     */
    public static function tryFromWire(mixed $value): ?self
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return self::tryFrom($value) ?? self::UNMAPPED;
    }
}
