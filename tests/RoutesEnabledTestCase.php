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
        // Testbench has no auth guard to hit; the BFF's own behaviour is what
        // these tests exercise, so the auth assertion is opted out explicitly.
        $app['config']->set('hub.routes.middleware', ['api']);
        $app['config']->set('hub.routes.allow_unauthenticated', true);
        $app['config']->set('hub.oauth.return_path', '/integrations/oauth-callback');
    }
}
