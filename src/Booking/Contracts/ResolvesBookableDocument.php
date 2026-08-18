<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Booking\Contracts;

use Emeq\HubSdk\Backlog\Contracts\ProvidesBacklogSources;
use Emeq\HubSdk\Booking\BookableDocument;
use Emeq\HubSdk\Exceptions\DocumentNotAuthorized;
use Emeq\HubSdk\Exceptions\DocumentNotBookable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

interface ResolvesBookableDocument
{
    /**
     * @param  string  $module  one of the names {@see ProvidesBacklogSources::modules()} declares
     * @param  string  $id  however this consumer addresses the document — commonly its uuid
     *
     * @throws ModelNotFoundException no document with that id exists
     * @throws DocumentNotAuthorized this user may not book it
     * @throws DocumentNotBookable it can never be sent as it stands
     */
    public function resolve(string $module, string $id): BookableDocument;
}
