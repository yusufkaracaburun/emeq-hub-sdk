<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

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
        ->and($migrations->filter(fn (string $name) => str_contains($name, 'create_hub_documents_table')))->not->toBeEmpty();
});

test('publishes the outcome copy so consumers can reword it', function () {
    expect(trans('hub::booking.error.provider_disabled'))
        ->toBe('The connection to the bookkeeping is switched off.');

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
