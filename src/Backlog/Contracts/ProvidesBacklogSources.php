<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Backlog\Contracts;

use Illuminate\Database\Query\Builder;

interface ProvidesBacklogSources
{
    public const COLUMNS = [
        'module',
        'uuid',
        'number',
        'date',
        'amount',
        'party',
        'direction',
        'head',
        'document_status',
    ];

    /** @param  list<string>  $modules */
    public function bookable(array $modules): Builder;

    /** @return list<string> */
    public function modules(): array;
}
