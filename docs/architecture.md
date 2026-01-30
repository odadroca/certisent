# Architecture (v0)

## Components
- Web UI (PHP + Tailwind CDN)
- MySQL persistence
- Worker (CLI PHP script invoked by cron OR remote worker via API)
- Notification senders (email/webhooks) inline in worker
- RSS feed endpoint for events

## Data flow (simplified)
1) Worker picks due monitors
2) Fetch live cert (SNI) and parse
3) Store snapshot
4) Compare to previous snapshot:
   - fingerprint changed -> changed/renewed event (confirm sampling)
   - days_remaining <= threshold -> expiry_warning
   - fetch failure -> check_failed
5) Event is stored and notifications are sent
6) Worker writes heartbeat to `system_state`

## Key tables
- monitors / monitor_settings
- cert_snapshots (immutable historical record)
- events (alerts and lifecycle events)
- audit_log (user actions)
- system_state (heartbeat)
