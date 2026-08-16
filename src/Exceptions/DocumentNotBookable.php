<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Exceptions;

use RuntimeException;

/**
 * This document cannot be turned into a canonical Hub document at all — it is
 * a draft, it is missing a party, its VAT does not reconcile.
 *
 * Thrown by the consumer's own mappers, never by this package: what makes a
 * document mappable is the consumer's data model. It lives here so the SDK can
 * catch "will never book" apart from "did not book this time" without knowing
 * the consumer's exception classes.
 *
 * Nothing is sent and nothing is recorded in the ledger; the document is
 * unchanged and can be fixed and re-offered.
 */
class DocumentNotBookable extends RuntimeException {}
