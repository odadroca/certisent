# Release notes — v0.4.x patch line

This document summarizes the v0.4.x patch releases. The DB schema version remains **0.4** across all versions listed here.

## v0.4.9 (stabilization)
- No new runtime features.
- Added this consolidated release notes document.

## v0.4.8
- Improved Bearer token extraction across hosting environments by checking common server header variants and `getallheaders()` (additive compatibility).
- Added troubleshooting note to API docs.

## v0.4.7
- Added correlation IDs for worker due-check runs and job processing executions; written to server logs for troubleshooting.

## v0.4.6
- Documentation consolidation for operations clarity (Apache Authorization forwarding, versioning contract, patch upgrade expectations).
- Removed `docs/hostinger-checklist.md` from the distribution.
- Project license clarified as **Apache-2.0**.

## v0.4.5
- `/api/v1/health` can be authorized by a narrower scope (`read_health`) while still accepting `run_worker` for backward compatibility.
- Admin UI supports selecting the health scope when creating keys.

## v0.4.4
- Improved cancellation responsiveness for `run_all` jobs (cancel takes effect between monitor checks/batches; counters/status remain consistent).

## v0.4.3
- Removed N+1 DB queries in due-check scheduling (performance-only; due semantics preserved).

## v0.4.2
- Decoupled app release version from DB schema version (`schema_version()` introduced; worker schema check uses it).

## v0.4.1
- Apache: forwarded `Authorization` header to PHP to prevent `401 missing_bearer` in common deployments.
