# 5. The SDK writes the ledger columns it reads

- Status: accepted
- Date: 2026-08-18

## Context

0.18.0 added `accounting_changed_at`, `accounting_change_action` and
`accounting_change_event_id` to `hub_documents`, and taught the backlog to use
them: `BacklogRepository`'s `accounting_changed` filter, the
`whereNull('accounting_changed_at')` in `PostedDocuments::excluding()`, and both
`BookingResource` and `BacklogDocumentResource`.

It shipped no writer. `docs/webhooks.md` described the join a consumer had to
write — look up a posted row by `account_id` + `external_ref`, check it is not
the echo of your own booking, write the three columns — and the first consumer
transcribed that prose into 150 lines. A second consumer would have transcribed
it again, differently.

That is the shape ADR-0003 warns about from the other direction. The ledger lives
in the consumer's database, but the *mechanics* of it are this package's job —
which is exactly why `PostedDocuments` ships here: it is account-scoped ledger
SQL, and an unscoped version hides another administration's postings without any
error. The writer has the same failure mode, and the same silence. A missed
marker reads as "the bookkeeping never touched this", forever.

## Decision

`AccountingChangeRecorder` ships in this package and is registered as a listener
on `HubWebhookReceived` by the service provider.

A listener rather than a call inside `ProcessHubWebhookJob`, for two reasons.
Every multi-DB consumer subclasses that job, and a subclass that forgets
`parent::onEvent()` would silently lose the marker. And `Webhooks/` would
otherwise gain a dependency on `Booking/`; the service provider is where those
two are allowed to meet.

The echo suppression is part of it. Hub reports authorship and timing and
deliberately draws no line — `HubWebhookEnvelope::isOwnEcho()` reads anything it
cannot establish as "not an echo", which is right for deciding whether to look at
an event and wrong for a marker that would then appear on every document this
consumer books. So the recorder takes a second signal, proximity to the row's own
`booked_at`, and either one suppresses. Both share one window, and the window is
a constructor argument because it is a product decision, not a protocol fact.

It reads `entity_id` and `action` off the envelope and never the raw partner
payload. A fallback into `data['Content']['Key']` would work today and would put
an Exact-shaped assumption in a package that ADR-0001 keeps provider-agnostic;
`entityKey()` is `protected` for a consumer that needs that rescue.

## Consequences

- A consumer that was writing these columns itself now has two writers. The
  second is idempotent — same row, same values — but the consumer's own copy
  should go, and `EMEQ_HUB_BOOKING_RECORD_ACCOUNTING_CHANGES=false` exists for
  the case where it cannot go yet.
- Nothing may assume the columns exist. A consumer that books nothing never
  published the ledger migration, and one on a pre-0.18 stub has the table
  without them, so the recorder resolves that once per process and does nothing
  when they are absent. A throw would return a 5xx to Hub, which retries the
  whole envelope five times over roughly three hours for a column nobody is
  waiting on — hence `record()` reports and swallows rather than propagating.
- The schema answer is memoised per process, like `HubDocument::tracesRequests()`.
  A multi-DB consumer whose tenants are migrated apart can therefore cache the
  wrong answer; the write that follows fails, is reported, and the delivery still
  succeeds. Migrating tenants together remains the assumption.
- The join key stays `external_ref`. That ties this to Hub returning the
  provider's own id on a booking and repeating it as `entity_id` on the event;
  if either changes, this class is where it changes.
