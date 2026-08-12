# 1. The SDK stays provider-agnostic

- Status: accepted
- Date: 2026-08-12

## Context

Hub integrates with several accounting and billing partners, and the list grows.
The obvious SDK shape — a class or method per partner, with a `Provider` enum —
means every new Hub partner needs an SDK release, a consumer upgrade, and a
deploy before it can be used. Consumers would be blocked on us for work that
happens entirely inside Hub.

## Decision

The SDK exposes only Hub's canonical surface. Providers are data:

- `integrations()->list()` returns Hub's discovery output; consumers render it.
- `oauth()->init($provider)` takes the free-form `key` from that output. No
  allowlist, no validation against a known set.
- Partner-specific request/response handling lives in Hub and the
  `emeq/*-api` packages, never here.

`HubWebhookEvent` is the one closed set, because it is Hub's own canonical
vocabulary rather than a partner's — and even there an unknown value decodes to
`UNMAPPED` instead of throwing.

## Consequences

- A new Hub partner appears in consumer UIs with no SDK release. This is the
  main reason the package exists in this shape.
- Consumers cannot get partner-specific behaviour through this SDK. If they need
  it, that is a signal the capability belongs in Hub's canonical API.
- Response shapes are `array<string, mixed>` rather than per-partner DTOs.
  Typed DTOs would have to enumerate partners, which is the thing this decision
  rejects.
