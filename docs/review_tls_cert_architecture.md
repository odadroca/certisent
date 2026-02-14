# Architecture Review: Proposed TLS Certificate Monitoring System vs. Certisent v0.7.6

---

## (a) Observed facts from my text

### a.1 What Certisent v0.7.6 actually is

- A **PHP monolith** (~5,555 lines), zero external dependencies (no Composer, no npm), targeting shared hosting and small VPS deployments.
- **MySQL/MariaDB** as the sole data store. No time-series DB, no vector DB, no Postgres.
- **Cron-driven single-process worker** (`scripts/worker.php --due`), typically run every 5-15 minutes. No distributed scheduling, no multi-region probes, no agent fleet.
- **Certificate fetching** (`CertFetcher.php`) performs a single SNI-enabled TLS handshake per monitor with `verify_peer=false` (intentional: observe what's served). Returns PEM + parsed metadata + SHA256 fingerprint.
- **Validation** (`TlsValidator.php`, 398 lines) is opt-in per monitor and limited to three modes:
  - Hostname identity matching (SAN/CN, wildcard, IDNA).
  - Chain trust validation via a separate probe (`verify_peer=true`, curl preferred, stream fallback). Classifies into `tls_self_signed`, `tls_untrusted_root`, `tls_untrusted_unknown`.
  - SPKI SHA256 pinning (v0.7.6).
- **No OCSP/CRL checking exists.** The codebase does not query OCSP responders, does not parse CRL distribution points, and does not store revocation status. The proposed schema field `ocsp_status` and `crl_status` have no implementation counterpart.
- **No signature algorithm or key-size extraction.** The parsed certificate fields stored are: serial, fingerprint, issuer CN, subject CN, validity dates, raw PEM. `sig_algo` and `pubkey_bits` from the proposal's `cert_snapshot.parsed` have no column in `cert_snapshots` and are not extracted by `Worker::checkOne()`.
- **No SAN storage.** SANs are evaluated at validation time in `TlsValidator::validateHostname()` but are not persisted in the snapshot or any table. The proposal assumes SANs are stored per snapshot.
- **No chain depth or intermediate certificate capture.** `CertFetcher::fetch()` captures only the leaf certificate via `stream_context_get_params()`. The proposal's `chain_depth` metric and "full chain" remediation assume chain data is available; it is not.
- **Events are append-only rows in MySQL** with a VARCHAR(1024) message and a JSON `meta_json` blob. There is no structured `rationale_ids` or `remediation_action` field. Events are typed strings (`expiry_warning`, `renewed`, `changed`, `check_failed`, `tls_wrong_host`, `tls_self_signed`, `tls_untrusted_root`, `tls_pin_mismatch`, etc.).
- **Notifications** are email (mail/SMTP/API), Slack webhook, and Teams webhook. No ACME integration. No ticket-creation integration. No automated remediation.
- **No API for "assess" or "explain" semantics.** The existing API (`Router.php`, 147 lines) exposes three endpoints: `GET /health`, `POST /worker/run`, `POST /check`. All are imperative (trigger a check, fetch a result). There is no retrieval, reasoning, or intent-based interface.
- **No embedding, vector similarity, or LLM integration anywhere in the codebase.** Zero references to any model, embedding, or retrieval-augmented generation pattern.
- **No automated test suite.** The README acknowledges this as a beta limitation.
- **Deployment target is shared hosting / single-server.** The architecture doc (`docs/architecture.md`) explicitly names "cron invokes worker" as the execution model. There is no container orchestration, no gRPC, no service mesh.

### a.2 What the proposed architecture introduces (net-new relative to codebase)

| Proposed component | Exists in codebase? | Gap size |
|-|-|-|
| Multi-region probes (cloud + on-prem agents) | No. Single cron worker, single origin. | Complete build-out. |
| OCSP/CRL status collection | No. Not implemented or modeled. | New protocol + storage. |
| TLS version/cipher collection | No. `stream_socket_client` does not expose negotiated cipher or protocol version. | Requires OpenSSL CLI or different client library. |
| Time-series DB (Prometheus/InfluxDB) | No. MySQL only. | New infrastructure dependency. |
| Vector DB (Pinecone/Weaviate/RedisVector) | No. | New infrastructure dependency. |
| Postgres as authoritative store | No. MySQL/MariaDB. | Migration or dual-write. |
| Embedding generation for cert metadata | No. | New model pipeline. |
| Retrieval-augmented LLM reasoning layer | No. | Entire new service. |
| Declarative intent API (`/assess`) | No. API is imperative. | New API design. |
| Automated ACME renewal playbooks | No. | Integration with ACME clients + privilege model. |
| gRPC orchestrator | No. PHP shared-hosting stack. | Language/runtime change. |
| Feature flags | No. `.env` booleans only. | New flagging system. |
| `confidence_score` on diagnoses | No. Events are deterministic. | Calibration problem. |

### a.3 What the proposal correctly identifies as existing capabilities

- Certificate fetching with SNI.
- Immutable snapshot storage (append-only `cert_snapshots`).
- Fingerprint-based change detection (renewal vs. rotation heuristic in `Worker::checkOne()`).
- Expiry warnings with configurable thresholds.
- JSON-structured event metadata (`meta_json`).
- Hostname identity validation.
- Trust chain validation.

### a.4 Data model alignment

The proposal's `cert_snapshot` schema:
```
id, host, probe_region, timestamp, raw_json, parsed: {
  not_before, not_after, issuer, subject, SANs[],
  serial, sig_algo, pubkey_bits, ocsp_status, crl_status
}
```

The existing `cert_snapshots` table:
```
id, monitor_id, fetched_at, serial, fingerprint_sha256,
issuer_cn, subject_cn, valid_from, valid_to, raw_pem,
status, error, days_remaining
```

**Missing from existing schema:** `probe_region`, `SANs[]`, `sig_algo`, `pubkey_bits`, `ocsp_status`, `crl_status`, `raw_json` (exists as `raw_pem`, different format expectation).

**Missing from proposal:** `fingerprint_sha256` (the primary change-detection key in the existing system), `status` (the computed expiry classification), `days_remaining`, `error`, and the relationship to `monitor_id` (the proposal uses `host` as identifier, losing multi-port and user-ownership semantics).

### a.5 Behavioral observations from code

- **Change confirmation sampling** (`Worker::confirmChange()`): re-fetches N times with 2-second delays to handle load-balancer endpoint rotation. This is a false-positive mitigation mechanism absent from the proposal.
- **Denormalized "last known" fields** on the `monitors` table: 15 cached columns updated on every check for fast dashboard queries. The proposal does not address read-path performance for the UI.
- **Event deduplication** is implemented per-event-type with fingerprint + classification comparison (`shouldEmitPinMismatchEvent`, hostname/trust category change detection in `checkOne`). The proposal's alert model does not describe deduplication.
- **Notification outbox** with reliable delivery, retry backoff, and dedupe keys. The proposal's "automated actions / playbooks" section does not account for delivery reliability.

---

## (b) Inferences / hypotheses

### b.1 The proposal is a greenfield design, not an evolution of Certisent

The proposed architecture shares thematic overlap with Certisent (TLS certificate monitoring) but is architecturally incompatible with the existing codebase in nearly every dimension:

- **Language/runtime**: PHP on shared hosting vs. Go/Python services with gRPC.
- **Storage**: MySQL vs. Postgres + InfluxDB + Pinecone.
- **Execution model**: synchronous cron worker vs. distributed probe fleet + async orchestrator + LLM inference pipeline.
- **API paradigm**: imperative check-trigger vs. declarative intent-based retrieval.

Implementing this proposal would not extend Certisent; it would replace it. The "3-step rollout" section implies incremental adoption, but step 1 alone (instrument probes + store snapshots in Postgres + implement deterministic checks) requires rewriting the fetch layer, storage layer, and event layer from scratch.

### b.2 The vector DB and LLM layer solve a problem the codebase does not have

Certisent's failure classification is deterministic and exhaustive for its scope:
- Expired? Compare `valid_to` to `now`.
- Wrong host? Match SANs/CN against monitored host.
- Self-signed? Classify OpenSSL error codes.
- Untrusted chain? Classify curl/stream verification failure.
- Key changed? Compare SPKI SHA256 to pinned value.
- Renewed vs. rotated? Compare validity date progression.

These checks produce unambiguous boolean or enum results. Adding an LLM to "explain why a cert is failing" when the deterministic check already emits `tls_self_signed` with an error string is overhead without informational gain. The LLM adds value only when:
1. The failure mode is novel or ambiguous (outside current classification).
2. The remediation requires contextual knowledge (e.g., "this issuer was distrusted by Mozilla on date X").
3. Cross-host correlation is needed ("3 of your 50 hosts have the same expiring CA").

None of these use cases are articulated with enough specificity to validate the LLM integration cost.

### b.3 OCSP/CRL is the highest-value missing capability, and it does not require an LLM

The codebase has no revocation checking. This is a genuine gap. Adding OCSP stapling detection + OCSP responder queries + CRL distribution point parsing would meaningfully improve detection coverage. This can be implemented deterministically in the existing PHP stack using `openssl_x509_parse()` (which already returns `extensions` containing OCSP and CRL URIs) plus curl-based OCSP queries. It does not require Prometheus, Pinecone, or an LLM.

### b.4 Multi-region probing is valuable but orthogonal to the LLM layer

Detecting region-specific certificate failures (CDN misconfigurations, geo-targeted certificate pinning, split-horizon DNS) is a real operational need. However, the proposal conflates two independent concerns:
1. **Distributed probing** (run the same `CertFetcher::fetch()` logic from multiple locations).
2. **AI-powered reasoning** (embed cert metadata, retrieve similar failures, generate natural-language explanations).

These can be designed and shipped independently. The probe fleet is useful without the LLM. The LLM is not useful without a large corpus of diverse failure data to reason over.

### b.5 The "3-step rollout" underestimates migration cost

- **Step 1** ("instrument probes + store in Postgres + deterministic checks") is approximately a full rewrite of `CertFetcher`, `TlsValidator`, `Worker`, `MonitorService`, the entire schema, and the API layer. In a different language. With a different database.
- **Step 2** ("add TSDB + vector embeddings") layers two new infrastructure dependencies.
- **Step 3** ("retrieval-native model layer + declarative API + playbooks behind feature flags") adds an LLM inference service, a retrieval orchestrator, and an action execution framework.

Each step is a standalone project. The phasing implies a linear progression, but step 1 is already a breaking change from the existing system.

### b.6 The proposal's data model loses operational semantics the codebase has

- **`monitor_id` vs. `host`**: Certisent tracks per-user monitors at specific host:port pairs with per-monitor settings (frequency, thresholds, validation mode, pinning). The proposal's snapshot keyed by `host` loses multi-user ownership, per-monitor configuration, and port differentiation.
- **`fingerprint_sha256` omitted from proposed schema**: This is the primary key for change detection, renewal classification, and deduplication in the existing system. Its absence suggests the proposal was written without inspecting the codebase.
- **No `status` or `days_remaining`**: The existing system computes and persists these for fast dashboard rendering. The proposal's data model would require recomputation on every read.

### b.7 Confidence scores on LLM outputs are uncalibrated without ground truth

The proposal specifies `confidence_score` in the output schema. Confidence calibration requires labeled data (known correct diagnoses). The codebase has no labeled dataset. Events are deterministic outputs, not predictions. Building calibrated confidence into an LLM diagnosis layer without validation data will produce numbers that look authoritative but have no empirical basis.

---

## (c) Recommendations

### c.1 Design protocol

If the goal is to evolve Certisent incrementally toward the capabilities described in the proposal, the following protocol avoids a rewrite:

1. **Extend, don't replace, the existing snapshot schema.** Add columns for `sig_algo`, `pubkey_bits`, `sans_json` (JSON array), `chain_pem` (MEDIUMTEXT, full chain), `negotiated_protocol`, `negotiated_cipher`. These are extractable from OpenSSL without changing the fetch architecture.

2. **Add OCSP/CRL checking as a new validation mode** alongside hostname/trust/pinning. Parse `authorityInfoAccess` and `crlDistributionPoints` from `openssl_x509_parse()` extensions. Query OCSP responders via curl. Store `ocsp_status` and `crl_status` as new columns on `cert_snapshots` and denormalized on `monitors`.

3. **Introduce probe identity** before multi-region probing. Add a `probe_id` and `probe_region` column to `cert_snapshots`. The existing single-server worker becomes probe `default/local`. Additional probes can be remote workers hitting the same `POST /api/v1/worker/run` endpoint with a probe identifier header.

4. **Do not add Postgres, InfluxDB, or a vector DB** until the MySQL-backed system demonstrably cannot handle the query patterns. The existing `cert_snapshots` table with `(monitor_id, fetched_at)` indexing supports time-range queries. Prometheus-style metrics can be derived at read time or via scheduled aggregation into a `metrics_daily` summary table.

5. **Do not add an LLM layer** until there is a concrete, testable specification for what it would produce that deterministic logic cannot. "Explain why cert failing" is answered today by the event type + meta_json. Define the delta.

### c.2 Critique of the draft

| Issue | Severity | Detail |
|-|-|-|
| **Assumes greenfield; ignores existing system** | High | The proposal does not reference Certisent's existing tables, code, or deployment model. It would be viable as a new-product spec. As an evolution plan for this codebase, it is disconnected. |
| **OCSP/CRL listed but not designed** | Medium | The proposal mentions `ocsp_status` and `crl_status` as schema fields but provides no protocol detail: which OCSP responder? Stapled or fetched? What if the responder is unreachable? What timeout? The existing codebase already handles timeout and probe-error classification for trust validation — OCSP/CRL needs the same rigor. |
| **Vector DB introduced without retrieval specification** | High | "Store embedding of certificate metadata + textual reasoning for semantic retrieval" — what query patterns require semantic similarity over structured fields? Certificate metadata is highly structured (dates, hostnames, fingerprints, enum statuses). Vector search is useful for unstructured or high-dimensional data. No example query is given that wouldn't be better served by a SQL `WHERE` clause. |
| **LLM "causal reasoning" is unspecified** | High | "Run causal reasoning over recent changes (e.g., new issuer, SAN changes, partial revocation)" — this is a SQL diff query over two snapshots. The existing `Worker::checkOne()` already computes fingerprint diffs and classifies changes. What causal claim would the LLM make that the deterministic diff does not? |
| **gRPC in a PHP shared-hosting ecosystem** | Medium | The existing codebase is designed for environments where `composer` may not be available. Introducing gRPC requires a language change, a build system, container infrastructure, and service discovery. This is not noted as a breaking constraint. |
| **"Automated ACME renewal" crosses a privilege boundary** | High | Certisent is an observer — it connects to endpoints and reports what it sees. Issuing ACME certificate renewals requires write access to DNS or HTTP challenge endpoints, private key management, and integration with the target's deployment pipeline. This is a fundamentally different trust model. The proposal does not address key custody, authorization, or blast radius. |
| **Feature flags mentioned without implementation path** | Low | "Enable automated playbooks behind feature flags." The codebase uses `.env` booleans for feature toggling. A feature-flag system (LaunchDarkly-style or even a simple DB table) is not present. This is a minor gap but indicative of under-specified rollout mechanics. |
| **No alert deduplication design** | Medium | The existing system has event-level deduplication (e.g., `shouldEmitPinMismatchEvent`, trust category change gating). The proposal's alert model (`snapshot_id, alert_type, severity, rationale_ids, remediation_action`) does not describe how duplicate alerts are suppressed across probes, time windows, or repeated checks. Multi-region probing will multiply this problem. |

### c.3 Plan (if proceeding incrementally)

**Phase 1: Enrich snapshot data (no new infrastructure)**
- Add `sig_algo`, `pubkey_bits`, `sans_json`, `chain_pem` columns to `cert_snapshots`.
- Extract these fields in `Worker::checkOne()` from `openssl_x509_parse()` output.
- Add `negotiated_protocol`, `negotiated_cipher` if obtainable from PHP stream metadata.
- Migration: append-only ALTER TABLE, backward-compatible.

**Phase 2: OCSP/CRL validation**
- Parse `authorityInfoAccess` and `crlDistributionPoints` from certificate extensions.
- Implement OCSP query via curl (HTTP POST to OCSP responder URI).
- Implement CRL fetch + serial lookup (curl + OpenSSL).
- Add `ocsp_status`, `crl_status` to `cert_snapshots` and denormalized on `monitors`.
- New event types: `cert_revoked_ocsp`, `cert_revoked_crl`, `ocsp_unavailable`.
- Timeouts and probe-error handling modeled after `TlsValidator::validateTrust()`.

**Phase 3: Multi-region probe support**
- Add `probe_id`, `probe_region` to `cert_snapshots`.
- Define probe registration and heartbeat protocol (new `probes` table or via `system_state`).
- Remote probes authenticate via existing API key system (`POST /api/v1/check` with probe identity).
- Dashboard aggregation: show per-region status, flag region-specific failures.
- Deduplication: events emit only when majority of probes agree on a state change.

**Phase 4: Structured assessment API (optional, no LLM)**
- Add `GET /api/v1/assess?host=&lookback_days=` that returns:
  - Snapshot history (structured JSON, no embedding).
  - Deterministic check results (expiry, hostname, trust, OCSP, pinning).
  - Diff against previous snapshot.
  - Suggested remediations as static rule-based mappings (e.g., `tls_self_signed` -> "Install a certificate from a trusted CA" with documentation links).
- This delivers the "declarative intent" API value without an LLM.

**Phase 5: Evaluate LLM integration (only after phases 1-4)**
- With enriched data, multi-region probing, and OCSP/CRL, assess whether remaining unexplained failure modes justify LLM integration.
- If yes: use a structured function-calling interface where the model receives JSON blobs (snapshot diffs, check results) and returns constrained JSON output. No vector DB needed if the retrieval is structured SQL over indexed tables.
- If no: the rule-based assessment API from phase 4 is sufficient.

### c.4 Options comparison

| Approach | Pros | Cons |
|-|-|-|
| **A. Implement proposal as written** | Addresses all stated goals. Modern architecture. | Complete rewrite. New language, runtime, 3+ new infra dependencies. Destroys shared-hosting compatibility. No automated tests to catch regressions. Months of work before parity with current functionality. |
| **B. Incremental extension (phases 1-5 above)** | Preserves existing functionality. Each phase is independently shippable. No new infrastructure until justified. Maintains deployment simplicity. | Does not achieve multi-service architecture. LLM integration deferred or dropped. PHP limitations on concurrent probing and cipher introspection. |
| **C. Hybrid: rewrite probe/ingest in Go, keep PHP UI** | Unlocks cipher/protocol introspection, concurrent probing, and OCSP/CRL via Go's `crypto/tls`. Keeps the working UI and user management. | Two codebases to maintain. Requires shared DB access or internal API. Deployment complexity increases. |
| **D. Adopt existing open-source (e.g., Certspotter, crt.sh, ssl-cert-check) and extend** | Faster time-to-capability for CT log monitoring, OCSP, multi-host. | Certisent's differentiators (per-user monitors, immutable snapshots, event timeline, notification outbox, SSRF policy) would need to be re-implemented or abandoned. |

**Recommendation:** Option B for the immediate term, with a clear evaluation gate at phase 4 for whether to proceed to option C or remain PHP-only. Option A is not recommended because the cost-to-benefit ratio is unfavorable given the existing system's coverage of core detection cases.

---

## Self-critique

### Failure modes

1. **OCSP/CRL in PHP may be impractical.** PHP's `openssl_x509_parse()` returns certificate extensions as strings, not structured ASN.1. Parsing `authorityInfoAccess` to extract the OCSP responder URI requires string manipulation on a format that varies by CA. Constructing an OCSP request in PHP without an external library (the codebase has zero dependencies) requires manual DER encoding of the OCSP request structure. This may be fragile enough to justify a Go sidecar (option C) earlier than phase 3.

2. **Multi-region probing via `POST /api/v1/check` is rate-limited.** The existing API enforces per-IP and per-token rate limits (default 600/min per IP, 1200/min per token). A probe fleet checking hundreds of monitors from multiple regions will hit these limits. The rate limiter (`RateLimiter.php`) would need probe-identity-aware exemptions or separate quotas, which is not in the current design.

3. **The incremental plan assumes MySQL can handle the snapshot volume.** If monitoring scales to thousands of hosts checked every 5 minutes across multiple probes, `cert_snapshots` will grow at ~288K rows/day (1000 hosts x 12 checks/hour x 24 hours). With `raw_pem` (MEDIUMTEXT) per row, this becomes a storage and query-performance problem within months. The plan does not include a retention/archival strategy.

### Tests / falsifications

1. **Test: Can `openssl_x509_parse()` reliably extract OCSP responder URIs across major CAs?** Collect 100 leaf certificates from Let's Encrypt, DigiCert, Sectigo, GlobalSign, and AWS Certificate Manager. Parse each with `openssl_x509_parse()` in PHP 8. If >10% produce unparseable `authorityInfoAccess` strings, the PHP-native OCSP path is not viable and option C (Go sidecar) is required earlier.

2. **Test: Does the existing event deduplication logic produce false negatives under multi-probe writes?** Simulate two probes writing snapshots for the same monitor within the same cron cycle. Trace whether `Worker::checkOne()` correctly deduplicates events when two snapshots arrive with different `fetched_at` but the same fingerprint. The current code reads `MonitorService::getLatestSnapshot()` — if two probes race, the "previous" snapshot may be the other probe's write, not the chronologically prior check. This would suppress legitimate change events.

### Change case

1. **If the deployment target shifts from shared hosting to containerized infrastructure** (e.g., the team decides Certisent should run on Kubernetes), the calculus changes significantly. Container orchestration makes option C (Go probe sidecar) and eventually option A (full rewrite) more viable because the deployment complexity they introduce is absorbed by the platform. In that scenario, skip phase 2's PHP-native OCSP implementation and go directly to a Go-based probe service that handles fetching, OCSP/CRL, and cipher introspection, writing results to the existing MySQL schema via the API. The PHP monolith becomes the UI + API gateway, and the Go service becomes the data-collection plane.
