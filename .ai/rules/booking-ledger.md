---
paths:
  - 'src/Booking/**'
  - 'src/Backlog/**'
---

# Booking ledger and backlog

## Everything here runs on `hub.booking.connection`, not the default one

`HubDocument::getConnectionName()` reads that config key, and `BacklogRepository`
builds *every* query — outer, summary and join — on the same connection via its
`connection()` helper. A `DB::query()` or `DB::table()` added here silently aims
at `database.default` instead, which is a different database the moment a
consumer sets the key. Fixed in 0.24.0; the join used to be the only part that
honoured it.

## Schema probes must be keyed by connection *and* database name

`HubDocument::tracesRequests()` and `AccountingChangeRecorder::marksChanges()`
ask the ledger whether it carries optional columns, and cache the answer for the
life of the process. Multi-DB consumers swap the database *behind one connection
name* per tenant, so a single `?bool` froze the first tenant's answer for every
later one — a tenant missing the migration then failed every booking until the
worker restarted. Key any new probe with `HubDocument::schemaKey()`.

## One rule decides which ledger row is current

`HubDocument::currentIds()`: the posted row wins, otherwise the highest id.
`forBooking()`, `forExternalIds()` and the backlog's join all read it. Writing a
second rule (a bare `MAX(id)`, an `orderByDesc('id')`) makes one response
contradict itself — the list said `failed` while the attached record said
`posted`.
