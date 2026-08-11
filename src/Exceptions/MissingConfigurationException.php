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
        );
    }

    public static function missingPat(): self
    {
        return new self(
            'hub.pat / EMEQ_HUB_PAT is not configured.',
            error: 'missing_configuration',
            category: 'CONFIGURATION_ERROR',
        );
    }
}
