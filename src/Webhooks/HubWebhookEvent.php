<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Webhooks;

/**
 * Canonical Hub → consumer webhook event names.
 *
 * Keep in sync with Hub `App\Integrations\Webhooks\CanonicalEvent`.
 */
final class HubWebhookEvent
{
    public const BANK_STATEMENT_CHANGED = 'accounting.bank_statement.changed';

    public const CASH_STATEMENT_CHANGED = 'accounting.cash_statement.changed';

    public const RELATION_CHANGED = 'accounting.relation.changed';

    public const SALES_INVOICE_CHANGED = 'accounting.sales_invoice.changed';

    public const DOCUMENT_SYNCED = 'accounting.document.synced';

    public const PAYMENT_CHANGED = 'billing.payment.changed';

    public const SUBSCRIPTION_CHANGED = 'billing.subscription.changed';

    public const CONNECTION_REVOKED = 'connection.revoked';

    public const UNMAPPED = 'unmapped';
}
