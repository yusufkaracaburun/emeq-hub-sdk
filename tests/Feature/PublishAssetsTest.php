<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

test('publishes hub config and migrations', function () {
    $config = config_path('hub.php');

    File::delete($config);

    $this->artisan('vendor:publish', ['--tag' => 'hub-config', '--force' => true])
        ->assertSuccessful();
    expect(File::exists($config))->toBeTrue()
        ->and(File::get($config))->toContain("'webhook'")
        ->and(File::get($config))->toContain('EMEQ_HUB_WEBHOOK_SECRET');

    $this->artisan('vendor:publish', ['--tag' => 'hub-migrations', '--force' => true])
        ->assertSuccessful();

    $migrations = collect(File::files(database_path('migrations')))
        ->map(fn ($file) => $file->getFilename());

    expect($migrations->filter(fn (string $name) => str_contains($name, 'create_webhook_calls_table')))->not->toBeEmpty()
        ->and($migrations->filter(fn (string $name) => str_contains($name, 'create_hub_documents_table')))->not->toBeEmpty()
        ->and($migrations->filter(fn (string $name) => str_contains($name, 'add_trace_to_hub_documents_table')))->not->toBeEmpty();
});

/**
 * A first-time consumer publishes both stubs, so the second has to be a no-op
 * against the table the first just created.
 */
test('the trace migration skips a ledger that already has the columns', function () {
    $create = require __DIR__.'/../../database/migrations/create_hub_documents_table.php.stub';
    $create->up();

    $trace = require __DIR__.'/../../database/migrations/add_trace_to_hub_documents_table.php.stub';
    $trace->up();

    expect(Schema::hasColumns('hub_documents', ['request_id', 'category']))->toBeTrue();
});

/**
 * And it does its actual job on a ledger created before the columns existed.
 */
test('the trace migration adds the columns to an older ledger', function () {
    $create = require __DIR__.'/../../database/migrations/create_hub_documents_table.php.stub';
    $create->up();

    Schema::table('hub_documents', function (Blueprint $table): void {
        $table->dropColumn(['request_id', 'category']);
    });
    expect(Schema::hasColumn('hub_documents', 'request_id'))->toBeFalse();

    $trace = require __DIR__.'/../../database/migrations/add_trace_to_hub_documents_table.php.stub';
    $trace->up();

    expect(Schema::hasColumns('hub_documents', ['request_id', 'category']))->toBeTrue();
});

test('publishes the outcome copy so consumers can reword it', function () {
    expect(trans('hub::booking.temporarily_unavailable'))
        ->toBe('The bookkeeping is briefly unreachable. Nothing was booked; try again shortly.');

    $this->artisan('vendor:publish', ['--tag' => 'hub-translations', '--force' => true])
        ->assertSuccessful();

    expect(File::exists(lang_path('vendor/hub/nl/booking.php')))->toBeTrue()
        ->and(File::get(lang_path('vendor/hub/nl/booking.php')))->toContain('De boekhouding');
});

test('hub install command is registered', function () {
    $this->artisan('hub:install')
        ->expectsOutputToContain('Next steps:')
        ->expectsOutputToContain('hub.webhook.job')
        ->assertSuccessful();
});
