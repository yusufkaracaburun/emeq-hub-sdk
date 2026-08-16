<?php

declare(strict_types=1);

/*
 * Copy for the outcomes this package decides. Everything a consumer's own
 * booking screen says stays in the consumer's own lang files — publish
 * `hub-translations` to reword these.
 */

return [

    'not_found' => 'This document no longer exists.',
    'not_allowed' => 'You may not edit this document, so you may not book it either.',
    'temporarily_unavailable' => 'A booking of this document is already in flight, or the bookkeeping is briefly unreachable. Try again shortly.',

    'error' => [
        'mapping_failed' => 'The bookkeeping does not know this relation or code yet.',
        'upstream_rejected' => 'The bookkeeping refused this document on its contents.',
        'document_already_posted' => 'This document was already booked with different contents.',
        'idempotency_key_reuse' => 'This key already belongs to another document.',
        'insufficient_ability' => 'The connection is not allowed to book. Check its permissions.',
        'provider_disabled' => 'The connection to the bookkeeping is switched off.',
        'connection_interrupted' => 'The connection to the bookkeeping dropped before it answered. Check there whether the document was booked before retrying.',
        'attachment_render_failed' => 'The attachment could not be produced, so nothing was booked.',
        'unknown' => 'The bookkeeping returned an unknown error.',
    ],

];
