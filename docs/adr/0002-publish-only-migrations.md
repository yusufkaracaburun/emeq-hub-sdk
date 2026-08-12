# 2. Migrations are publish-only

- Status: accepted
- Date: 2026-08-12

## Context

Inbound Hub webhooks are stored by `spatie/laravel-webhook-client` in a
`webhook_calls` table. A package can load its migrations automatically with
`loadMigrationsFrom()`, which is the usual convenience.

Several consumers are multi-database: one connection per tenant, or a separate
connection for webhook traffic. An auto-loaded migration runs on the default
connection, which for those apps is the wrong one — and the mistake only shows
up later, as webhooks landing in a database nobody reads.

## Decision

`HubServiceProvider` publishes the `create_webhook_calls_table` stub and never
loads it. Consumers run it themselves, on the connection they choose.

The same reasoning drives `hub.webhook.job` / `hub.webhook.profile`: a multi-DB
consumer points those at a subclass that binds the tenant connection before the
job touches `webhook_calls`.

## Consequences

- Installation has an explicit step: publish, then migrate. `hub:install` says so
  and the README repeats it.
- Consumers can put `webhook_calls` wherever it belongs, including a tenant
  database.
- Nothing in this package may assume the table exists on the default connection.
  `ProcessHubWebhookJob` reaches it only after `bindAccountContext()`.
