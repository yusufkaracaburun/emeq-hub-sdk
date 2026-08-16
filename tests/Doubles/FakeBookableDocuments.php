<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Tests\Doubles;

use Emeq\HubSdk\Booking\BookableDocument;
use Emeq\HubSdk\Booking\Contracts\ResolvesBookableDocument;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

final class FakeBookableDocuments implements ResolvesBookableDocument
{
    /**
     * @param  array<string, BookableDocument|Throwable>  $map  keyed "module:id"
     */
    public function __construct(private array $map = []) {}

    public function resolve(string $module, string $id): BookableDocument
    {
        $found = $this->map[$module.':'.$id] ?? new ModelNotFoundException;

        if ($found instanceof Throwable) {
            throw $found;
        }

        return $found;
    }
}
