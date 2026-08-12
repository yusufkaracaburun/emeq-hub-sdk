<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Resources;

use Emeq\HubSdk\Http\Request\Accounting\CreateDocumentRequest;
use Emeq\HubSdk\Http\Request\Accounting\GetAccountingRequest;
use Emeq\HubSdk\Http\Request\Accounting\PutMappingRequest;
use Emeq\HubSdk\Http\Request\Accounting\SyncAccountingRequest;
use Emeq\HubSdk\Http\Request\Accounting\ValidateDocumentRequest;

/**
 * Canonical accounting surface — Hub picks the partner adapter.
 */
class Accounting extends Resource
{
    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function createDocument(array $document, string $idempotencyKey, ?string $accountId = null): array
    {
        $response = $this->connector->send(new CreateDocumentRequest(
            document: $document,
            accountId: $this->resolveAccountId($accountId),
            idempotencyKey: $idempotencyKey,
        ));

        return $this->json($response->json());
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function validateDocument(array $document, ?string $accountId = null): array
    {
        $response = $this->connector->send(new ValidateDocumentRequest(
            document: $document,
            accountId: $this->resolveAccountId($accountId),
        ));

        return $this->json($response->json());
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public function documents(array $query = [], ?string $accountId = null): array
    {
        return $this->get('/accounting/documents', $query, $accountId);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public function bankStatements(array $query = [], ?string $accountId = null): array
    {
        return $this->get('/accounting/bank-statements', $query, $accountId);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public function ledgerAccounts(array $query = [], ?string $accountId = null): array
    {
        return $this->get('/accounting/ledger-accounts', $query, $accountId);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public function taxCodes(array $query = [], ?string $accountId = null): array
    {
        return $this->get('/accounting/tax-codes', $query, $accountId);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public function customers(array $query = [], ?string $accountId = null): array
    {
        return $this->get('/accounting/customers', $query, $accountId);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public function suppliers(array $query = [], ?string $accountId = null): array
    {
        return $this->get('/accounting/suppliers', $query, $accountId);
    }

    /**
     * @return array<string, mixed>
     */
    public function capabilities(?string $accountId = null): array
    {
        return $this->json($this->get('/accounting/capabilities', [], $accountId));
    }

    /**
     * @return array<string, mixed>
     */
    public function referenceData(?string $accountId = null): array
    {
        return $this->json($this->get('/accounting/reference-data', [], $accountId));
    }

    /**
     * @return array<string, mixed>
     */
    public function mapping(?string $accountId = null): array
    {
        return $this->json($this->get('/accounting/mapping', [], $accountId));
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @return array<string, mixed>
     */
    public function updateMapping(array $mapping, ?string $accountId = null): array
    {
        $response = $this->connector->send(new PutMappingRequest(
            mapping: $mapping,
            accountId: $this->resolveAccountId($accountId),
        ));

        return $this->json($response->json());
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function sync(array $body = [], ?string $accountId = null): array
    {
        $response = $this->connector->send(new SyncAccountingRequest(
            accountId: $this->resolveAccountId($accountId),
            body: $body,
        ));

        return $this->json($response->json());
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private function get(string $path, array $query, ?string $accountId): array
    {
        $response = $this->connector->send(new GetAccountingRequest(
            path: $path,
            accountId: $this->resolveAccountId($accountId),
            queryParameters: $query,
        ));

        $payload = $response->json();

        if (! is_array($payload)) {
            return $this->json($payload);
        }

        return $payload;
    }
}
