<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Console;

use Emeq\HubSdk\Booking\HubDocument;
use Emeq\HubSdk\Contracts\ResolvesAccountId;
use Emeq\HubSdk\Contracts\ResolvesWebhookAccount;
use Emeq\HubSdk\Exceptions\HubException;
use Emeq\HubSdk\Exceptions\MissingConfigurationException;
use Emeq\HubSdk\Hub;
use Emeq\HubSdk\Support\HubLocks;
use Emeq\HubSdk\Support\HubRouteMiddleware;
use Emeq\HubSdk\Support\OAuthReturnUrl;
use Emeq\HubSdk\Support\SdkIdentity;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\WebhookClient\Models\WebhookCall;
use Throwable;

class HubDoctorCommand extends Command
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    protected $signature = 'hub:doctor {--ping : Call Hub with the configured PAT}';

    protected $description = 'Check the emeq Hub SDK configuration this application boots with';

    /** @var list<array{check: string, status: string, detail: string}> */
    private array $findings = [];

    public function handle(): int
    {
        $this->checkHttp();
        $this->checkBindings();
        $this->checkLedger();
        $this->checkBookingLocks();
        $this->checkWebhooks();
        $this->checkWebhookLocks();
        $this->checkRoutes();

        if ($this->option('ping') === true) {
            $this->ping();
        }

        $this->report();

        return $this->countOf(self::FAIL) === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function checkHttp(): void
    {
        $this->record('sdk', self::OK, SdkIdentity::userAgent());

        $base = $this->string('hub.base_url');

        match (true) {
            $base === '' => $this->record('hub.base_url', self::FAIL, 'Empty. Set EMEQ_HUB_BASE to the Hub origin, e.g. https://hub.emeq.nl'),
            preg_match('#^https?://#i', $base) !== 1 => $this->record('hub.base_url', self::FAIL, "[{$base}] is not an absolute http(s) URL."),
            str_starts_with(mb_strtolower($base), 'http://') => $this->record('hub.base_url', self::WARN, "[{$base}] is plain HTTP — the PAT travels unencrypted."),
            default => $this->record('hub.base_url', self::OK, mb_rtrim($base, '/').'/v1'),
        };

        $pat = $this->string('hub.pat');

        $this->record(
            'hub.pat',
            $pat === '' ? self::FAIL : self::OK,
            $pat === '' ? 'Empty. Set EMEQ_HUB_PAT, server-side only.' : sprintf('Set, %d characters.', mb_strlen($pat)),
        );

        $timeout = $this->integer('hub.timeout', 30);

        $this->record(
            'hub.timeout',
            $timeout > 0 ? self::OK : self::FAIL,
            $timeout > 0 ? "{$timeout}s" : "{$timeout}s is not a usable request timeout.",
        );
    }

    private function checkBindings(): void
    {
        $account = $this->laravel->bound(ResolvesAccountId::class);

        $this->record(
            'ResolvesAccountId',
            $account ? self::OK : self::FAIL,
            $account ? 'Bound.' : 'Not bound. Every account-scoped Hub call needs it.',
        );

        $webhookAccount = $this->laravel->bound(ResolvesWebhookAccount::class);

        $this->record(
            'ResolvesWebhookAccount',
            $webhookAccount ? self::OK : self::WARN,
            $webhookAccount ? 'Bound.' : 'Not bound. Inbound deliveries cannot resolve their account without it.',
        );
    }

    private function checkLedger(): void
    {
        $model = new HubDocument;
        $table = $model->getTable();
        $name = $model->getConnectionName();
        $label = $name ?? 'default';

        try {
            $database = DB::connection($name)->getDatabaseName();
            $schema = Schema::connection($name);
            $exists = $schema->hasTable($table);
        } catch (Throwable $e) {
            $this->record($table, self::FAIL, "Connection [{$label}] is unreachable: ".$e->getMessage());

            return;
        }

        if (! $exists) {
            $this->record($table, self::WARN, "Missing on [{$label}] {$database}. Booking fails until the migration runs there; ignore when this app never books.");

            return;
        }

        $this->record($table, self::OK, "[{$label}] {$database}");

        $trace = $schema->hasColumns($table, HubDocument::TRACE_COLUMNS);

        $this->record(
            $table.' trace',
            $trace ? self::OK : self::WARN,
            $trace
                ? implode(', ', HubDocument::TRACE_COLUMNS)
                : 'Missing. A failed booking will not name the Hub log line that explains it. Run add_trace_to_hub_documents_table.',
        );

        $change = $schema->hasColumns($table, HubDocument::CHANGE_COLUMNS);

        $this->record(
            $table.' accounting change',
            $change ? self::OK : self::WARN,
            $change
                ? implode(', ', HubDocument::CHANGE_COLUMNS)
                : 'Missing. The backlog\'s accounting_changed filter stays empty.',
        );
    }

    private function checkBookingLocks(): void
    {
        $this->attempt('booking lock store', static function (): string {
            $name = HubLocks::storeName(HubLocks::BOOKING_STORE);

            self::proveLockable(
                HubLocks::bookingStore(),
                'booking',
                static fn (): MissingConfigurationException => MissingConfigurationException::bookingLockStoreNotLockable($name),
            );

            return $name;
        });

        $this->attempt('hub.booking.lock_seconds', static fn (): string => HubLocks::bookingSeconds().'s');
    }

    private function checkWebhooks(): void
    {
        $enabled = $this->boolean('hub.webhook.enabled', true);
        $name = $this->string('hub.webhook.name') ?: 'emeq-hub';

        $this->record(
            'hub.webhook.enabled',
            $enabled ? self::OK : self::WARN,
            $enabled ? 'Open.' : 'Closed — deliveries are answered as if no endpoint exists.',
        );

        $secret = $this->string('hub.webhook.secret');

        $this->record(
            'hub.webhook.secret',
            $secret !== '' ? self::OK : ($enabled ? self::FAIL : self::WARN),
            $secret !== ''
                ? sprintf('Set, %d characters.', mb_strlen($secret))
                : 'Empty. Every signed delivery is rejected until it matches Hub\'s webhook_callback_secret.',
        );

        $entry = $this->webhookClientConfig($name);

        match (true) {
            $entry === null => $this->record('webhook-client config', self::FAIL, "No entry named [{$name}]. The service provider should have upserted it."),
            ! is_string($entry['process_webhook_job'] ?? null) || $entry['process_webhook_job'] === '' => $this->record('webhook-client config', self::FAIL, "Entry [{$name}] has no process_webhook_job."),
            default => $this->record('webhook-client config', self::OK, (string) $entry['process_webhook_job']),
        };

        $table = (new WebhookCall)->getTable();

        try {
            $exists = Schema::hasTable($table);
            $database = DB::connection()->getDatabaseName();
        } catch (Throwable $e) {
            $this->record($table, self::FAIL, 'Default connection is unreachable: '.$e->getMessage());

            return;
        }

        $this->record(
            $table,
            $exists ? self::OK : ($enabled ? self::FAIL : self::WARN),
            $exists
                ? "{$database} (default connection — a multi-DB app must also carry it on the connection ResolvesWebhookAccount binds)"
                : "Missing on the default connection {$database}. A delivery against a missing table 500s and Hub retries it for hours.",
        );

    }

    private function checkWebhookLocks(): void
    {
        $this->attempt('webhook lock store', static function (): string {
            $name = HubLocks::storeName(HubLocks::WEBHOOK_STORE);

            self::proveLockable(
                HubLocks::webhookStore(),
                'webhook',
                static fn (): MissingConfigurationException => MissingConfigurationException::webhookLockStoreNotLockable($name),
            );

            return $name;
        });

        $this->record('hub.webhook.lock_seconds', self::OK, HubLocks::webhookSeconds().'s');
    }

    private function checkRoutes(): void
    {
        if (! $this->boolean('hub.routes.enabled', false)) {
            $this->record('hub.routes', self::OK, 'Off — this app exposes no BFF routes.');
        } else {
            $this->attempt('hub.routes.middleware', static fn (): string => implode(', ', HubRouteMiddleware::validated()));
        }

        $this->attempt('hub.oauth.return_path', function (): string {
            $url = OAuthReturnUrl::fromConfigPath('https://your-app.test', $this->string('hub.oauth.return_path'));

            return $url ?? 'Empty — Hub falls back to the Origin of the init call.';
        });
    }

    private function ping(): void
    {
        if (! $this->laravel->bound(ResolvesAccountId::class)) {
            $this->record('hub ping', self::WARN, 'Skipped: bind ResolvesAccountId first.');

            return;
        }

        try {
            $integrations = $this->laravel->make(Hub::class)->integrations()->list();

            $this->record('hub ping', self::OK, sprintf('%d integration(s) offered.', count($integrations)));
        } catch (HubException $e) {
            $this->record('hub ping', self::FAIL, sprintf(
                '%s [%s]%s',
                $e->getMessage(),
                $e->error,
                $e->requestId === null ? '' : ' request_id '.$e->requestId,
            ));
        } catch (Throwable $e) {
            $this->record('hub ping', self::FAIL, $e->getMessage());
        }
    }

    /** @param  callable(): MissingConfigurationException  $unlockable */
    private static function proveLockable(LockProvider $store, string $purpose, callable $unlockable): void
    {
        try {
            $lock = $store->lock('hub-doctor:'.$purpose, 1);

            if ($lock->get()) {
                $lock->release();
            }
        } catch (Throwable) {
            throw $unlockable();
        }
    }

    /** @param  callable(): string  $check */
    private function attempt(string $name, callable $check): void
    {
        try {
            $this->record($name, self::OK, $check());
        } catch (Throwable $e) {
            $this->record($name, self::FAIL, $e->getMessage());
        }
    }

    /** @return array<string, mixed>|null */
    private function webhookClientConfig(string $name): ?array
    {
        $configs = Config::get('webhook-client.configs');

        if (! is_array($configs)) {
            return null;
        }

        foreach ($configs as $entry) {
            if (is_array($entry) && ($entry['name'] ?? null) === $name) {
                /** @var array<string, mixed> $entry */
                return $entry;
            }
        }

        return null;
    }

    private function report(): void
    {
        $this->table(['Check', 'Status', 'Detail'], array_map(
            static fn (array $finding): array => [
                $finding['check'],
                match ($finding['status']) {
                    self::OK => '<fg=green>ok</>',
                    self::WARN => '<fg=yellow>warn</>',
                    default => '<fg=red>fail</>',
                },
                $finding['detail'],
            ],
            $this->findings,
        ));

        $failures = $this->countOf(self::FAIL);
        $warnings = $this->countOf(self::WARN);

        if ($failures > 0) {
            $this->error(sprintf('%d check(s) failed, %d warning(s).', $failures, $warnings));

            return;
        }

        if ($warnings > 0) {
            $this->warn(sprintf('No failures, %d warning(s).', $warnings));

            return;
        }

        $this->info('All checks passed.');
    }

    private function record(string $check, string $status, string $detail): void
    {
        $this->findings[] = ['check' => $check, 'status' => $status, 'detail' => $detail];
    }

    private function countOf(string $status): int
    {
        return count(array_filter($this->findings, static fn (array $finding): bool => $finding['status'] === $status));
    }

    private function string(string $key): string
    {
        $value = Config::get($key);

        return is_string($value) ? $value : '';
    }

    private function integer(string $key, int $fallback): int
    {
        $value = Config::get($key);

        return is_numeric($value) ? (int) $value : $fallback;
    }

    private function boolean(string $key, bool $fallback): bool
    {
        $value = Config::get($key);

        return is_bool($value) ? $value : $fallback;
    }
}
