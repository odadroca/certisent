# Certisent — Agent-Native Redesign: Architecture Handover

| Field        | Value                                                                 |
|--------------|-----------------------------------------------------------------------|
| Status       | Draft for sprint planning                                             |
| Version      | 0.1                                                                   |
| Target line  | Certisent 0.8 → 1.0                                                   |
| Scope        | Make Certisent first-class consumable by A2A / MCP agents             |
| Strategy     | Evolution (additive surface), not rewrite                             |
| Owners (TBD) | Backend lead, Platform/API lead, Security lead, Docs/DevRel           |

---

## 1. Executive summary

Certisent today is a human-operator product with a thin API bolt-on. The data model
(immutable snapshots + events + audit log) is the right substrate to serve agents,
but the *outer surface* is not: no discovery, no machine-readable contract,
no streaming tasks, no agent identity in audit, no verifiable artifacts.

This handover defines an additive, sprint-sized path to turn Certisent into a
**verifiable TLS observation oracle** that A2A and MCP agents can discover,
call, subscribe to, and trust downstream — without rewriting the core or
breaking the existing PHP UI.

Phase 1 (Sprints 1–4) ships an agent-native API surface on top of today's
services. Phase 2 (Sprints 5–8) adds the differentiators that justify
Certisent's place in a multi-agent mesh: capability tokens, signed snapshots,
CT corroboration, policy-as-data.

---

## 2. Current state (recap)

- **Core (keep):** `cert_snapshots`, `events`, `audit_log`, `notification_outbox`,
  `monitors`, `monitor_settings`, `api_keys`, `rate_limits`.
- **Services (keep, extend):** `app/services/{CertFetcher,Worker,Notifier,TlsValidator,SsrfPolicy,RateLimiter,Audit,MonitorService}.php`.
- **API today:** `GET /api/v1/health`, `POST /api/v1/worker/run`, `POST /api/v1/check`.
  Bearer tokens, scopes `read_health|run_worker|check_monitor`.
- **Surface gaps for agents:** no discovery, no resource addressing,
  no streaming, no subscriptions, no signed artifacts, no agent identity,
  no CT cross-check, UI-only CRUD for monitors/settings.

---

## 3. Target architecture

```
                        ┌────────────────────────────┐
                        │  Agents (A2A / MCP / curl) │
                        └─────────────┬──────────────┘
                                      │
       ┌──────────────────────────────┼──────────────────────────────┐
       │  Discovery & Contracts                                       │
       │  /.well-known/agent.json   /.well-known/openapi.json         │
       │  /mcp  (stdio + streamable HTTP)                             │
       └──────────────────────────────┬──────────────────────────────┘
                                      │
       ┌──────────────────────────────┴──────────────────────────────┐
       │  Public API v2                                               │
       │  REST CRUD  •  SSE stream  •  Task lifecycle  •  JWS export  │
       │  Capability-token auth (delegatable)                         │
       └──────────────────────────────┬──────────────────────────────┘
                                      │
       ┌──────────────────────────────┴──────────────────────────────┐
       │  Domain services (existing, extended)                        │
       │  Monitors • CertFetcher • TlsValidator • Worker • Notifier  │
       │  + SnapshotSigner • CtCorroborator • CapabilityIssuer        │
       └──────────────────────────────┬──────────────────────────────┘
                                      │
       ┌──────────────────────────────┴──────────────────────────────┐
       │  MySQL/MariaDB (append-only snapshots, events, audit, JWKS) │
       └──────────────────────────────────────────────────────────────┘
```

Backward compatibility: `/api/v1/*` remains supported through 1.x; new surface
is `/api/v2/*` (or `/api/v1/` parallel routes — see ADR-001).

---

## 4. Guiding principles

1. **Additive, not destructive.** PHP UI and v1 API keep working until 1.0.
2. **Contract first.** Every new endpoint ships with OpenAPI + JSON Schema in the same PR.
3. **Verifiable by default.** New artifacts (snapshots, events) carry signatures and IDs an agent can quote.
4. **Capability over role.** Tokens declare *what they can do on which resource*, not "admin/user".
5. **Streaming is normal.** Long operations return a task, not a blob.
6. **Provenance is logged.** Every mutating call records `(user, agent, delegation chain, capability hash)`.
7. **No fork of the schema.** New tables/columns only, with migrations; never rewrite history.

---

## 5. Workstreams

| Code  | Workstream                          | Sprints     |
|-------|-------------------------------------|-------------|
| WS-A  | Contracts & discovery               | 0, 1, 2, 3  |
| WS-B  | API surface expansion               | 1, 4        |
| WS-C  | Identity, capabilities, audit       | 5           |
| WS-D  | Verifiable artifacts                | 6           |
| WS-E  | External corroboration (CT)         | 7           |
| WS-F  | Policy-as-data, hardening, GA       | 8           |
| WS-G  | Cross-cutting: tests, docs, ops     | all         |

---

## 6. Sprint plan

Each sprint is sized for ~2 weeks of one backend engineer plus part-time
security and docs review. T-shirt sizes (S/M/L) are relative; refine after Sprint 0.

### Sprint 0 — Discovery & ADRs (S)

**Goal.** Lock the contracts and decisions before code lands.

**Deliverables.**
- ADR-001: Versioning (`/api/v2` vs. parallel `/api/v1` routes).
- ADR-002: Auth model (PASETO/biscuit/macaroon for capability tokens — pick one).
- ADR-003: Signature scheme for snapshots (JWS detached vs. COSE) and key management (file-based JWKS now, KMS later).
- ADR-004: A2A AgentCard scope and skill naming.
- ADR-005: MCP transport (stdio + streamable HTTP) and adapter location (in-tree PHP vs. sidecar).
- Spike: prototype JWS signing of a snapshot row and verify with a stand-alone script.
- Updated `docs/architecture.md` with target diagram.

**Acceptance.** ADRs merged; spike repo'd under `tools/spikes/`; planning estimates refined.

**Out of scope.** Any production code change.

---

### Sprint 1 — OpenAPI + CRUD expansion on v1 (M)

**Goal.** Make every capability currently exclusive to the PHP UI reachable from the API.

**Deliverables.**
- `docs/openapi.yaml` covering existing + new endpoints.
- Served at `GET /.well-known/openapi.json` and `GET /api/v1/openapi.json`.
- New endpoints (Bearer-scoped, backward compatible):
  - `GET/POST /api/v1/monitors`, `GET/PATCH/DELETE /api/v1/monitors/{id}`
  - `GET/PATCH /api/v1/monitors/{id}/settings` (TLS mode, pin, frequency, notify threshold)
  - `GET /api/v1/monitors/{id}/snapshots?limit&cursor`
  - `GET /api/v1/snapshots/{id}` (returns parsed JSON + `application/pem-certificate-chain` via Accept)
  - `GET /api/v1/monitors/{id}/events?since&severity&type&cursor`
  - `GET /api/v1/monitors/{id}/diff?from=<snap_id>&to=<snap_id>`
- Cursor pagination, ETag, `If-None-Match`, RFC 7807 problem responses.
- New scopes: `monitors:read`, `monitors:write`, `events:read`, `snapshots:read`. Legacy scopes preserved.

**Acceptance.**
- 100% of UI-only CRUD reachable via API.
- Schema validation enforced by request middleware.
- `Idempotency-Key` honored on POST/PATCH.
- No regression in existing UI flows (manual smoke + the existing test plan).

**Dependencies.** ADR-001.

---

### Sprint 2 — A2A AgentCard + Task lifecycle (M)

**Goal.** Make Certisent discoverable as an A2A agent and turn worker runs into addressable tasks.

**Deliverables.**
- `GET /.well-known/agent.json` (AgentCard) declaring:
  - identity, endpoint, auth schemes (Bearer + planned capability tokens),
  - skills: `monitor.create`, `monitor.check`, `monitor.diff`, `events.list`, `snapshot.get`, `pin.set`, `trust.classify`,
  - streaming and push notification support flags.
- Task table `tasks` (`id`, `kind`, `status`, `created_at`, `updated_at`, `actor_*`, `result_json`, `error_json`).
- New endpoints:
  - `POST /api/v1/tasks` (replaces single-shot `worker/run` and `check`; old endpoints become thin facades that create a task and inline-await).
  - `GET /api/v1/tasks/{id}`
  - `POST /api/v1/tasks/{id}/cancel`
  - `GET /api/v1/tasks/{id}/stream` (SSE — see Sprint 4 for full streaming pipeline).
- Worker is refactored to write task state transitions; cron path unchanged.

**Acceptance.**
- An A2A-compatible client can resolve the AgentCard and invoke `monitor.check` via the task surface.
- Legacy `POST /api/v1/worker/run` and `POST /api/v1/check` continue to work and return the same shape.
- Task lifecycle is reflected in `audit_log` and `events`.

**Dependencies.** Sprint 1 (OpenAPI), ADR-004.

---

### Sprint 3 — MCP adapter (M)

**Goal.** Same capabilities, exposed as MCP tools and resources, so LLM clients (Claude, etc.) can use Certisent natively.

**Deliverables.**
- `tools/mcp/certisent-mcp/` — Node or Python MCP server (decision in ADR-005) that proxies to the v1 API using a service token.
- **Tools:** `monitor_create`, `monitor_check`, `monitor_diff`, `events_query`, `snapshot_get`, `pin_set`, `trust_classify`.
- **Resources:** URI templates
  - `certisent://monitor/{id}`
  - `certisent://monitor/{id}/snapshot/{seq}`
  - `certisent://monitor/{id}/events?since=…`
- **Prompts:** at least `incident_triage(monitor_id)` returning a structured prompt with the latest diff + events.
- Distributed via npm (or PyPI) and as a Docker image; documented in `docs/mcp.md`.
- Streamable HTTP transport supported; stdio for local dev.

**Acceptance.**
- A Claude Code session can `npx certisent-mcp` against a running Certisent and call all listed tools.
- Resource URIs round-trip (fetch via MCP yields same bytes as the equivalent v1 GET).

**Dependencies.** Sprint 1, ADR-005.

---

### Sprint 4 — Streaming, subscriptions, agent-as-channel (M)

**Goal.** Agents can subscribe to monitor events and Certisent can push to other agents.

**Deliverables.**
- `GET /api/v1/events/stream?monitor_id=&since=&severity=` over **SSE**. Reconnect via `Last-Event-ID`.
- `POST /api/v1/subscriptions` registers a push target (URL + auth + filter). Stored in a new `subscriptions` table.
- New outbox delivery type `a2a_push`: outbound POST to subscriber URL with signed event JSON.
- Webhook payload schema published in `docs/schemas/event.v1.json`.
- Per-subscription rate limit and exponential backoff (reuses outbox infra).
- Stream and push events share the same canonical event object.

**Acceptance.**
- An agent can register a subscription and receive an event push within one worker tick of it being recorded.
- Reconnecting SSE clients receive missed events back to `since`.
- Subscription failures surface in Admin → Outbox with retry telemetry.

**Dependencies.** Sprint 2 (tasks/events shape).

---

### Sprint 5 — Capability tokens & agent identity in audit (L)

**Goal.** Replace coarse Bearer scopes with delegatable capabilities and record agent provenance.

**Deliverables.**
- Capability token format (per ADR-002): biscuit / macaroon / PASETO with caveats:
  - `monitor_id ∈ {…}`, `actions ⊆ {read,write,check,subscribe}`, `expires`, `agent_id`, `parent_token_hash`.
- New endpoints:
  - `POST /api/v1/capabilities` — mint from a user-owned token, with caveats.
  - `POST /api/v1/capabilities/attenuate` — derive a strictly narrower token.
  - `POST /api/v1/capabilities/revoke` (by hash).
- Verifier middleware: accepts Bearer (legacy) and capability tokens, computes effective permissions.
- `audit_log` schema extension: `actor_user_id`, `actor_agent_id`, `delegation_chain_json`, `capability_hash`.
- Admin UI: view active capabilities and revoke.

**Acceptance.**
- A user-owned Bearer can mint a read-only capability for monitor 42, valid for 1h, that cannot be used on monitor 43 or for writes — verified by tests.
- Every mutating action since Sprint 5 ship records the agent and delegation chain.
- Legacy Bearer keys still work.

**Dependencies.** Sprints 1, 2; ADR-002.

---

### Sprint 6 — Signed snapshots & verifiable export (M)

**Goal.** Snapshots and events become provable artifacts an agent can pass on.

**Deliverables.**
- Instance signing key + rotation; JWKS exposed at `/.well-known/jwks.json`.
- On snapshot insert: compute canonical JSON, sign as JWS (detached payload), store `snapshot_signatures(snapshot_id, jws, kid, signed_at)`.
- `GET /api/v1/snapshots/{id}.jws` returns the detached JWS.
- `Accept: application/cert+jws` content-negotiated on snapshot endpoint.
- Optional: RFC 3161 timestamp request per snapshot, stored alongside.
- Verifier CLI in `tools/verify-snapshot/` so downstream agents can validate without Certisent.
- `docs/verification.md` with worked example.

**Acceptance.**
- A third party (with only the JWKS and a snapshot JWS) can verify integrity and `signed_at`.
- Key rotation works: old signatures verify against archived `kid`.

**Dependencies.** ADR-003.

---

### Sprint 7 — CT log corroboration skill (M)

**Goal.** Certisent becomes useful for *cross-checking* what an endpoint serves vs. what was logged.

**Deliverables.**
- `CtCorroborator` service: query CT logs (crt.sh API and/or a chosen log via RFC 6962) for a host.
- New endpoint and A2A/MCP skill `cert.corroborate`:
  - input: host (or monitor_id),
  - output: `{served_spki, ct_entries:[…], anomalies:[…]}`.
- Anomaly classes: `served_not_logged`, `logged_not_served`, `recent_unexpected_issuance`.
- New event types `tls_ct_anomaly_*` with the same outbox/subscription flow.
- Caching layer (CT lookups are slow) with TTL and stampede protection.
- SSRF policy extension for CT endpoints (allowlist).

**Acceptance.**
- For a known-good host: zero anomalies.
- For a host where a fresh cert is logged that Certisent has never seen served: anomaly raised within a configurable window.
- Cache hit ratio measurable in heartbeat panel.

**Dependencies.** Sprint 4 (event/subscription pipeline).

---

### Sprint 8 — Policy-as-data, hardening, 1.0 GA (M)

**Goal.** Close the loop: agents can read *and write* monitoring policy with schema-validated calls, and the surface is ready for GA.

**Deliverables.**
- `GET/PUT /api/v1/monitors/{id}/policy` returning/accepting JSON Schema-validated policy:
  `frequency`, `tls_validation_mode`, `pin_mode`, `pin_spki_sha256`, `notify_threshold_days`, `subscriptions`.
- `GET /api/v1/schemas/policy.v1.json` — the schema, also referenced from the OpenAPI.
- Conformance test suite (`tests/conformance/`) hitting AgentCard, OpenAPI, MCP, and capability flows.
- Load/abuse profile: subscription fan-out, SSE reconnect storm, CT lookup stampede.
- Threat model doc (`docs/threat_model_agent_surface.md`).
- Release: deprecate `v0.x` legacy scopes (still supported but warned), cut **Certisent 1.0**.

**Acceptance.**
- A reference agent (open-sourced under `examples/`) walks: discover → mint capability → create monitor → subscribe → receive signed event → verify offline → close subscription.
- Threat model signed off by security.
- Migration guide for 0.7.x → 1.0 in `docs/upgrade_1.0.md`.

**Dependencies.** All prior sprints.

---

## 7. Cross-cutting concerns

### Authentication and authorization
- Legacy Bearer keys honored through 1.x.
- New auth scheme: capability tokens (Sprint 5).
- Every middleware decision logged with capability hash + caveat evaluation result.

### Observability
- Per-endpoint latency histograms, per-subscription delivery lag, per-task duration.
- Heartbeat panel (Admin → System) gains: active subscriptions, SSE connections, CT cache hit rate, last signing key rotation.

### Data migrations
- Forward-only migrations under `sql/migrations/`. Each sprint owns its migrations.
- No destructive migrations; columns are added nullable with backfill jobs run by the worker.

### Backward compatibility
- v1 endpoints remain functional through 1.x.
- The PHP UI calls the new APIs internally where convenient but is not blocked on it.
- Outbound email/webhook channels remain first-class alongside `a2a_push`.

### Testing
- Contract tests against `openapi.yaml` (Spectral + Dredd or schemathesis).
- A2A and MCP conformance suites added in Sprint 8 but seeded earlier.
- A signed-snapshot golden test fixture set under `tests/fixtures/snapshots/`.

### Documentation
- `docs/agent_guide.md` (new) — for agent builders.
- `docs/mcp.md`, `docs/a2a.md`, `docs/verification.md`, `docs/upgrade_1.0.md`.
- Every sprint updates `docs/api.md` and the OpenAPI in the same PR.

---

## 8. Risks & open decisions

| ID    | Risk / Decision                                                          | Mitigation / Owner          |
|-------|--------------------------------------------------------------------------|-----------------------------|
| R-01  | Token format choice (biscuit vs. macaroon vs. PASETO) — PHP library maturity varies. | ADR-002, security lead.    |
| R-02  | MCP server is non-PHP; introduces a second runtime.                      | Ship as optional sidecar; document independent release cadence. |
| R-03  | Signing keys on shared hosting — no HSM.                                 | File-based JWKS with rotation; KMS path documented for VPS deploys. |
| R-04  | CT lookups are slow and rate-limited externally.                         | Caching + per-host cooldown; SSRF allowlist; optional disable flag. |
| R-05  | SSE on PHP shared hosting can be flaky (process limits).                 | Provide long-poll fallback; document hosting requirements. |
| R-06  | Capability revocation requires central check on every request.           | LRU revocation cache; size limits documented. |
| R-07  | Audit-log schema change touches a hot table.                             | Online migration with shadow columns; cutover behind a flag. |
| R-08  | A2A spec is moving.                                                      | Pin to a dated revision in ADR-004; revisit at GA.            |

---

## 9. Definition of done (per sprint)

A sprint is done when:

1. Code merged behind a feature flag where applicable.
2. OpenAPI/JSON Schemas updated and validated in CI.
3. Migrations applied to a staging DB without errors and rolled forward on a copy of production data.
4. New scopes/capabilities documented in `docs/api.md`.
5. Manual smoke test on the reference deploy passes.
6. Security review sign-off on any auth/crypto change.
7. Release note appended to `docs/release_notes_v0.x.md`.

---

## 10. Roadmap at a glance

| Sprint | Theme                              | Headline outcome                                         |
|--------|------------------------------------|----------------------------------------------------------|
| 0      | ADRs & spike                       | Decisions locked, signing spike works.                   |
| 1      | OpenAPI + CRUD                     | Every UI capability reachable from the API.              |
| 2      | A2A AgentCard + Tasks              | Discoverable agent; long-running tasks addressable.      |
| 3      | MCP adapter                        | LLM clients use Certisent natively.                      |
| 4      | Streaming & subscriptions          | Agents subscribe; Certisent pushes signed events.        |
| 5      | Capability tokens + agent identity | Delegatable scoped access, agent-attributed audit log.   |
| 6      | Signed snapshots                   | Snapshots verifiable offline by any downstream agent.    |
| 7      | CT corroboration                   | Cross-source identity oracle.                            |
| 8      | Policy-as-data + 1.0 GA            | Agents write policy; conformance suite; ship 1.0.        |

---

## Appendix A — Sample AgentCard (sketch)

```json
{
  "name": "Certisent",
  "description": "TLS observation oracle: live cert fetch, immutable snapshots, signed events, CT corroboration.",
  "url": "https://example.com/certisent/public",
  "version": "1.0.0",
  "auth": {
    "schemes": ["bearer", "capability-token"],
    "tokenEndpoint": "/api/v1/capabilities"
  },
  "capabilities": {
    "streaming": true,
    "pushNotifications": true,
    "stateTransitionHistory": true
  },
  "skills": [
    { "id": "monitor.check",    "input": "MonitorCheckInput",    "output": "Task<MonitorCheckResult>" },
    { "id": "monitor.diff",     "input": "MonitorDiffInput",     "output": "SnapshotDiff" },
    { "id": "events.subscribe", "input": "SubscribeInput",       "output": "Subscription" },
    { "id": "cert.corroborate", "input": "HostInput",            "output": "CtCorroboration" }
  ]
}
```

## Appendix B — Sample MCP tool registration (sketch)

```json
{
  "name": "monitor_diff",
  "description": "Return a structured diff between two certificate snapshots of a monitor.",
  "inputSchema": {
    "type": "object",
    "required": ["monitor_id", "from", "to"],
    "properties": {
      "monitor_id": { "type": "integer" },
      "from": { "type": "string", "description": "snapshot id" },
      "to":   { "type": "string", "description": "snapshot id" }
    }
  }
}
```

## Appendix C — Sample signed event payload (sketch)

```json
{
  "id": "evt_01HZ...",
  "monitor_id": 123,
  "type": "tls_pin_mismatch",
  "severity": "critical",
  "observed_at": "2026-06-03T09:14:22Z",
  "snapshot_id": "snap_01HZ...",
  "expected_spki_sha256": "…",
  "observed_spki_sha256": "…",
  "signature": {
    "alg": "EdDSA",
    "kid": "certisent-2026-06",
    "jws": "eyJhbGciOi..."
  }
}
```
