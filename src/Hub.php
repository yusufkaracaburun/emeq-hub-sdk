<?php

declare(strict_types=1);

namespace Emeq\HubSdk;

use Emeq\HubSdk\Contracts\ResolvesAccountId;
use Emeq\HubSdk\Http\HubConnector;
use Emeq\HubSdk\Resources\Accounting;
use Emeq\HubSdk\Resources\Accounts;
use Emeq\HubSdk\Resources\Connections;
use Emeq\HubSdk\Resources\ConnectSessions;
use Emeq\HubSdk\Resources\Integrations;
use Emeq\HubSdk\Resources\Itheorie;
use Emeq\HubSdk\Resources\OAuth;

class Hub
{
    public function __construct(
        private readonly HubConnector $connector,
        private readonly ?ResolvesAccountId $accountIdResolver = null,
    ) {}

    public function connector(): HubConnector
    {
        return $this->connector;
    }

    public function accounts(): Accounts
    {
        return new Accounts($this->connector);
    }

    public function integrations(): Integrations
    {
        return new Integrations($this->connector, $this->accountIdResolver);
    }

    public function oauth(): OAuth
    {
        return new OAuth($this->connector, $this->accountIdResolver);
    }

    public function connectSessions(): ConnectSessions
    {
        return new ConnectSessions($this->connector, $this->accountIdResolver);
    }

    public function connections(): Connections
    {
        return new Connections($this->connector);
    }

    public function accounting(): Accounting
    {
        return new Accounting($this->connector, $this->accountIdResolver);
    }

    public function itheorie(): Itheorie
    {
        return new Itheorie($this->connector);
    }
}
