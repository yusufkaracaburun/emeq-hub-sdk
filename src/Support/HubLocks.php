<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Support;

use Emeq\HubSdk\Exceptions\MissingConfigurationException;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

final class HubLocks
{
    public const BOOKING_STORE = 'hub.booking.lock_store';

    public const WEBHOOK_STORE = 'hub.webhook.lock_store';

    /** @throws MissingConfigurationException */
    public static function bookingStore(): LockProvider
    {
        return self::provider(self::BOOKING_STORE)
            ?? throw MissingConfigurationException::bookingLockStoreNotLockable(self::storeName(self::BOOKING_STORE));
    }

    /** @throws MissingConfigurationException */
    public static function bookingSeconds(): int
    {
        $seconds = self::integer('hub.booking.lock_seconds', 40);
        $timeout = self::integer('hub.timeout', 30);

        if ($seconds <= $timeout) {
            throw MissingConfigurationException::bookingLockShorterThanTimeout($seconds, $timeout);
        }

        return $seconds;
    }

    /** @throws MissingConfigurationException */
    public static function webhookStore(): LockProvider
    {
        return self::provider(self::WEBHOOK_STORE)
            ?? throw MissingConfigurationException::webhookLockStoreNotLockable(self::storeName(self::WEBHOOK_STORE));
    }

    public static function webhookSeconds(): int
    {
        return max(1, self::integer('hub.webhook.lock_seconds', 30));
    }

    public static function storeName(string $configKey): string
    {
        return self::configured($configKey) ?? self::defaultStoreName();
    }

    private static function provider(string $configKey): ?LockProvider
    {
        $store = Cache::store(self::configured($configKey))->getStore();

        return $store instanceof LockProvider ? $store : null;
    }

    private static function configured(string $configKey): ?string
    {
        $configured = Config::get($configKey);

        return is_string($configured) && $configured !== '' ? $configured : null;
    }

    private static function defaultStoreName(): string
    {
        $default = Config::get('cache.default');

        return is_string($default) ? $default : 'default';
    }

    private static function integer(string $configKey, int $fallback): int
    {
        $value = Config::get($configKey);

        return is_numeric($value) ? (int) $value : $fallback;
    }
}
