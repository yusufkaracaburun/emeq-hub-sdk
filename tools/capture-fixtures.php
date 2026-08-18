<?php

/**
 * Capture real Hub responses so the SDK's fixtures stop being invented.
 *
 * Development tool — never shipped (see .gitattributes). Run it against a Hub
 * account that has a live accounting connection:
 *
 *   EMEQ_HUB_BASE=https://hub.emeq.nl \
 *   EMEQ_HUB_PAT=... \
 *   EMEQ_HUB_ACCOUNT=tenant-1 \
 *   php tools/capture-fixtures.php
 *
 * Writes one JSON file per case to --out (default storage/hub-capture, which is
 * gitignored). Nothing is committed automatically: the captures hold real
 * company names, IBANs and ledger codes, so they get redacted by hand before
 * they become fixtures.
 *
 * Reads and validate calls are side-effect free and run by default. Booking a
 * document and triggering a provider re-sync are real writes and only run with
 * --allow-write.
 */
declare(strict_types=1);

const READ_LIMIT = 2;

$options = getopt('', ['out::', 'allow-write', 'help']);

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/capture-fixtures.php [--out=DIR] [--allow-write]\n");
    exit(0);
}

$base = rtrim((string) getenv('EMEQ_HUB_BASE'), '/');
$pat = (string) getenv('EMEQ_HUB_PAT');
$account = (string) getenv('EMEQ_HUB_ACCOUNT');
$outDir = (string) ($options['out'] ?? __DIR__.'/../storage/hub-capture');
$allowWrite = isset($options['allow-write']);

foreach (['EMEQ_HUB_BASE' => $base, 'EMEQ_HUB_PAT' => $pat, 'EMEQ_HUB_ACCOUNT' => $account] as $name => $value) {
    if ($value === '') {
        fwrite(STDERR, "Missing {$name}.\n");
        exit(1);
    }
}

if (! is_dir($outDir) && ! mkdir($outDir, 0755, true) && ! is_dir($outDir)) {
    fwrite(STDERR, "Cannot create {$outDir}.\n");
    exit(1);
}

/**
 * Minimal document that satisfies StoreDocumentRequest: type, external_id,
 * issue_date, party, lines. `external_id` doubles as the idempotency key.
 *
 * The party defaults to a made-up company, which is what you want for the
 * validation captures — it produces the "unknown relation" and "bad VAT number"
 * findings. A write run needs a party the administration already knows, or the
 * booking is refused: point EMEQ_CAPTURE_PARTY_* at a relation from
 * `GET /accounting/customers`.
 *
 * @return array<string, mixed>
 */
function sampleDocument(string $externalId, string $issueDate): array
{
    return [
        'type' => 'sales_invoice',
        'external_id' => $externalId,
        'number' => 'F-2026-0001',
        'reference' => 'capture run',
        'currency' => 'EUR',
        'prices_include_tax' => false,
        'issue_date' => $issueDate,
        'party' => [
            'role' => 'debtor',
            'kind' => 'company',
            'name' => getenv('EMEQ_CAPTURE_PARTY_NAME') ?: 'Fixture Capture B.V.',
            'vat_number' => getenv('EMEQ_CAPTURE_PARTY_VAT') ?: 'NL000000000B01',
            'external_id' => getenv('EMEQ_CAPTURE_PARTY_ID') ?: 'party-'.$externalId,
        ],
        'lines' => [
            [
                'description' => 'Capture line',
                'amount' => 100.0,
                'tax_rate' => 21,
                'quantity' => 1,
                'unit_price' => 100.0,
                'category' => 'omzet',
            ],
        ],
    ];
}

/**
 * @param  array<string, mixed>|null  $body
 * @return array{status: int, headers: array<string, string>, body: mixed, raw: string}
 */
function call(string $base, string $token, ?string $account, string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
{
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer '.$token,
    ];

    if ($account !== null) {
        $headers[] = 'X-Account-Id: '.$account;
    }

    if ($idempotencyKey !== null) {
        $headers[] = 'Idempotency-Key: '.$idempotencyKey;
    }

    $responseHeaders = [];

    $handle = curl_init($base.'/v1'.$path);
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
        CURLOPT_HEADERFUNCTION => function ($_, string $line) use (&$responseHeaders): int {
            $parts = explode(':', $line, 2);

            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return strlen($line);
        },
    ]);

    $raw = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $error = curl_error($handle);
    curl_close($handle);

    if ($raw === false) {
        return ['status' => 0, 'headers' => [], 'body' => ['curl_error' => $error], 'raw' => ''];
    }

    return [
        'status' => $status,
        'headers' => array_intersect_key($responseHeaders, array_flip(['content-type', 'x-request-id', 'retry-after'])),
        'body' => json_decode($raw, true),
        'raw' => $raw,
    ];
}

$reads = [
    'capabilities' => '/accounting/capabilities',
    'reference-data' => '/accounting/reference-data',
    'mapping' => '/accounting/mapping',
    'documents' => '/accounting/documents?limit='.READ_LIMIT,
    'bank-statements' => '/accounting/bank-statements?limit='.READ_LIMIT,
    'ledger-accounts' => '/accounting/ledger-accounts?limit='.READ_LIMIT,
    'tax-codes' => '/accounting/tax-codes?limit='.READ_LIMIT,
    'customers' => '/accounting/customers?limit='.READ_LIMIT,
    'suppliers' => '/accounting/suppliers?limit='.READ_LIMIT,
];

/** @var list<array{case: string, method: string, path: string, body: array<string, mixed>|null, token?: string, account?: string|null, idempotency?: string}> $cases */
$cases = [];

foreach ($reads as $name => $path) {
    $cases[] = ['case' => $name, 'method' => 'GET', 'path' => $path, 'body' => null];
}

$cases[] = [
    'case' => 'validate-valid',
    'method' => 'POST',
    'path' => '/accounting/documents/validate',
    'body' => sampleDocument('capture-valid-1', '2026-08-13'),
];

// Same document with a full ISO timestamp. api.json says issue_date is
// `format: date-time` while the TypeScript mirror says `YYYY-MM-DD`; running
// both settles which one Hub actually accepts.
$cases[] = [
    'case' => 'validate-valid-datetime',
    'method' => 'POST',
    'path' => '/accounting/documents/validate',
    'body' => sampleDocument('capture-valid-2', '2026-08-13T09:00:00+02:00'),
];

// Passes the request rules, fails the mapping/business checks — this is what
// the `findings` array looks like when it is not empty.
$unmapped = sampleDocument('capture-unmapped-1', '2026-08-13');
$unmapped['lines'][0]['tax_rate'] = 13.5;
$unmapped['lines'][0]['category'] = 'category-that-is-not-mapped';
$cases[] = [
    'case' => 'validate-unmapped',
    'method' => 'POST',
    'path' => '/accounting/documents/validate',
    'body' => $unmapped,
];

// Malformed body — captures the 422 error envelope.
$cases[] = [
    'case' => 'validate-malformed',
    'method' => 'POST',
    'path' => '/accounting/documents/validate',
    'body' => [],
];

// Error envelopes the SDK maps to exceptions.
$cases[] = [
    'case' => 'error-401',
    'method' => 'GET',
    'path' => '/accounting/capabilities',
    'body' => null,
    'token' => 'not-a-real-token',
];

$cases[] = [
    'case' => 'error-404',
    'method' => 'GET',
    'path' => '/connections/con_does-not-exist',
    'body' => null,
    'account' => null,
];

if ($allowWrite) {
    $externalId = 'capture-'.date('Ymd-His');

    $cases[] = [
        'case' => 'create-document',
        'method' => 'POST',
        'path' => '/accounting/documents',
        'body' => sampleDocument($externalId, '2026-08-13'),
        'idempotency' => $externalId,
    ];

    // Same key, same body — captures what a retry after a timeout gets back.
    $cases[] = [
        'case' => 'create-document-replay',
        'method' => 'POST',
        'path' => '/accounting/documents',
        'body' => sampleDocument($externalId, '2026-08-13'),
        'idempotency' => $externalId,
    ];

    $cases[] = ['case' => 'sync', 'method' => 'POST', 'path' => '/accounting/sync', 'body' => []];
}

fwrite(STDOUT, 'Capturing '.count($cases)." cases to {$outDir}\n");

if (! $allowWrite) {
    fwrite(STDOUT, "Read-only run. Pass --allow-write to also book a document and trigger a re-sync.\n");
}

foreach ($cases as $case) {
    $result = call(
        base: $base,
        token: $case['token'] ?? $pat,
        account: array_key_exists('account', $case) ? $case['account'] : $account,
        method: $case['method'],
        path: $case['path'],
        body: $case['body'],
        idempotencyKey: $case['idempotency'] ?? null,
    );

    file_put_contents(
        $outDir.'/'.$case['case'].'.json',
        json_encode([
            'case' => $case['case'],
            'method' => $case['method'],
            'path' => $case['path'],
            'request_body' => $case['body'],
            'idempotency_key' => $case['idempotency'] ?? null,
            'status' => $result['status'],
            'response_headers' => $result['headers'],
            'response_body' => $result['body'],
            'response_raw' => $result['body'] === null ? $result['raw'] : null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n"
    );

    fwrite(STDOUT, sprintf("  %-26s %s %d\n", $case['case'], $case['method'], $result['status']));
}

fwrite(STDOUT, "\nDone. Captures contain real business data — do not commit them as-is.\n");
