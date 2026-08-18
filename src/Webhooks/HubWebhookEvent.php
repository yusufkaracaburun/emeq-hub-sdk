<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Webhooks;

enum HubWebhookEvent: string
{
    case BANK_STATEMENT_CHANGED = 'accounting.bank_statement.changed';

    case CASH_STATEMENT_CHANGED = 'accounting.cash_statement.changed';

    case RELATION_CHANGED = 'accounting.relation.changed';

    case SALES_INVOICE_CHANGED = 'accounting.sales_invoice.changed';

    case PURCHASE_INVOICE_CHANGED = 'accounting.purchase_invoice.changed';

    case JOURNAL_ENTRY_CHANGED = 'accounting.journal_entry.changed';

    case DOCUMENT_CHANGED = 'accounting.document.changed';

    case LEDGER_ACCOUNT_CHANGED = 'accounting.ledger_account.changed';

    case DOCUMENT_SYNCED = 'accounting.document.synced';

    case PAYMENT_CHANGED = 'billing.payment.changed';

    case SUBSCRIPTION_CHANGED = 'billing.subscription.changed';

    case CONNECTION_REVOKED = 'connection.revoked';

    case UNMAPPED = 'unmapped';

    public static function fromWire(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::UNMAPPED) : self::UNMAPPED;
    }
}
