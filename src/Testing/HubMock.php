<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Testing;

use Emeq\HubSdk\Exceptions\HubException;
use Saloon\Http\Faking\MockResponse;

final class HubMock
{
    public static function capabilities(): MockResponse
    {
        return MockResponse::make(self::fixture('capabilities'), 200);
    }

    public static function referenceData(): MockResponse
    {
        return MockResponse::make(self::fixture('reference-data'), 200);
    }

    public static function mapping(): MockResponse
    {
        return MockResponse::make(self::fixture('mapping'), 200);
    }

    public static function documents(): MockResponse
    {
        return MockResponse::make(self::fixture('documents'), 200);
    }

    public static function ledgerAccounts(): MockResponse
    {
        return MockResponse::make(self::fixture('ledger-accounts'), 200);
    }

    public static function taxCodes(): MockResponse
    {
        return MockResponse::make(self::fixture('tax-codes'), 200);
    }

    public static function customers(): MockResponse
    {
        return MockResponse::make(self::fixture('customers'), 200);
    }

    public static function suppliers(): MockResponse
    {
        return MockResponse::make(self::fixture('suppliers'), 200);
    }

    public static function bankStatements(): MockResponse
    {
        return MockResponse::make(self::fixture('bank-statements-empty'), 200);
    }

    public static function createDocument(): MockResponse
    {
        return MockResponse::make(self::fixture('create-document'), 201);
    }

    public static function createDocumentWithWarnings(): MockResponse
    {
        return MockResponse::make(self::fixture('create-document-with-warnings'), 201);
    }

    public static function sync(): MockResponse
    {
        return MockResponse::make(self::fixture('sync'), 200);
    }

    public static function validateDocument(bool $valid = true): MockResponse
    {
        return MockResponse::make(self::fixture($valid ? 'validate-clean' : 'validate-findings'), 200);
    }

    public static function unauthenticated(): MockResponse
    {
        return MockResponse::make(self::fixture('error-unauthenticated'), 401);
    }

    public static function notFound(): MockResponse
    {
        return MockResponse::make(self::fixture('error-not-found'), 404);
    }

    public static function invalidQuery(): MockResponse
    {
        return MockResponse::make(self::fixture('error-invalid-query'), 400);
    }

    /** @return array<string, MockResponse> */
    public static function accounting(): array
    {
        return [
            '*/v1/accounting/documents/validate' => self::validateDocument(),
            '*/v1/accounting/capabilities' => self::capabilities(),
            '*/v1/accounting/reference-data' => self::referenceData(),
            '*/v1/accounting/mapping' => self::mapping(),
            '*/v1/accounting/documents*' => self::documents(),
            '*/v1/accounting/ledger-accounts*' => self::ledgerAccounts(),
            '*/v1/accounting/tax-codes*' => self::taxCodes(),
            '*/v1/accounting/customers*' => self::customers(),
            '*/v1/accounting/suppliers*' => self::suppliers(),
            '*/v1/accounting/bank-statements*' => self::bankStatements(),
            '*/v1/accounting/sync' => self::sync(),
        ];
    }

    /** @return array<string, mixed> */
    public static function fixture(string $name): array
    {
        $path = __DIR__.'/fixtures/'.$name.'.json';

        if (! is_file($path)) {
            throw new HubException(
                "No Hub fixture named [{$name}].",
                error: 'unknown_fixture',
                category: 'CONFIGURATION_ERROR',
            );
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new HubException(
                "Hub fixture [{$name}] is not a JSON object.",
                error: 'invalid_fixture',
                category: 'CONFIGURATION_ERROR',
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
