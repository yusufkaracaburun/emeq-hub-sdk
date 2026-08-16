# 3. The booking ledger lives in the consumer

- Status: accepted
- Date: 2026-08-16

## Context

Hub already records what it posted. `provider_entity_links` maps a canonical
`external_id` to the entity the provider created, and `idempotency_keys` holds
the replayable response. A consumer could ask Hub every time and keep nothing.

It does not work, for three reasons found while building the first consumer.

**A backlog is a join, not a lookup.** The page that matters is "which of my
documents are not booked yet" — a query over the consumer's own invoices,
left-joined to their booking state, filtered and sorted and paginated by the
consumer's columns. Hub cannot participate in that join. `GET
/v1/accounting/documents` is a live provider read that requires a `type`
parameter and returns a thin projection; it answers "what does the bookkeeping
contain", not "what of mine is still outstanding".

**Refusals are state too.** `mapping_failed`, `upstream_rejected` and
`insufficient_ability` are answers about a document that Hub does not keep as
consumer-readable state — `provider_entity_links` records what succeeded. A
consumer that stores only successes cannot show a user why yesterday's batch
stopped, and cannot tell "never attempted" from "attempted and refused".

**Some answers decide nothing.** `409 idempotency_request_in_progress`, `429`
and `5xx` say nothing about the document. Writing a row for those would be a
lie, and the distinction between "no row" and "a row saying failed" is the whole
retry policy.

## Decision

The SDK ships a `hub_documents` ledger — an Eloquent model plus a migration stub
— that lives in the consumer's database. One row per `(account_id, type,
external_id)`, holding the outcome of the last decided attempt.

It is a record of *what this consumer sent and what Hub answered*, not a mirror
of the bookkeeping. Hub stays the authority on what is actually posted; the
ledger is the authority on what this consumer may safely send again.

The migration follows ADR-0002: published, never loaded. The failure mode is
sharper here than for `webhook_calls` — a ledger on the wrong connection reads
as "not booked yet", and the next run posts a duplicate into a real
administration. `HubDocument` therefore declares no connection of its own and
reads `hub.booking.connection`, which defaults to the consumer's default.

## Consequences

- The ledger's schema is public API. Adding a column is a minor release with a
  new stub; consumers already on an older stub keep working because the model
  never selects columns explicitly.
- Consumers query the table directly for their own backlog. That is expected,
  and it is why the table name is stable and reachable through
  `(new HubDocument)->getTable()` rather than hard-coded.
- Two stores can disagree. If a consumer's ledger is wiped, Hub's idempotency
  window still refuses the resend — which is the correct outcome and the reason
  `document_already_posted` is classified as a refusal rather than an error.
- Nothing in this package may assume the ledger exists on the default
  connection, or that a row exists at all.
