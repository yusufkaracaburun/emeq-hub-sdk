<?php

declare(strict_types=1);

use Emeq\HubSdk\Tests\RoutesEnabledTestCase;
use Emeq\HubSdk\Tests\TestCase;

uses(TestCase::class)->in('Feature/HubClientTest.php');
uses(TestCase::class)->in('Unit');
uses(RoutesEnabledTestCase::class)->in('Feature/IntegrationRoutesTest.php');
