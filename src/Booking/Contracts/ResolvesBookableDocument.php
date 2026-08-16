<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Booking\Contracts;

use Emeq\HubSdk\Backlog\Contracts\ProvidesBacklogSources;
use Emeq\HubSdk\Booking\BookableDocument;
use Emeq\HubSdk\Booking\BookingRunner;
use Emeq\HubSdk\Exceptions\DocumentNotAuthorized;
use Emeq\HubSdk\Exceptions\DocumentNotBookable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Turns "module X, id Y" into something sendable.
 *
 * Everything specific to a consumer lives behind this one call: finding the
 * record, authorising the user, and mapping it onto the canonical shape.
 * {@see BookingRunner} then owns which failure becomes
 * which answer.
 */
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
