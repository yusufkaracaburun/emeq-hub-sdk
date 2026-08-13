---
paths:
  - 'src/Webhooks/**'
---

# Webhooks

## webhook_calls must live on the connection bound by bindAccountContext()
ProcessHubWebhookJob::handle() calls bindAccountContext() first, then reads WebhookCall via resolveWebhookCall() and HubWebhookDeduplicator::alreadyProcessed(). Both use the default connection *after* the switch.

So multi-DB consumers must store webhook_calls in the tenant DB, and ResolvesWebhookAccount::prepare() must perform the tenant/connection switch before Spatie stores the call.

Storing webhook_calls centrally while binding a tenant connection fails silently: resolveWebhookCall() returns null and every delivery is logged as hub.webhook.skipped / webhook_call_missing — no exception, no failed job.
