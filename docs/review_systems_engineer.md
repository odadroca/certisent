# Certisent v0.7.6 — Systems Engineer Feature Review

**Date**: 2026-02-07
**Scope**: Feature-by-feature analysis of current value and unrealized potential
**Perspective**: Production systems engineering (infrastructure, reliability, operations)

---

## 1. Certificate Monitoring (Core)

### How it helps

- Live-fetches the actual leaf certificate via TLS handshake with SNI, so what you see is what the client sees. This is the right approach — monitoring from the outside removes assumptions about config management correctness.
- Immutable `cert_snapshots` table creates a forensic record of every observation. During an incident you can answer "what certificate was served at time T?" without relying on access logs or memory.
- Per-monitor check frequency avoids one-size-fits-all polling. A payment gateway can be checked every 5 minutes while a docs site gets checked hourly.

### How it could help more

- **Multi-vantage-point probing**: A single observer node misses geo-routed or CDN-specific certificate issues. Supporting distributed probes (even just two — primary + secondary) that compare results would catch split-brain TLS configs where a CDN edge node serves a stale cert.
- **Full chain capture**: Currently only the leaf certificate is stored. Intermediate chain issues (expired intermediates, cross-sign transitions) are a common class of outage. Capturing `peer_certificate_chain` from the stream context and storing the chain fingerprint would detect intermediate rotation without requiring trust validation to be turned on.
- **Port scanning / protocol awareness**: Certificate monitoring is limited to raw TLS on a single port. STARTTLS protocols (SMTP/587, IMAP/993, PostgreSQL/5432) require protocol-level negotiation before the TLS handshake. Adding STARTTLS support would make Certisent useful for mail and database infrastructure, not just HTTPS endpoints.
- **Certificate Transparency log cross-referencing**: When a new fingerprint appears, querying CT logs for the same domain could confirm whether the certificate was legitimately issued or is a potential misissuance indicator.

---

## 2. Change Detection & Confirmation Sampling

### How it helps

- Fingerprint comparison against the previous snapshot is the simplest reliable method for detecting rotation. The multi-sample confirmation (`TLS_SAMPLES_ON_CHANGE`, default 2) is a smart mitigation for load-balanced endpoints where different backends may serve different certificates during a rollout window.
- Distinguishing renewal (validity period extended) from arbitrary change (fingerprint changed without expected validity progression) is operationally meaningful — renewals are expected; unexpected changes are incidents.
- The `changed_unstable` event type for unconfirmed changes avoids false positive alerting on flapping endpoints while still recording the observation.

### How it could help more

- **Configurable confirmation per monitor**: The sample count is a global env var. Load-balanced endpoints behind Cloudflare may need 5 samples; a single-origin server needs 1. Making `TLS_SAMPLES_ON_CHANGE` a per-monitor setting in `monitor_settings` would reduce unnecessary re-fetch overhead on simple endpoints and increase confidence on complex ones.
- **Gradual rollout detection**: When an endpoint is behind a load balancer performing a certificate rollout, Certisent currently sees it as either confirmed-changed or unstable. Tracking the ratio of old-to-new fingerprints across samples over multiple check cycles would give operators visibility into rollout progress (e.g., "3/5 backends have the new cert").
- **Issuer change alerting**: Currently issuer changes are implicit in fingerprint changes. A dedicated `issuer_changed` event when the issuing CA changes (Let's Encrypt to DigiCert, or vice versa) would flag potential supply chain or procurement changes that need operator awareness.

---

## 3. TLS Validation (Hostname, Trust Chain, Pinning)

### How it helps

- The separation of observation (CertFetcher with `verify_peer=false`) from validation (TlsValidator as opt-in) is architecturally sound. It means Certisent can monitor endpoints that intentionally use self-signed certs without false alarms, while operators who want trust enforcement can enable it progressively.
- The three-tier mode (off / observe / enforce) per monitor enables gradual rollout. You can enable observe to understand your baseline before switching to enforce and having events fire.
- SPKI pinning (v0.7.6) provides defense against CA compromise or misissuance. The dedupe logic in `shouldEmitPinMismatchEvent` prevents event storms on every check cycle.

### How it could help more

- **Certificate expiry of intermediate/root CAs**: Trust validation checks the chain against the system CA bundle, but doesn't warn when an intermediate or root CA in the chain is itself approaching expiry. Intermediate CA expiry has caused widespread outages (the 2020 AddTrust root expiry broke large swaths of the internet).
- **OCSP/CRL revocation checking**: A certificate can be validly signed but revoked. OCSP stapling status or CRL checks would catch certificates that have been revoked due to key compromise. This is a meaningful gap for security-sensitive deployments.
- **Pin rotation workflow**: Pinning is currently a single SPKI hash. In practice, operators need to pin both the current and next key to enable zero-downtime rotation. Supporting a `pin_spki_sha256_backup` field (or a comma-separated list of acceptable pins) would align with the HPKP model and prevent self-inflicted pin mismatch events during planned key rotation.
- **TLS version and cipher suite reporting**: The current fetch captures the certificate but not the negotiated protocol version or cipher suite. Logging `TLSv1.2` vs `TLSv1.3` and the cipher suite per snapshot would help operators track TLS hardening progress across their estate and detect downgrades.

---

## 4. Notification System (Email, Slack, Teams)

### How it helps

- The queued outbox with retry + exponential backoff (up to 5 attempts, capping at 1 hour) is the correct pattern for reliable delivery. Notifications that fail on a transient SMTP issue will be retried rather than silently dropped.
- SHA-256 dedupe keys prevent duplicate notifications for the same event, which is critical for operators managing noisy environments.
- Webhook URL sanitization in error messages (`sanitizeError`) prevents leaking secrets into the database.

### How it could help more

- **PagerDuty / OpsGenie / generic webhook integration**: Slack and Teams cover chat-ops, but incident management platforms (PagerDuty, OpsGenie, Grafana OnCall) are where on-call routing actually happens. A generic webhook template with configurable payload format would cover these without building per-provider integrations.
- **Notification escalation**: Currently all events for a monitor go to the monitor owner. For critical events (expired cert, trust failure), it would be valuable to escalate to a secondary contact or a team channel if the primary notification isn't acknowledged within N minutes. Even a simple "also notify admins on critical severity" flag would help.
- **Notification suppression / maintenance windows**: There's no way to suppress notifications during a planned maintenance window (e.g., certificate replacement). An operator performing a planned rotation will receive change events. A per-monitor "mute until datetime" field would prevent alert fatigue during known operations.
- **Digest / summary notifications**: Operators with many monitors receive one notification per event. A daily or weekly digest summarizing the certificate estate health (N monitors OK, N expiring within 30d, N with trust issues) would serve management and compliance audiences without per-event noise.

---

## 5. Worker / Cron System

### How it helps

- The cron-driven, stateless architecture is a deliberate and good design choice for the target deployment environment (shared hosting / VPS). No daemon to crash, no PID files to manage, no systemd units to configure.
- Time-boxed batch processing (`maxSeconds` in `processJobs`) prevents cron overlap — a batch that takes too long won't stack up with the next invocation.
- The `cronHealthCheck` method that fires a `cron_failed` critical event if no successful run in 12 hours is a useful self-monitoring mechanism.

### How it could help more

- **Parallelism within a batch**: Currently `checkOne` runs sequentially within a batch. For large monitor counts, the TLS connect timeout (7s default) serialized across hundreds of monitors creates a long tail. Even limited parallelism (e.g., using `curl_multi` for the TLS handshakes) would dramatically reduce batch duration.
- **Staggered scheduling**: All monitors with the same frequency are checked in the same batch. If 200 monitors are all set to 60-minute frequency and were created around the same time, they all come due simultaneously. Adding jitter (a random offset per monitor within its frequency window) would smooth the load curve.
- **Worker locking**: There's no explicit lock preventing two cron invocations from running `--due` simultaneously. On hosting environments with overlapping cron execution (or manual API triggers), two workers could check the same monitor concurrently. An advisory lock (MySQL `GET_LOCK`) would be a lightweight safeguard.
- **Dead-letter queue for permanently failing monitors**: Monitors that fail N consecutive checks (e.g., DNS resolution permanently broken for a decommissioned host) continue consuming check slots. Auto-disabling monitors after a configurable consecutive failure count (with an event and notification) would reduce wasted work.

---

## 6. SSRF Protection

### How it helps

- The three-mode design (legacy / public_only / allowlist_private) with progressive hardening matches real operational patterns. Operators can start in legacy mode and tighten as they inventory their targets.
- DNS resolution to IP before policy evaluation prevents DNS rebinding attacks where a hostname resolves to a private IP after the policy check.
- IPv4 and IPv6 private range coverage is comprehensive (including CGN 100.64/10, documentation ranges, multicast).

### How it could help more

- **DNS rebinding protection via double-resolution**: While the current code resolves DNS and then checks, there's a TOCTOU gap: the hostname is resolved again when `stream_socket_client` connects. An attacker controlling DNS could return a public IP for the policy check and a private IP for the actual connection. Passing the resolved IP directly to the socket (via the `tcp://` wrapper with an explicit IP and SNI override) would close this gap.
- **Request logging for blocked attempts**: SSRF blocks are returned as errors but not logged to the audit trail. Logging blocked SSRF attempts (with source user/IP context) to the audit log would provide visibility into potential abuse attempts.

---

## 7. Rate Limiting

### How it helps

- DB-backed fixed-window rate limiting with FOR UPDATE row locking is correct for a shared-hosting environment where in-memory stores (Redis) may not be available.
- The non-breaking fallback (if the `rate_limits` table is missing, allow everything) ensures rate limiting can be deployed as a non-breaking upgrade.
- Separate scopes for login (per-IP) and API (per-IP + per-token) allow fine-grained control.

### How it could help more

- **Sliding window instead of fixed window**: The current fixed-window implementation has a known edge case: a burst at the end of one window plus a burst at the start of the next allows 2x the limit within a short timespan. A sliding window or token bucket algorithm would provide smoother rate enforcement.
- **Account lockout notification**: When a login rate limit is triggered, there's no notification to the account owner or admin. A single "excessive login attempts for IP X" event logged to the audit trail would flag brute-force activity.
- **Per-user API rate limiting**: Rate limits are per-IP and per-token, but not per-user. A user with multiple API tokens can multiply their effective rate by using different tokens from the same session. Adding a per-user aggregate limit would close this gap.

---

## 8. API & Automation

### How it helps

- The REST API with scoped Bearer tokens enables integration with external orchestration (CI/CD pipelines, infrastructure-as-code, monitoring aggregators).
- The `/check` endpoint with stateless quick-check mode is valuable for ad-hoc validation (e.g., checking a cert before deploying a config change).
- User-scoped API keys (v0.5.6) with monitor ownership enforcement prevent a compromised viewer token from triggering checks on monitors they don't own.

### How it could help more

- **GET /monitors and GET /events endpoints**: The API currently only supports write operations (trigger checks, run worker). There's no read API for listing monitors, retrieving events, or exporting snapshots. This means external dashboards (Grafana, custom UIs) can't consume Certisent data without direct DB access.
- **Webhook callback registration**: Instead of polling for events, an API endpoint to register a callback URL that receives event payloads in real-time would enable event-driven integrations without polling overhead.
- **Prometheus / OpenMetrics exposition**: For operators already running Prometheus, a `/metrics` endpoint exposing `certisent_monitor_days_remaining{host="...",port="..."}`, `certisent_monitor_last_check_epoch`, `certisent_events_total{type="..."}` would allow native integration with existing alerting and graphing infrastructure. This is arguably the single highest-value addition for infrastructure teams.
- **Bulk monitor management**: Creating 50 monitors currently requires 50 individual form submissions or API calls. A bulk import endpoint (CSV or JSON array) would enable infrastructure-as-code workflows where the monitor list is generated from service discovery or Terraform outputs.

---

## 9. Audit Log

### How it helps

- Logging user actions (monitor CRUD, login, settings changes) with IP, User-Agent, and structured JSON meta provides an accountability trail for compliance.
- The admin-facing audit log viewer enables retrospective investigation without direct DB queries.

### How it could help more

- **Audit log retention policy**: The audit log grows indefinitely. A configurable retention period with automatic cleanup (e.g., `AUDIT_RETENTION_DAYS=365`) would prevent unbounded table growth on long-running instances.
- **Audit log export**: For compliance workflows (SOC 2, ISO 27001), operators need to export audit logs to external systems (SIEM, S3 archival). An API endpoint or CLI command to export audit records in JSON-lines format would support this.
- **System-level audit entries**: Currently only user-initiated actions are logged. System events (worker runs, configuration changes, schema migrations) don't appear in the audit log. Adding system-level entries would provide a complete operational timeline.

---

## 10. Localization (i18n)

### How it helps

- The i18n framework with per-user locale preferences enables multi-language deployments without code changes.
- The `i18n_audit.php` tool that scans for missing translation keys is a practical development aid.

### How it could help more

- **Notification language**: Notifications currently use the user's UI locale, which is correct. But webhook payloads (Slack/Teams) might go to shared channels where the team language differs from the monitor owner's preference. A per-channel locale override would handle this edge case.
- **Locale-aware certificate field display**: Certificate subject/issuer fields can contain non-ASCII characters (particularly in organization names). Ensuring these are displayed correctly regardless of the user's locale setting is a data integrity concern.

---

## 11. Security Hardening (Headers, Sessions, CSRF)

### How it helps

- The security header baseline (CSP, HSTS, X-Frame-Options, Referrer-Policy) is solid for a server-rendered PHP application. CSP in report-only by default avoids breaking the UI while still enabling operators to tighten incrementally.
- CSRF on all POST forms (including logout) prevents cross-site request forgery.
- Session hardening with SameSite, HttpOnly, and Secure flags follows current best practices.

### How it could help more

- **Subresource Integrity (SRI)**: The frontend loads Tailwind CSS from a CDN without SRI hashes. If the CDN is compromised, arbitrary CSS (or JS if the CDN serves it) could be injected. Adding SRI `integrity` attributes to CDN-loaded resources would mitigate this.
- **Two-factor authentication (2FA/TOTP)**: For an application that guards infrastructure security information, password-only authentication is a single point of compromise. TOTP-based 2FA would meaningfully raise the bar for account takeover.
- **Session invalidation on password change**: The current session management doesn't appear to invalidate other active sessions when a password is changed. If an attacker has an active session, changing the password alone wouldn't evict them.

---

## 12. Architecture & Operational Gaps

### No automated test suite

This is the single largest risk factor. Every code change is a manual-testing liability. The procedural + service-class architecture would test well with PHPUnit:
- `TlsValidator::validateHostname()` and `dnsNameMatches()` are pure functions ideal for unit tests
- `SsrfPolicy::isPrivateOrReservedIp()` and `cidrMatch()` are pure functions
- The notification dedupe logic, rate limiter window calculations, and event emission conditions are all testable

### No health check for the notification pipeline

The worker checks for cron staleness (`cronHealthCheck`), but there's no equivalent for the notification outbox. If the outbox accumulates hundreds of unsent notifications (e.g., SMTP credentials expired), there's no proactive alert to the admin. A `notificationHealthCheck` that fires a critical event when pending outbox entries exceed a threshold would close this gap.

### No data retention / cleanup

Tables grow indefinitely: `cert_snapshots`, `events`, `notification_outbox` (completed entries), `rate_limits` (stale entries). On a long-running instance with many monitors, this will eventually cause performance degradation. A configurable retention policy with a cleanup task in the worker would address this.

### No backup/export facility

There's no built-in way to export monitor configuration, snapshots, or events. For disaster recovery or migration, operators must rely on `mysqldump`. A CLI export command producing a portable format would improve operational resilience.

---

## Summary: Top 5 High-Impact Improvements

| Priority | Improvement | Effort | Impact |
|----------|-------------|--------|--------|
| 1 | **Prometheus /metrics endpoint** | Medium | Bridges Certisent into existing monitoring infrastructure |
| 2 | **Read API (GET /monitors, /events)** | Medium | Enables external dashboards and automation |
| 3 | **Automated test suite** | High | Reduces regression risk, enables CI/CD |
| 4 | **Data retention / cleanup** | Low | Prevents unbounded table growth |
| 5 | **Full chain capture** | Low-Medium | Catches intermediate CA expiry issues |
