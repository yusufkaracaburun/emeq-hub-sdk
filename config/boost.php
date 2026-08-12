<?php

declare(strict_types=1);
use Laravel\Boost\Mcp\Tools\BrowserLogs;
use Laravel\Boost\Mcp\Tools\DatabaseConnections;
use Laravel\Boost\Mcp\Tools\DatabaseQuery;
use Laravel\Boost\Mcp\Tools\DatabaseSchema;
use Laravel\Boost\Mcp\Tools\GetAbsoluteUrl;
use Laravel\Boost\Mcp\Tools\LastError;
use Laravel\Boost\Mcp\Tools\ReadLogEntries;

/*
|--------------------------------------------------------------------------
| Boost configuration for this PACKAGE repository
|--------------------------------------------------------------------------
|
| This repository ships an SDK, not an application: there is no database,
| no HTTP kernel serving pages and no application log. Boost is booted
| through the development-only `artisan` shim in the repository root.
|
| The tools that depend on a running application are therefore disabled so
| they do not show up in the agent's tool list. This file is excluded from
| the distributed archive via .gitattributes and is never published to
| consumers of this package (only config/hub.php is).
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Boost Master Switch
    |--------------------------------------------------------------------------
    |
    | This option may be used to disable all Boost functionality which will
    | prevent Boost's routes from being registered and will also disable
    | Boost's browser logging functionality from reading or operating.
    |
    */

    'enabled' => env('BOOST_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Boost Project Rules
    |--------------------------------------------------------------------------
    |
    | Project rules let agents write decisions, traps and standing constraints
    | as tracked Markdown in /.ai/rules/. Enabling "scoped_guidelines" also
    | moves path-scoped guidelines to .ai/rules/boost/ - it stays opt-in.
    |
    */

    'rules' => [
        'enabled' => env('BOOST_RULES_ENABLED', true),
        'scoped_guidelines' => env('BOOST_RULES_SCOPED_GUIDELINES', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Guidelines
    |--------------------------------------------------------------------------
    |
    | Any guidelines listed here will be excluded whenever Boost composes your
    | AI guidelines during boost:install or boost:update. Entries match the
    | names shown within the boost:install summary, e.g. "livewire/core".
    |
    */

    'guidelines' => [
        'exclude' => [
            // Laravel Cloud deployment advice — this repository deploys nothing.
            'deployments',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Skills
    |--------------------------------------------------------------------------
    |
    | Any skills listed here will not be installed or synced to your agents
    | by boost:install and boost:update, e.g. "fluxui-development". Your
    | own skills within the ".ai/skills" directory are never excluded.
    |
    */

    'skills' => [
        'exclude' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Boost Executables Paths
    |--------------------------------------------------------------------------
    |
    | These options allow you to specify custom paths for the executables that
    | Boost uses. While configured, they take precedence over the automatic
    | discovery mechanism. When undefined, your system defaults are used.
    |
    */

    'executable_paths' => [
        'php' => env('BOOST_PHP_EXECUTABLE_PATH'),
        'composer' => env('BOOST_COMPOSER_EXECUTABLE_PATH'),
        'npm' => env('BOOST_NPM_EXECUTABLE_PATH'),
        'vendor_bin' => env('BOOST_VENDOR_BIN_EXECUTABLE_PATH'),
        'current_directory' => env('BOOST_CURRENT_DIRECTORY_EXECUTABLE_PATH'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Boost Browser Logs Watcher
    |--------------------------------------------------------------------------
    |
    | The following option may be used to enable or disable the browser logs
    | watcher feature within Laravel Boost. The log watcher will read any
    | errors within the browser's console to give Boost better context.
    |
    */

    'browser_logs_watcher' => env('BOOST_BROWSER_LOGS_WATCHER', false),

    /*
    |--------------------------------------------------------------------------
    | Browser Log Levels
    |--------------------------------------------------------------------------
    |
    | This option defines which browser console log levels will be captured by
    | Boost's browser logger. You may trim this list down to ['error'] when
    | warnings, info, and debug messages become too noisy to be relevant.
    |
    */

    'browser_log_levels' => explode(',', env('BOOST_BROWSER_LOG_LEVELS', 'error,warning,info,debug')),

    /*
    |--------------------------------------------------------------------------
    | MCP Tools
    |--------------------------------------------------------------------------
    |
    | Excluded here: every tool that needs an application to be meaningful.
    | There is no database, no browser session, no application log and no
    | routing table in this repository, so those tools can only mislead.
    |
    | What remains: application-info (package versions from composer.lock),
    | search-docs (version-pinned Laravel ecosystem docs), and record-rule
    | (writes durable project rules to .ai/rules/).
    |
    */

    'mcp' => [
        'tools' => [
            'exclude' => [
                BrowserLogs::class,
                DatabaseConnections::class,
                DatabaseQuery::class,
                DatabaseSchema::class,
                GetAbsoluteUrl::class,
                LastError::class,
                ReadLogEntries::class,
            ],
            'include' => [],
        ],
        'tool_timeout' => 180,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tinker Tool
    |--------------------------------------------------------------------------
    |
    | Off by default — it evaluates arbitrary PHP. Set BOOST_TINKER_TOOL_ENABLED
    | to true when you want an agent to poke at SDK classes directly (it boots
    | through the artisan shim, so the package's own providers are available).
    |
    */

    'tinker_tool_enabled' => env('BOOST_TINKER_TOOL_ENABLED', false),

];
