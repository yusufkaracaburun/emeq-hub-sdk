<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Tests\Doubles;

use Emeq\HubSdk\Backlog\Contracts\ProvidesBacklogSources;
use Emeq\HubSdk\Backlog\PostedDocuments;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class FakeBacklogSources implements ProvidesBacklogSources
{
    public const TABLE = 'test_documents';

    public function __construct(private readonly PostedDocuments $posted) {}

    public function bookable(array $modules): Builder
    {
        $requested = $modules === []
            ? $this->modules()
            : array_values(array_intersect($this->modules(), $modules));

        if ($requested === []) {
            $requested = $this->modules();
        }

        $union = $this->query((string) array_shift($requested));

        foreach ($requested as $module) {
            $union->unionAll($this->query($module));
        }

        return $union;
    }

    public function modules(): array
    {
        return ['invoice', 'transaction'];
    }

    private function query(string $module): Builder
    {
        return DB::table(self::TABLE)
            ->where('module', $module)
            ->select(ProvidesBacklogSources::COLUMNS)
            ->whereNotExists($this->posted->excluding(self::TABLE.'.uuid'));
    }
}
