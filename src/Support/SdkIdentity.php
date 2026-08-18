<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Support;

use Composer\InstalledVersions;
use Throwable;

final class SdkIdentity
{
    public const PACKAGE = 'emeq/hub-sdk';

    public const VERSION_HEADER = 'X-Emeq-Sdk-Version';

    private static ?string $userAgent = null;

    public static function version(): string
    {
        return self::versionOf(self::PACKAGE);
    }

    public static function userAgent(): string
    {
        return self::$userAgent ??= sprintf(
            'emeq-hub-sdk/%s php/%s laravel/%s',
            self::version(),
            PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION,
            self::versionOf('laravel/framework'),
        );
    }

    public static function forget(): void
    {
        self::$userAgent = null;
    }

    private static function versionOf(string $package): string
    {
        if (! class_exists(InstalledVersions::class)) {
            return 'unknown';
        }

        try {
            return InstalledVersions::getPrettyVersion($package) ?? 'unknown';
        } catch (Throwable) {
            return 'unknown';
        }
    }
}
