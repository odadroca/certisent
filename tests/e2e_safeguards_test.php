<?php
declare(strict_types=1);

/**
 * End-to-end tests for the v0.7.7 safeguards.
 *
 * Uses SQLite in-memory to exercise Worker::pruneSnapshots() and
 * Worker::reconcileDenormalized() with real data and real SQL.
 *
 * Loaded by run_e2e.php (separate from unit tests which need no DB).
 */

// ──────────────────────────────────────────────────────────────────────
// PRUNE SNAPSHOTS
// ──────────────────────────────────────────────────────────────────────

echo "  [pruneSnapshots] disabled when SNAPSHOT_RETENTION_DAYS=0\n";
e2e_reset();
$GLOBALS['__test_cfg_overrides']['SNAPSHOT_RETENTION_DAYS'] = 0;
$uid = e2e_insert_user();
$mid = e2e_insert_monitor($uid);
for ($i = 0; $i < 20; $i++) {
    e2e_insert_snapshot($mid, gmdate('Y-m-d H:i:s', time() - (200 - $i) * 86400), 'fp' . $i);
}
$r = Worker::pruneSnapshots();
assert_eq(0, $r['deleted'], 'pruneSnapshots: disabled=0 deletes nothing');

// ──────────────────────────────────────────────────────────────────────

echo "  [pruneSnapshots] keeps at least SNAPSHOT_KEEP_PER_MONITOR newest snapshots\n";
e2e_reset();
$GLOBALS['__test_cfg_overrides']['SNAPSHOT_RETENTION_DAYS'] = 30;
$GLOBALS['__test_cfg_overrides']['SNAPSHOT_KEEP_PER_MONITOR'] = 5;
$uid = e2e_insert_user();
$mid = e2e_insert_monitor($uid);

// Insert 10 snapshots: 5 older than 30 days, 5 newer.
for ($i = 0; $i < 5; $i++) {
    e2e_insert_snapshot($mid, gmdate('Y-m-d H:i:s', time() - (60 - $i) * 86400), 'old_fp' . $i);
}
for ($i = 0; $i < 5; $i++) {
    e2e_insert_snapshot($mid, gmdate('Y-m-d H:i:s', time() - (10 - $i) * 86400), 'new_fp' . $i);
}
$r = Worker::pruneSnapshots();
assert_eq(5, $r['deleted'], 'pruneSnapshots: deletes old beyond retention');
// Verify the 5 newest remain.
$st = db()->prepare("SELECT COUNT(*) AS c FROM cert_snapshots WHERE monitor_id = :mid");
$st->execute([':mid' => $mid]);
assert_eq(5, (int)$st->fetch()['c'], 'pruneSnapshots: 5 newest remain');

// ──────────────────────────────────────────────────────────────────────

echo "  [pruneSnapshots] never drops below KEEP_PER_MONITOR even if all are old\n";
e2e_reset();
$GLOBALS['__test_cfg_overrides']['SNAPSHOT_RETENTION_DAYS'] = 30;
$GLOBALS['__test_cfg_overrides']['SNAPSHOT_KEEP_PER_MONITOR'] = 3;
$uid = e2e_insert_user();
$mid = e2e_insert_monitor($uid);

// 5 snapshots, all older than 30 days.
for ($i = 0; $i < 5; $i++) {
    e2e_insert_snapshot($mid, gmdate('Y-m-d H:i:s', time() - (100 - $i) * 86400), 'fpx' . $i);
}
$r = Worker::pruneSnapshots();
assert_eq(2, $r['deleted'], 'pruneSnapshots: deletes 2, keeps floor of 3');
$st = db()->prepare("SELECT COUNT(*) AS c FROM cert_snapshots WHERE monitor_id = :mid");
$st->execute([':mid' => $mid]);
assert_eq(3, (int)$st->fetch()['c'], 'pruneSnapshots: exactly 3 remain');

// ──────────────────────────────────────────────────────────────────────

echo "  [pruneSnapshots] skips monitors with fewer than KEEP_PER_MONITOR snapshots\n";
e2e_reset();
$GLOBALS['__test_cfg_overrides']['SNAPSHOT_RETENTION_DAYS'] = 30;
$GLOBALS['__test_cfg_overrides']['SNAPSHOT_KEEP_PER_MONITOR'] = 10;
$uid = e2e_insert_user();
$mid = e2e_insert_monitor($uid);
for ($i = 0; $i < 5; $i++) {
    e2e_insert_snapshot($mid, gmdate('Y-m-d H:i:s', time() - (100 - $i) * 86400), 'fpz' . $i);
}
$r = Worker::pruneSnapshots();
assert_eq(0, $r['deleted'], 'pruneSnapshots: fewer than keep_per_monitor not touched');
assert_eq(1, $r['skipped_monitors'], 'pruneSnapshots: 1 monitor skipped');

// ──────────────────────────────────────────────────────────────────────

echo "  [pruneSnapshots] processes multiple monitors independently\n";
e2e_reset();
$GLOBALS['__test_cfg_overrides']['SNAPSHOT_RETENTION_DAYS'] = 10;
$GLOBALS['__test_cfg_overrides']['SNAPSHOT_KEEP_PER_MONITOR'] = 2;
$uid = e2e_insert_user();
$mid1 = e2e_insert_monitor($uid, 'alpha.com');
$mid2 = e2e_insert_monitor($uid, 'beta.com');

// alpha: 5 old snapshots.
for ($i = 0; $i < 5; $i++) {
    e2e_insert_snapshot($mid1, gmdate('Y-m-d H:i:s', time() - (50 - $i) * 86400), 'a' . $i);
}
// beta: 3 old snapshots.
for ($i = 0; $i < 3; $i++) {
    e2e_insert_snapshot($mid2, gmdate('Y-m-d H:i:s', time() - (50 - $i) * 86400), 'b' . $i);
}
$r = Worker::pruneSnapshots();
assert_eq(4, $r['deleted'], 'pruneSnapshots: multi-monitor deletes 3+1=4');
// alpha: 2 remain. beta: 2 remain.
$st = db()->prepare("SELECT COUNT(*) AS c FROM cert_snapshots WHERE monitor_id = :mid");
$st->execute([':mid' => $mid1]);
assert_eq(2, (int)$st->fetch()['c'], 'pruneSnapshots: alpha has 2');
$st->execute([':mid' => $mid2]);
assert_eq(2, (int)$st->fetch()['c'], 'pruneSnapshots: beta has 2');

// ──────────────────────────────────────────────────────────────────────

echo "  [pruneSnapshots] emits system event when rows deleted\n";
// (continues from the previous test's state — events table has the event)
$evSt = db()->query("SELECT * FROM events WHERE type = 'snapshots_pruned' ORDER BY id DESC LIMIT 1");
$ev = $evSt->fetch();
assert_true($ev !== false, 'pruneSnapshots: snapshots_pruned event created');
assert_eq('info', $ev['severity'], 'pruneSnapshots: event severity is info');
$meta = json_decode($ev['meta_json'], true);
assert_eq(4, $meta['deleted'], 'pruneSnapshots: event meta.deleted=4');

// ──────────────────────────────────────────────────────────────────────

echo "  [pruneSnapshots] no event when nothing pruned\n";
e2e_reset();
$GLOBALS['__test_cfg_overrides']['SNAPSHOT_RETENTION_DAYS'] = 30;
$GLOBALS['__test_cfg_overrides']['SNAPSHOT_KEEP_PER_MONITOR'] = 100;
$uid = e2e_insert_user();
$mid = e2e_insert_monitor($uid);
e2e_insert_snapshot($mid, db_now_utc(), 'fp1');
Worker::pruneSnapshots();
$evSt = db()->query("SELECT COUNT(*) AS c FROM events WHERE type = 'snapshots_pruned'");
assert_eq(0, (int)$evSt->fetch()['c'], 'pruneSnapshots: no event when nothing pruned');

// ──────────────────────────────────────────────────────────────────────
// RECONCILE DENORMALIZED
// ──────────────────────────────────────────────────────────────────────

echo "  [reconcileDenormalized] no-op when monitors are in sync\n";
e2e_reset();
$uid = e2e_insert_user();
$mid = e2e_insert_monitor($uid);
$snapTime = '2025-06-15 12:00:00';
e2e_insert_snapshot($mid, $snapTime, 'fp_sync', 'ok', 60);

// Manually set the monitor's denormalized fields to match the snapshot.
db()->prepare("UPDATE monitors SET last_checked_at = :c, last_status = 'ok', last_fingerprint_sha256 = 'fp_sync', last_days_remaining = 60 WHERE id = :id")
    ->execute([':c' => $snapTime, ':id' => $mid]);

$r = Worker::reconcileDenormalized();
assert_eq(1, $r['checked'], 'reconcile: checked 1 monitor');
assert_eq(0, $r['repaired'], 'reconcile: 0 repaired when in sync');

// ──────────────────────────────────────────────────────────────────────

echo "  [reconcileDenormalized] repairs fingerprint drift\n";
e2e_reset();
$uid = e2e_insert_user();
$mid = e2e_insert_monitor($uid);
$snapTime = '2025-06-15 12:00:00';
e2e_insert_snapshot($mid, $snapTime, 'new_fp', 'ok', 45);

// Monitor has stale fingerprint (simulates crash between snapshot insert and monitor update).
db()->prepare("UPDATE monitors SET last_checked_at = :c, last_status = 'ok', last_fingerprint_sha256 = 'old_fp', last_days_remaining = 60 WHERE id = :id")
    ->execute([':c' => $snapTime, ':id' => $mid]);

$r = Worker::reconcileDenormalized();
assert_eq(1, $r['repaired'], 'reconcile: repaired fingerprint drift');

// Verify the monitor now matches the snapshot.
$mon = db()->prepare("SELECT last_fingerprint_sha256, last_days_remaining FROM monitors WHERE id = :id");
$mon->execute([':id' => $mid]);
$row = $mon->fetch();
assert_eq('new_fp', $row['last_fingerprint_sha256'], 'reconcile: fingerprint updated');
assert_eq(45, (int)$row['last_days_remaining'], 'reconcile: days_remaining updated');

// ──────────────────────────────────────────────────────────────────────

echo "  [reconcileDenormalized] repairs time drift (monitor behind snapshot)\n";
e2e_reset();
$uid = e2e_insert_user();
$mid = e2e_insert_monitor($uid);
$oldTime = '2025-06-14 12:00:00';
$newTime = '2025-06-15 12:00:00';
e2e_insert_snapshot($mid, $newTime, 'fp1', 'warn', 10);

// Monitor last_checked_at is older than the latest snapshot.
db()->prepare("UPDATE monitors SET last_checked_at = :c, last_status = 'ok', last_fingerprint_sha256 = 'fp1' WHERE id = :id")
    ->execute([':c' => $oldTime, ':id' => $mid]);

$r = Worker::reconcileDenormalized();
assert_eq(1, $r['repaired'], 'reconcile: repaired time drift');

$mon = db()->prepare("SELECT last_checked_at, last_status FROM monitors WHERE id = :id");
$mon->execute([':id' => $mid]);
$row = $mon->fetch();
assert_eq($newTime, $row['last_checked_at'], 'reconcile: last_checked_at updated');
assert_eq('warn', $row['last_status'], 'reconcile: last_status updated');

// ──────────────────────────────────────────────────────────────────────

echo "  [reconcileDenormalized] repairs status drift\n";
e2e_reset();
$uid = e2e_insert_user();
$mid = e2e_insert_monitor($uid);
$snapTime = '2025-06-15 12:00:00';
e2e_insert_snapshot($mid, $snapTime, 'fp1', 'critical', 2);

// Monitor says 'ok' but snapshot says 'critical'.
db()->prepare("UPDATE monitors SET last_checked_at = :c, last_status = 'ok', last_fingerprint_sha256 = 'fp1', last_days_remaining = 60 WHERE id = :id")
    ->execute([':c' => $snapTime, ':id' => $mid]);

$r = Worker::reconcileDenormalized();
assert_eq(1, $r['repaired'], 'reconcile: repaired status drift');

$mon = db()->prepare("SELECT last_status, last_days_remaining FROM monitors WHERE id = :id");
$mon->execute([':id' => $mid]);
$row = $mon->fetch();
assert_eq('critical', $row['last_status'], 'reconcile: status repaired to critical');
assert_eq(2, (int)$row['last_days_remaining'], 'reconcile: days_remaining repaired');

// ──────────────────────────────────────────────────────────────────────

echo "  [reconcileDenormalized] repairs NULL last_checked_at\n";
e2e_reset();
$uid = e2e_insert_user();
$mid = e2e_insert_monitor($uid);
$snapTime = '2025-06-15 12:00:00';
e2e_insert_snapshot($mid, $snapTime, 'fp_null', 'ok', 90);

// Monitor has never been marked as checked (last_checked_at=NULL).
// This happens if the worker crashed after the first snapshot insert.
$r = Worker::reconcileDenormalized();
assert_eq(1, $r['repaired'], 'reconcile: repaired NULL last_checked_at');

$mon = db()->prepare("SELECT last_checked_at, last_fingerprint_sha256 FROM monitors WHERE id = :id");
$mon->execute([':id' => $mid]);
$row = $mon->fetch();
assert_eq($snapTime, $row['last_checked_at'], 'reconcile: last_checked_at set from NULL');
assert_eq('fp_null', $row['last_fingerprint_sha256'], 'reconcile: fingerprint set from NULL');

// ──────────────────────────────────────────────────────────────────────

echo "  [reconcileDenormalized] skips disabled monitors\n";
e2e_reset();
$uid = e2e_insert_user();
$mid = e2e_insert_monitor($uid);
e2e_insert_snapshot($mid, '2025-06-15 12:00:00', 'fp_dis', 'ok', 60);

// Disable the monitor.
db()->prepare("UPDATE monitors SET enabled = 0 WHERE id = :id")->execute([':id' => $mid]);

$r = Worker::reconcileDenormalized();
assert_eq(0, $r['checked'], 'reconcile: disabled monitor not checked');
assert_eq(0, $r['repaired'], 'reconcile: disabled monitor not repaired');

// ──────────────────────────────────────────────────────────────────────

echo "  [reconcileDenormalized] skips monitors with no snapshots\n";
e2e_reset();
$uid = e2e_insert_user();
$mid = e2e_insert_monitor($uid);
// No snapshots inserted.
$r = Worker::reconcileDenormalized();
assert_eq(0, $r['checked'], 'reconcile: no-snapshot monitor not checked');

// ──────────────────────────────────────────────────────────────────────

echo "  [reconcileDenormalized] emits system event on repair\n";
e2e_reset();
$uid = e2e_insert_user();
$mid = e2e_insert_monitor($uid);
e2e_insert_snapshot($mid, '2025-06-15 12:00:00', 'fpev', 'ok', 30);
// Monitor has stale state — triggers repair.
Worker::reconcileDenormalized();

$evSt = db()->query("SELECT * FROM events WHERE type = 'monitors_reconciled' ORDER BY id DESC LIMIT 1");
$ev = $evSt->fetch();
assert_true($ev !== false, 'reconcile: monitors_reconciled event created');
$meta = json_decode($ev['meta_json'], true);
assert_eq(1, $meta['repaired'], 'reconcile: event meta.repaired=1');

// ──────────────────────────────────────────────────────────────────────

echo "  [reconcileDenormalized] multiple monitors mixed state\n";
e2e_reset();
$uid = e2e_insert_user();
$mid1 = e2e_insert_monitor($uid, 'alpha.com');
$mid2 = e2e_insert_monitor($uid, 'beta.com');
$mid3 = e2e_insert_monitor($uid, 'gamma.com');

$snapTime = '2025-06-15 12:00:00';
e2e_insert_snapshot($mid1, $snapTime, 'fp_a', 'ok', 60);
e2e_insert_snapshot($mid2, $snapTime, 'fp_b', 'warn', 20);
e2e_insert_snapshot($mid3, $snapTime, 'fp_c', 'critical', 3);

// mid1: in sync.
db()->prepare("UPDATE monitors SET last_checked_at = :c, last_status = 'ok', last_fingerprint_sha256 = 'fp_a', last_days_remaining = 60 WHERE id = :id")
    ->execute([':c' => $snapTime, ':id' => $mid1]);
// mid2: stale fingerprint.
db()->prepare("UPDATE monitors SET last_checked_at = :c, last_status = 'warn', last_fingerprint_sha256 = 'stale_fp', last_days_remaining = 20 WHERE id = :id")
    ->execute([':c' => $snapTime, ':id' => $mid2]);
// mid3: stale status.
db()->prepare("UPDATE monitors SET last_checked_at = :c, last_status = 'ok', last_fingerprint_sha256 = 'fp_c', last_days_remaining = 3 WHERE id = :id")
    ->execute([':c' => $snapTime, ':id' => $mid3]);

$r = Worker::reconcileDenormalized();
assert_eq(3, $r['checked'], 'reconcile: checked all 3 monitors');
assert_eq(2, $r['repaired'], 'reconcile: repaired 2 of 3');

// ──────────────────────────────────────────────────────────────────────
// CRON HEALTH CHECK (@mail fix)
// ──────────────────────────────────────────────────────────────────────

echo "  [cronHealthCheck] no-op when no last_cron_run_at\n";
e2e_reset();
// No system_state entry for 'last_cron_run_at'.
Worker::cronHealthCheck();
$evSt = db()->query("SELECT COUNT(*) AS c FROM events WHERE type = 'cron_failed'");
assert_eq(0, (int)$evSt->fetch()['c'], 'cronHealthCheck: no-op without system state');

// ──────────────────────────────────────────────────────────────────────

echo "  [cronHealthCheck] no-op when cron ran recently\n";
e2e_reset();
Worker::setSystemState('last_cron_run_at', gmdate('Y-m-d H:i:s', time() - 3600)); // 1h ago
Worker::cronHealthCheck();
$evSt = db()->query("SELECT COUNT(*) AS c FROM events WHERE type = 'cron_failed'");
assert_eq(0, (int)$evSt->fetch()['c'], 'cronHealthCheck: no-op when recent');

// ──────────────────────────────────────────────────────────────────────

echo "  [cronHealthCheck] creates cron_failed event when >12h\n";
e2e_reset();
Worker::setSystemState('last_cron_run_at', gmdate('Y-m-d H:i:s', time() - 13 * 3600)); // 13h ago
Worker::cronHealthCheck();
$evSt = db()->query("SELECT * FROM events WHERE type = 'cron_failed' ORDER BY id DESC LIMIT 1");
$ev = $evSt->fetch();
assert_true($ev !== false, 'cronHealthCheck: cron_failed event created');
assert_eq('critical', $ev['severity'], 'cronHealthCheck: severity is critical');

// ──────────────────────────────────────────────────────────────────────

echo "  [cronHealthCheck] deduplicates within 12h window\n";
// Do NOT reset — second call in the same 12h window should be a no-op.
$evCount1 = (int)db()->query("SELECT COUNT(*) AS c FROM events WHERE type = 'cron_failed'")->fetch()['c'];
Worker::cronHealthCheck(); // Second call.
$evCount2 = (int)db()->query("SELECT COUNT(*) AS c FROM events WHERE type = 'cron_failed'")->fetch()['c'];
assert_eq($evCount1, $evCount2, 'cronHealthCheck: deduplicated within 12h');

// ──────────────────────────────────────────────────────────────────────
// SYSTEM STATE HELPERS
// ──────────────────────────────────────────────────────────────────────

echo "  [setSystemState/getSystemState] round-trip\n";
e2e_reset();
Worker::setSystemState('test_key', 'test_value');
assert_eq('test_value', Worker::getSystemState('test_key'), 'systemState: round-trip');

echo "  [setSystemState] upsert overwrites\n";
Worker::setSystemState('test_key', 'updated');
assert_eq('updated', Worker::getSystemState('test_key'), 'systemState: upsert overwrites');

echo "  [getSystemState] returns null for missing key\n";
assert_eq(null, Worker::getSystemState('nonexistent'), 'systemState: null for missing');
