# 4. The SDK stops carrying a create-relation flag

Date: 2026-08-17

## Status

Accepted. Ships in v0.21.0 (breaking). v0.20.0 was already taken by an
unrelated `request_id`/`category` release.

## Context

`DocumentBooker::book()` and `BookingRunner::book()/bookOne()` take a `$createRelation`
boolean that sets `party.create_if_missing` on the outgoing payload. Consumer apps wire it to
a per-booking option, which in at least one app surfaced as a checkbox with a warning label
next to it.

The Hub has retired that field. Relation resolution is now a deterministic ladder — mirror,
chamber of commerce, VAT number, normalised name, create — where the create step is the
outcome of four failed lookups rather than a caller's request. See the Hub's
`relation-resolution-ladder` decision.

Keeping the parameter would mean the SDK sends a field the Hub rejects, and would keep asking
callers a question that no longer has an answer.

## Decision

Remove `$createRelation` from `DocumentBooker::book()`, `BookingRunner::book()` and
`BookingRunner::bookOne()`. The party block keeps its other behaviour, including pinning
`party.external_id` to the value a previous booking used — that guard against re-opening a
second relation stays.

Surface `warnings[]` from the booking response on the result object. The Hub reports there what
it did to the administration (`relation.created`, `relation.matched_by_name`,
`relation.name_differs`, and `relation.relinked` since Hub 2026-08-18, when a mirrored relation
turns out to be gone from the administration); a consumer app that drops those leaves its user blind to a write in
their own bookkeeping. The checkbox went away, so the report must arrive.

Carry the party contract through to the fake and the test helpers: `kind` (`company` |
`person`), a required `external_id`, and the optional `relation_id` pin.

## Consequences

- Breaking for every caller of the booking API — hence a minor bump on a 0.x line, with the
  removal called out at the top of the changelog.
- Consumer apps delete their own flag plumbing rather than passing `false` everywhere.
- A consumer that ignores `warnings[]` still books correctly; it just tells its user less.
