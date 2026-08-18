<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Tests;

use Emeq\HubSdk\HubServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            HubServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('hub.base_url', 'https://hub.example.test');
        $app['config']->set('hub.pat', 'test-pat-token');
        $app['config']->set('hub.timeout', 10);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('hub.routes.enabled', false);
    }
}
