<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Testing;

use Emeq\HubSdk\Exceptions\HubException;
use Saloon\Http\Faking\MockResponse;

/**
 * Canonical mock responses for consumer test suites.
 *
 * Every payload here was captured from a live Hub against a connected provider
 * and then redacted — no invented shapes. The SDK's own tests read the same
 * files, so a consumer testing against `HubMock` tests against what the SDK
 * itself treats as the truth.
 *
 * ```php
 * MockClient::global(HubMock::accounting());
 * ```
 */
final class HubMock
{
    public static function capabilities(): MockResponse
    {
        return MockResponse::make(self::fixture('capabilities'), 200);
    }

    /**
     * Grouped by reference kind — `{gl: [...], vat: [...], journal: [...]}`.
     * Items carry no `kind` of their own, and `attrs` is `[]` when empty and an
     * object when filled.
     */
    public static function referenceData(): MockResponse
    {
        return MockResponse::make(self::fixture('reference-data'), 200);
    }

    /**
     * Wrapped: `{mapping: {vat_codes, journals, gl_accounts}}`. Sections hold
     * concept key → provider code, and `vat_codes` also carries composite keys
     * like `reverse_charge:21`.
     */
    public static function mapping(): MockResponse
    {
        return MockResponse::make(self::fixture('mapping'), 200);
    }

    /**
     * A document read is a thin projection of the provider's own record: dates,
     * party name and lines can all come back empty.
     */
    public static function documents(): MockResponse
    {
        return MockResponse::make(self::fixture('documents'), 200);
    }

    /**
     * Has a `next_cursor`, so `AccountingPage::hasMore()` is true.
     */
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

    /**
     * An empty page — `{data: [], next_cursor: null, has_more: false}`.
     */
    public static function bankStatements(): MockResponse
    {
        return MockResponse::make(self::fixture('bank-statements-empty'), 200);
    }

    /**
     * A booked document — `201`, not `200`.
     *
     * `external_ref` and `external_number` are the provider's own identifiers
     * for the booking; store them, they are how the document is found back in
     * the administration. A retry carrying the same `Idempotency-Key` returns a
     * byte-identical body, verified against a live Hub: the document is booked
     * once and the replay echoes the first answer.
     */
    public static function createDocument(): MockResponse
    {
        return MockResponse::make(self::fixture('create-document'), 201);
    }

    /**
     * A booked document where the relation ladder fell back to a name match
     * instead of the mirror or a KvK/VAT lookup.
     *
     * Not a live capture: Hub's ladder had not reached production yet when this
     * fixture was written. Code, message and context keys are copied from the
     * Hub's own emitter (`ExactRelationResolver`, `BookingWarnings`) — replace
     * with a real capture once the ladder is deployed.
     */
    public static function createDocumentWithWarnings(): MockResponse
    {
        return MockResponse::make(self::fixture('create-document-with-warnings'), 201);
    }

    /**
     * Reports how many records the provider pulled — the shape says nothing
     * about which entities were touched.
     */
    public static function sync(): MockResponse
    {
        return MockResponse::make(self::fixture('sync'), 200);
    }

    /**
     * Validation answers 200 either way; `valid` carries the verdict.
     *
     * The clean payload still holds one `info` finding — findings are not the
     * same thing as failure. The failing payload mixes `error` and `warning`.
     */
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

    /**
     * What Hub answers when a collection read omits a required filter — the
     * `documents` endpoint rejects a call without `type`.
     */
    public static function invalidQuery(): MockResponse
    {
        return MockResponse::make(self::fixture('error-invalid-query'), 400);
    }

    /**
     * URL-keyed map for `MockClient::global()`, covering every endpoint whose
     * URL identifies it on its own.
     *
     * Keys are URL patterns, not `Http\Request\*` classes — that namespace is
     * package-internal. Saloon walks the map in order and takes the first
     * pattern that matches, so the validate path is listed before the documents
     * one.
     *
     * `createDocument()` is deliberately absent. Saloon matches on the URL
     * alone, and booking a document posts to the same `/accounting/documents`
     * the list read gets — one map cannot answer both. This one answers the
     * read; a test that books maps that same wildcard pattern to
     * `HubMock::createDocument()` itself.
     *
     * @return array<string, MockResponse>
     */
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

    /**
     * The raw payload behind a factory, for assertions or for building a
     * variant.
     *
     * @return array<string, mixed>
     */
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
