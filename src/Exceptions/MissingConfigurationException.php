<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Exceptions;

class MissingConfigurationException extends HubException
{
    public static function missingBaseUrl(): self
    {
        return new self(
            'hub.base_url / EMEQ_HUB_BASE is not configured.',
            error: 'missing_configuration',
            category: 'CONFIGURATION_ERROR',
            status: 503,
        );
    }

    public static function missingPat(): self
    {
        return new self(
            'hub.pat / EMEQ_HUB_PAT is not configured.',
            error: 'missing_configuration',
            category: 'CONFIGURATION_ERROR',
            status: 503,
        );
    }

    public static function missingAccountResolver(): self
    {
        return new self(
            'Bind Emeq\\HubSdk\\Contracts\\ResolvesAccountId before using Hub integration routes.',
            error: 'missing_account_resolver',
            category: 'CONFIGURATION_ERROR',
            status: 503,
        );
    }
}
