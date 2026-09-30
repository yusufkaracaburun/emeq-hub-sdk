<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface ResolvesConnectSessionContext
{
    /**
     * Throw (e.g. `abort(403)`) to deny the user a connect session.
     *
     * @return array{
     *     categories?: list<array{key: string, label: string, type: 'expense'|'income'}>,
     *     mode?: 'manage'|'view',
     *     actor?: array{name: string, email: string},
     * }
     */
    public function context(?Authenticatable $user): array;
}
