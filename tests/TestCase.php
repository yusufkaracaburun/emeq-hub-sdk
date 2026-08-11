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
        // Opt-in BFF routes off by default in unit tests; enable in RoutesEnabledTestCase.
        $app['config']->set('hub.routes.enabled', false);
    }
}
