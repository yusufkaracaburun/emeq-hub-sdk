<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Tests;

use Emeq\HubSdk\Backlog\Contracts\ProvidesBacklogSources;
use Emeq\HubSdk\Booking\AccountingChangeRecorder;
use Emeq\HubSdk\Booking\HubDocument;
use Emeq\HubSdk\Contracts\ResolvesAccountId;
use Emeq\HubSdk\Tests\Doubles\FakeBacklogSources;
use Emeq\HubSdk\Tests\Doubles\FixedAccountId;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class BookingTestCase extends TestCase
{
    protected const ACCOUNT_ID = 'tenant-1';

    /** @var list<string> */
    protected array $temporaryDatabases = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->bind(ResolvesAccountId::class, fn (): ResolvesAccountId => new FixedAccountId(self::ACCOUNT_ID));
        $app->bind(ProvidesBacklogSources::class, FakeBacklogSources::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $migration = require __DIR__.'/../database/migrations/create_hub_documents_table.php.stub';
        $migration->up();

        HubDocument::forgetTraceSupport();
        AccountingChangeRecorder::forgetChangeSupport();

        $this->createDocumentsTable();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryDatabases as $file) {
            @unlink($file);
        }

        $this->temporaryDatabases = [];

        parent::tearDown();
    }

    protected function createDocumentsTable(?string $connection = null): void
    {
        Schema::connection($connection)->create(FakeBacklogSources::TABLE, function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('module');
            $table->string('uuid');
            $table->string('number');
            $table->date('date');
            $table->decimal('amount', 12, 2);
            $table->string('party')->nullable();
            $table->string('direction');
            $table->string('head')->nullable();
            $table->string('document_status')->nullable();
        });
    }

    protected function temporaryDatabase(): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'hub-ledger-');

        $this->temporaryDatabases[] = $file;

        return $file;
    }

    protected function useLedgerDatabase(string $file, bool $withTrace = true, bool $withChange = true): void
    {
        config()->set('database.connections.tenant', [
            'driver' => 'sqlite',
            'database' => $file,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        config()->set('hub.booking.connection', 'tenant');

        DB::purge('tenant');

        Schema::connection('tenant')->dropIfExists('hub_documents');
        Schema::connection('tenant')->create('hub_documents', function (Blueprint $table) use ($withTrace, $withChange): void {
            $table->bigIncrements('id');
            $table->string('account_id');
            $table->string('type');
            $table->string('external_id');
            $table->string('party_external_id')->nullable();
            $table->string('status');
            $table->string('external_ref')->nullable();
            $table->string('external_number')->nullable();
            $table->string('error')->nullable();
            $table->text('error_message')->nullable();

            if ($withTrace) {
                $table->string('request_id')->nullable();
                $table->string('category')->nullable();
            }

            $table->timestamp('booked_at')->nullable();

            if ($withChange) {
                $table->timestamp('accounting_changed_at')->nullable();
                $table->string('accounting_change_action', 32)->nullable();
                $table->string('accounting_change_event_id', 64)->nullable();
            }

            $table->timestamps();

            $table->unique(['account_id', 'type', 'external_id'], 'hub_documents_identity_unique');
        });
    }
}
