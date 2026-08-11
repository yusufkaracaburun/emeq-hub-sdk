<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Tests;

class RoutesEnabledTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('hub.routes.enabled', true);
        $app['config']->set('hub.routes.prefix', 'api');
        $app['config']->set('hub.routes.middleware', ['api']);
        $app['config']->set('hub.oauth.return_path', '/integrations/oauth-callback');
    }
}
