<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Facades;

use Emeq\HubSdk\Http\HubConnector;
use Emeq\HubSdk\Resources\Accounting;
use Emeq\HubSdk\Resources\Accounts;
use Emeq\HubSdk\Resources\Connections;
use Emeq\HubSdk\Resources\ConnectSessions;
use Emeq\HubSdk\Resources\Integrations;
use Emeq\HubSdk\Resources\Itheorie;
use Emeq\HubSdk\Resources\OAuth;
use Illuminate\Support\Facades\Facade;

/**
 * @method static HubConnector connector()
 * @method static Accounts accounts()
 * @method static Integrations integrations()
 * @method static OAuth oauth()
 * @method static ConnectSessions connectSessions()
 * @method static Connections connections()
 * @method static Accounting accounting()
 * @method static Itheorie itheorie()
 */
class Hub extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Emeq\HubSdk\Hub::class;
    }
}
