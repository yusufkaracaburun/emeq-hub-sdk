<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

test('publishes hub config migrations and webhook-client stub', function () {
    $config = config_path('hub.php');
    $webhookClient = config_path('webhook-client.php');

    File::delete($config);
    File::delete($webhookClient);

    $this->artisan('vendor:publish', ['--tag' => 'hub-config', '--force' => true])
        ->assertSuccessful();
    expect(File::exists($config))->toBeTrue();

    $this->artisan('vendor:publish', ['--tag' => 'hub-migrations', '--force' => true])
        ->assertSuccessful();

    $migrations = collect(File::files(database_path('migrations')))
        ->filter(fn ($file) => str_contains($file->getFilename(), 'create_webhook_calls_table'));
    expect($migrations)->not->toBeEmpty();

    $this->artisan('vendor:publish', ['--tag' => 'hub-webhook-client', '--force' => true])
        ->assertSuccessful();
    expect(File::exists($webhookClient))->toBeTrue()
        ->and(File::get($webhookClient))->toContain('SpatieWebhookClientConfig::make');
});

test('hub install command is registered', function () {
    $this->artisan('hub:install')
        ->expectsOutputToContain('Next steps:')
        ->assertSuccessful();
});
