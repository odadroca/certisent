<?php
declare(strict_types=1);

/**
 * End-to-end test bootstrap: SQLite-backed database for integration testing.
 *
 * Overrides db() to return an in-memory SQLite PDO, creates the schema
 * (adapted from MySQL to SQLite), and loads the Worker class so that
 * pruneSnapshots() and reconcileDenormalized() can be tested against
 * real rows without requiring a running MySQL server.
 */

// ── 1. Provide stubs for global functions the app expects ───────────────

// cfg() with test-controllable overrides.
$GLOBALS['__test_cfg_overrides'] = [];

function cfg(string $key, $default = null) {
    if (isset($GLOBALS['__test_cfg_overrides'][$key])) {
        return $GLOBALS['__test_cfg_overrides'][$key];
    }
    static $defaults = [
        'SNAPSHOT_RETENTION_DAYS' => 90,
        'SNAPSHOT_KEEP_PER_MONITOR' => 10,
        'ADMIN_EMAIL' => '',
        'MAIL_FROM' => 'test@example.com',
        'MAIL_FROM_NAME' => 'Test',
    ];
    return $defaults[$key] ?? $default;
}

function db_now_utc(): string {
    return gmdate('Y-m-d H:i:s');
}

function env(string $key, ?string $default = null): ?string {
    return $default;
}

// ── 2. db() singleton — SQLite in-memory ────────────────────────────────

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // Enable foreign keys in SQLite.
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

// ── 3. Create schema (SQLite-compatible) ────────────────────────────────

function e2e_create_schema(): void {
    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL DEFAULT '',
        role TEXT NOT NULL DEFAULT 'viewer',
        locale TEXT NOT NULL DEFAULT 'en',
        created_at TEXT NOT NULL,
        last_login_at TEXT NULL,
        notify_channels_json TEXT NULL,
        notify_repeat_count INTEGER NOT NULL DEFAULT 1,
        rss_token TEXT NOT NULL DEFAULT '',
        failed_login_count INTEGER NOT NULL DEFAULT 0,
        locked_until TEXT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS monitors (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        url TEXT NOT NULL,
        host TEXT NOT NULL,
        port INTEGER NOT NULL DEFAULT 443,
        enabled INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        last_checked_at TEXT NULL,
        last_status TEXT NULL,
        last_fingerprint_sha256 TEXT NULL,
        last_issuer_cn TEXT NULL,
        last_valid_from TEXT NULL,
        last_valid_to TEXT NULL,
        last_days_remaining INTEGER NULL,
        last_error TEXT NULL,
        hostname_ok INTEGER NULL,
        hostname_error TEXT NULL,
        trust_ok INTEGER NULL,
        trust_category TEXT NULL,
        trust_error TEXT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS monitor_settings (
        monitor_id INTEGER PRIMARY KEY,
        notify_days_before_expiry INTEGER NOT NULL DEFAULT 30,
        check_frequency_minutes INTEGER NOT NULL DEFAULT 60,
        notify_on_change INTEGER NOT NULL DEFAULT 1,
        notify_on_renewal INTEGER NOT NULL DEFAULT 1,
        tls_validation_mode TEXT NOT NULL DEFAULT 'off',
        pin_mode TEXT NOT NULL DEFAULT 'off',
        pin_spki_sha256 TEXT NULL,
        FOREIGN KEY (monitor_id) REFERENCES monitors(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cert_snapshots (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        monitor_id INTEGER NOT NULL,
        fetched_at TEXT NOT NULL,
        serial TEXT NULL,
        fingerprint_sha256 TEXT NULL,
        issuer_cn TEXT NULL,
        subject_cn TEXT NULL,
        valid_from TEXT NULL,
        valid_to TEXT NULL,
        raw_pem TEXT NULL,
        status TEXT NOT NULL DEFAULT 'ok',
        error TEXT NULL,
        days_remaining INTEGER NULL,
        FOREIGN KEY (monitor_id) REFERENCES monitors(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_snapshots_monitor_time ON cert_snapshots(monitor_id, fetched_at)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        monitor_id INTEGER NULL,
        type TEXT NOT NULL,
        severity TEXT NOT NULL DEFAULT 'info',
        message TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL,
        meta_json TEXT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS system_state (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL DEFAULT '',
        updated_at TEXT NOT NULL
    )");
}

// ── 4. Seed helpers ─────────────────────────────────────────────────────

function e2e_insert_user(string $email = 'test@example.com'): int {
    $now = db_now_utc();
    $st = db()->prepare("INSERT INTO users (email, password_hash, role, created_at, rss_token)
                          VALUES (:e, 'hash', 'admin', :c, 'rss')");
    $st->execute([':e' => $email, ':c' => $now]);
    return (int)db()->lastInsertId();
}

function e2e_insert_monitor(int $userId, string $host = 'example.com'): int {
    $now = db_now_utc();
    $st = db()->prepare("INSERT INTO monitors (user_id, url, host, port, enabled, created_at, updated_at)
                          VALUES (:uid, :url, :host, 443, 1, :c, :u)");
    $st->execute([':uid' => $userId, ':url' => 'https://' . $host . '/', ':host' => $host, ':c' => $now, ':u' => $now]);
    $mid = (int)db()->lastInsertId();

    $st2 = db()->prepare("INSERT INTO monitor_settings (monitor_id) VALUES (:mid)");
    $st2->execute([':mid' => $mid]);
    return $mid;
}

function e2e_insert_snapshot(int $monitorId, string $fetchedAt, string $fp = 'abc', string $status = 'ok', int $daysRemaining = 60): int {
    $st = db()->prepare("INSERT INTO cert_snapshots (monitor_id, fetched_at, fingerprint_sha256, issuer_cn, subject_cn, valid_from, valid_to, status, days_remaining)
                          VALUES (:mid, :fat, :fp, 'TestCA', 'example.com', '2025-01-01 00:00:00', '2026-01-01 00:00:00', :st, :dr)");
    $st->execute([':mid' => $monitorId, ':fat' => $fetchedAt, ':fp' => $fp, ':st' => $status, ':dr' => $daysRemaining]);
    return (int)db()->lastInsertId();
}

// ── 5. Reset database between tests ─────────────────────────────────────

function e2e_reset(): void {
    $pdo = db();
    $pdo->exec("DELETE FROM cert_snapshots");
    $pdo->exec("DELETE FROM events");
    $pdo->exec("DELETE FROM monitor_settings");
    $pdo->exec("DELETE FROM monitors");
    $pdo->exec("DELETE FROM users");
    $pdo->exec("DELETE FROM system_state");
    $GLOBALS['__test_cfg_overrides'] = [];
}
