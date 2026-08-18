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
use Illuminate\Support\Facades\Schema;

abstract class BookingTestCase extends TestCase
{
    protected const ACCOUNT_ID = 'tenant-1';

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

    protected function createDocumentsTable(): void
    {
        Schema::create(FakeBacklogSources::TABLE, function (Blueprint $table): void {
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
}
