<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Webhooks;

/**
 * Canonical Hub → consumer webhook event names.
 *
 * Keep in sync with Hub `App\Integrations\Webhooks\CanonicalEvent`.
 *
 * Case names deliberately keep their pre-0.7 spelling: consumers comparing
 * `$envelope->event === HubWebhookEvent::CONNECTION_REVOKED` keep working, the
 * expression is simply type-safe now. An event Hub adds later decodes to
 * {@see self::UNMAPPED} rather than throwing, so new partners still need no
 * SDK release.
 */
enum HubWebhookEvent: string
{
    case BANK_STATEMENT_CHANGED = 'accounting.bank_statement.changed';

    case CASH_STATEMENT_CHANGED = 'accounting.cash_statement.changed';

    case RELATION_CHANGED = 'accounting.relation.changed';

    case SALES_INVOICE_CHANGED = 'accounting.sales_invoice.changed';

    case DOCUMENT_SYNCED = 'accounting.document.synced';

    case PAYMENT_CHANGED = 'billing.payment.changed';

    case SUBSCRIPTION_CHANGED = 'billing.subscription.changed';

    case CONNECTION_REVOKED = 'connection.revoked';

    case UNMAPPED = 'unmapped';

    /**
     * Never throws: an unknown wire value is an event this SDK release does not
     * know about yet, not a broken payload.
     */
    public static function fromWire(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::UNMAPPED) : self::UNMAPPED;
    }
}
