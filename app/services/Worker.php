<?php
declare(strict_types=1);

final class Worker {

    private static function requireSchemaVersion(string $expected): void {
        $current = self::getSystemState('schema_version');
        if ($current === null || $current === '') {
            // Fresh install or pre-versioned DB: set version marker.
            self::setSystemState('schema_version', $expected);
            return;
        }
        if ($current !== $expected) {
            throw new RuntimeException('DB schema_version mismatch: expected ' . $expected . ', got ' . $current);
        }
    }

    public static function setSystemState(string $key, string $value): void {
        $st = db()->prepare("INSERT INTO system_state (`key`,`value`,`updated_at`) VALUES (:k,:v,:u)
                             ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_at=VALUES(updated_at)");
        $st->execute([':k'=>$key, ':v'=>$value, ':u'=>db_now_utc()]);
    }

    public static function getSystemState(string $key): ?string {
        $st = db()->prepare("SELECT `value` FROM system_state WHERE `key`=:k");
        $st->execute([':k'=>$key]);
        $r = $st->fetch();
        return $r ? (string)$r['value'] : null;
    }


    /**
     * Create an async job to run checks for all enabled monitors.
     */
    public static function createRunAllJob(?int $requestedByUserId): int {
        self::requireSchemaVersion(schema_version());
        $now = db_now_utc();
        $st = db()->prepare("INSERT INTO worker_jobs (type, requested_by_user_id, status, total_processed, last_monitor_id, error, created_at, updated_at, started_at, finished_at)
                             VALUES ('run_all', :uid, 'pending', 0, NULL, NULL, :c, :u, NULL, NULL)");
        $st->execute([':uid' => $requestedByUserId, ':c' => $now, ':u' => $now]);
        return (int)db()->lastInsertId();
    }

    /**
     * Cancel a job.
     */
    public static function cancelJob(int $jobId): bool {
        self::requireSchemaVersion(schema_version());
        $st = db()->prepare("UPDATE worker_jobs SET status='cancelled', updated_at=:u, finished_at=:u WHERE id=:id AND status IN ('pending','running')");
        $st->execute([':u'=>db_now_utc(), ':id'=>$jobId]);
        return $st->rowCount() > 0;
    }

    /**
     * Latest job requested by a specific user.
     * @return array<string,mixed>|null
     */
    public static function getLatestJobForUser(int $userId): ?array {
        self::requireSchemaVersion(schema_version());
        $st = db()->prepare("SELECT * FROM worker_jobs WHERE requested_by_user_id=:uid ORDER BY created_at DESC LIMIT 1");
        $st->execute([':uid'=>$userId]);
        $r = $st->fetch();
        return $r ?: null;
    }

    /**
     * Process one pending/running job in small batches.
     * Designed to be callable from both CLI worker and web (time boxed).
     * @return array<string,mixed>|null job summary or null if no job.
     */
    public static function processJobs(int $maxChecks = 25, int $maxSeconds = 20, ?int $onlyJobId = null): ?array {
        self::requireSchemaVersion(schema_version());
        $t0 = microtime(true);

        if ($onlyJobId !== null) {
            $st = db()->prepare("SELECT * FROM worker_jobs WHERE id=:id LIMIT 1");
            $st->execute([':id'=>$onlyJobId]);
        } else {
            $st = db()->prepare("SELECT * FROM worker_jobs WHERE status IN ('pending','running') ORDER BY created_at ASC LIMIT 1");
            $st->execute();
        }
        $job = $st->fetch();
        if (!$job) return null;

        $jobId = (int)$job['id'];
        $status = (string)$job['status'];
        if ($status === 'pending') {
            // claim job
            $claim = db()->prepare("UPDATE worker_jobs SET status='running', started_at=:u, updated_at=:u WHERE id=:id AND status='pending'");
            $claim->execute([':u'=>db_now_utc(), ':id'=>$jobId]);
            // reload
            $st2 = db()->prepare("SELECT * FROM worker_jobs WHERE id=:id LIMIT 1");
            $st2->execute([':id'=>$jobId]);
            $job = $st2->fetch() ?: $job;
            $status = (string)$job['status'];
        }

        if ($status !== 'running') {
            return ['id'=>$jobId,'status'=>$status,'total_processed'=>(int)$job['total_processed']];
        }

        if ((string)$job['type'] !== 'run_all') {
            $fail = db()->prepare("UPDATE worker_jobs SET status='failed', error=:e, updated_at=:u, finished_at=:u WHERE id=:id");
            $fail->execute([':e'=>'unknown_job_type', ':u'=>db_now_utc(), ':id'=>$jobId]);
            self::createSystemEvent('job_failed','warn','Worker job failed (unknown type).',['job_id'=>$jobId]);
            return ['id'=>$jobId,'status'=>'failed','error'=>'unknown_job_type'];
        }

        $lastId = $job['last_monitor_id'] !== null ? (int)$job['last_monitor_id'] : 0;
        $processed = (int)$job['total_processed'];

        $sel = db()->prepare("SELECT id FROM monitors WHERE enabled=1 AND id > :last ORDER BY id ASC LIMIT :lim");
        $sel->bindValue(':last', $lastId, PDO::PARAM_INT);
        $sel->bindValue(':lim', $maxChecks, PDO::PARAM_INT);
        $sel->execute();
        $rows = $sel->fetchAll();

        $checked = 0; $errors=0; $changed=0; $renewed=0; $warned=0;

        foreach ($rows as $r) {
            $mid = (int)$r['id'];
            $out = self::checkOne($mid);
            $checked++;
            $errors += (int)($out['errors'] ?? 0);
            $changed += (int)($out['changed'] ?? 0);
            $renewed += (int)($out['renewed'] ?? 0);
            $warned += (int)($out['warned'] ?? 0);
            $processed++;
            $lastId = $mid;
            if ((microtime(true) - $t0) >= $maxSeconds) {
                break;
            }
        }

        // update cursor/progress
        $up = db()->prepare("UPDATE worker_jobs SET total_processed=:p, last_monitor_id=:last, updated_at=:u WHERE id=:id AND status='running'");
        $up->execute([':p'=>$processed, ':last'=>($lastId>0?$lastId:null), ':u'=>db_now_utc(), ':id'=>$jobId]);

        $doneBatch = count($rows) == 0 || ($checked < count($rows));

        // Determine completion: if we fetched 0 new rows, we're done.
        if (count($rows) === 0) {
            $fin = db()->prepare("UPDATE worker_jobs SET status='completed', updated_at=:u, finished_at=:u WHERE id=:id AND status='running'");
            $fin->execute([':u'=>db_now_utc(), ':id'=>$jobId]);
            self::createSystemEvent('job_completed','info','Worker job completed.',[
                'job_id'=>$jobId,
                'type'=>'run_all',
                'total_processed'=>$processed,
            ]);
        }

        return [
            'id'=>$jobId,
            'status'=>(count($rows)===0 ? 'completed' : 'running'),
            'batch' => ['checked'=>$checked,'errors'=>$errors,'changed'=>$changed,'renewed'=>$renewed,'warned'=>$warned],
            'total_processed'=>$processed,
            'last_monitor_id'=>$lastId,
        ];
    }

    public static function runDueChecks(?int $limit = null): array {
        self::requireSchemaVersion(schema_version());
        $t0 = microtime(true);
        $sql = "SELECT m.id
                FROM monitors m
                JOIN monitor_settings ms ON ms.monitor_id=m.id
                WHERE m.enabled=1
                ORDER BY m.updated_at DESC";
        $ids = db()->query($sql)->fetchAll();
        $done = 0;
        $results = ['checked'=>0,'errors'=>0,'changed'=>0,'renewed'=>0,'warned'=>0];

        foreach ($ids as $row) {
            if ($limit !== null && $done >= $limit) break;
            $monitorId = (int)$row['id'];

            if (!self::isDue($monitorId)) continue;

            $r = self::checkOne($monitorId);
            $results['checked'] += 1;
            foreach (['errors','changed','renewed','warned'] as $k) $results[$k] += (int)($r[$k] ?? 0);

            $done++;
        }
        self::setSystemState('last_cron_run_at', db_now_utc());
        self::setSystemState('last_cron_ok', '1');

        // Process pending notifications (reliable delivery)
        $outbox = Notifier::processOutbox(100);

        $durationMs = (int)round((microtime(true) - $t0) * 1000);
        self::createSystemEvent('worker_run', 'info', 'Worker run (due).', array_merge($results, ['mode'=>'due','duration_ms'=>$durationMs,'outbox'=>$outbox]));
        return $results;
    }

    public static function runAllChecks(?int $limit = null): array {
        self::requireSchemaVersion(schema_version());
        $t0 = microtime(true);
        $st = db()->query("SELECT id FROM monitors WHERE enabled=1 ORDER BY updated_at DESC");
        $rows = $st->fetchAll();
        $results = ['checked'=>0,'errors'=>0,'changed'=>0,'renewed'=>0,'warned'=>0];
        $done = 0;
        foreach ($rows as $r) {
            if ($limit !== null && $done >= $limit) break;
            $out = self::checkOne((int)$r['id']);
            $results['checked'] += 1;
            foreach (['errors','changed','renewed','warned'] as $k) $results[$k] += (int)($out[$k] ?? 0);
            $done++;
        }
        self::setSystemState('last_cron_run_at', db_now_utc());
        self::setSystemState('last_cron_ok', '1');

        $outbox = Notifier::processOutbox(100);
        $durationMs = (int)round((microtime(true) - $t0) * 1000);
        self::createSystemEvent('worker_run', 'info', 'Worker run (all).', array_merge($results, ['mode'=>'all','duration_ms'=>$durationMs,'outbox'=>$outbox]));
        return $results;
    }

    private static function isDue(int $monitorId): bool {
        $m = MonitorService::getMonitorById($monitorId);
        if (!$m) return false;
        $freq = max(5, (int)$m['check_frequency_minutes']); // safety clamp
        $lastChecked = $m['last_checked_at'] ?? null;
        if (!$lastChecked) {
            $last = MonitorService::getLatestSnapshot($monitorId);
            if (!$last) return true;
            $lastChecked = (string)$last['fetched_at'];
        }
        $lastTs = strtotime((string)$lastChecked . ' UTC');
        if ($lastTs === false) return true;
        return (time() - $lastTs) >= ($freq * 60);
    }

    /**
     * Runs one check, stores snapshot, creates events, sends notifications.
     */
    public static function checkOne(int $monitorId): array {
        $m = MonitorService::getMonitorById($monitorId);
        if (!$m) return ['errors'=>1];
        $prev = MonitorService::getLatestSnapshot($monitorId);

        // Fetch cert
        $fetch = CertFetcher::fetch((string)$m['host'], (int)$m['port']);
        $now = db_now_utc();

        $status = 'unknown';
        $err = null;
        $parsed = null;
        $pem = null;
        $finger = null;
        $validFrom = null;
        $validTo = null;
        $issuer = null;
        $subject = null;
        $serial = null;
        $daysRemaining = null;

        if (!$fetch['ok']) {
            $status = 'critical';
            $err = (string)$fetch['error'];
        } else {
            $parsed = $fetch['parsed'];
            $pem = $fetch['pem'];
            $finger = $fetch['fingerprint_sha256'];

            if (!$parsed) {
                $status = 'critical';
                $err = 'parse_failed';
            } else {
                $serial = (string)($parsed['serialNumberHex'] ?? '');
                $issuer = (string)($parsed['issuer']['CN'] ?? ($parsed['issuer']['O'] ?? ''));
                $subject = (string)($parsed['subject']['CN'] ?? ($parsed['subject']['O'] ?? ''));

                $vf = (int)($parsed['validFrom_time_t'] ?? 0);
                $vt = (int)($parsed['validTo_time_t'] ?? 0);

                $validFrom = gmdate('Y-m-d H:i:s', $vf);
                $validTo = gmdate('Y-m-d H:i:s', $vt);

                $daysRemaining = (int)floor(($vt - time()) / 86400);

                $notifyDays = (int)$m['notify_days_before_expiry'];
                if ($daysRemaining <= 0) {
                    $status = 'critical';
                } elseif ($daysRemaining <= $notifyDays) {
                    $status = 'warn';
                } else {
                    $status = 'ok';
                }
            }
        }

        // Store snapshot
        $st = db()->prepare('
            INSERT INTO cert_snapshots
            (monitor_id, fetched_at, serial, fingerprint_sha256, issuer_cn, subject_cn, valid_from, valid_to, raw_pem, status, error, days_remaining)
            VALUES
            (:mid,:f,:serial,:fp,:issuer,:subject,:vf,:vt,:pem,:status,:err,:days)
        ');
        $st->execute([
            ':mid'=>$monitorId,
            ':f'=>$now,
            ':serial'=>$serial,
            ':fp'=>$finger,
            ':issuer'=>$issuer,
            ':subject'=>$subject,
            ':vf'=>$validFrom,
            ':vt'=>$validTo,
            ':pem'=>$pem,
            ':status'=>$status,
            ':err'=>$err,
            ':days'=>$daysRemaining,
        ]);

        // Update monitor denormalized fields for fast UI queries
        $up = db()->prepare('UPDATE monitors SET last_checked_at=:c, last_status=:st, last_fingerprint_sha256=:fp, last_issuer_cn=:issuer,
                             last_valid_from=:vf, last_valid_to=:vt, last_days_remaining=:days, last_error=:err, updated_at=:u WHERE id=:id');
        $up->execute([
            ':c'=>$now,
            ':st'=>$status,
            ':fp'=>$finger,
            ':issuer'=>$issuer,
            ':vf'=>$validFrom,
            ':vt'=>$validTo,
            ':days'=>$daysRemaining,
            ':err'=>$err,
            ':u'=>$now,
            ':id'=>$monitorId,
        ]);
        $out = ['errors'=>0,'changed'=>0,'renewed'=>0,'warned'=>0];

        // Evaluate events
        if ($status === 'critical' && $err !== null) {
            self::createEvent($monitorId, 'check_failed', 'critical', "TLS fetch failed: {$err}", ['error'=>$err]);
            $out['errors'] = 1;
            return $out;
        }

        // Change/Renewal detection
        if ($prev && $finger && !empty($prev['fingerprint_sha256']) && $prev['fingerprint_sha256'] !== $finger) {
            $confirm = self::confirmChange($m, $finger);
            $samples = (int)($confirm['samples'] ?? max(1, (int)cfg('TLS_SAMPLES_ON_CHANGE', 2)));
            $observed = $confirm['observed_fingerprints'] ?? [];
            $confirmOk = (bool)($confirm['confirmed'] ?? false);
            if ($confirmOk) {
                $out['changed'] = 1;

                $prevTo = $prev['valid_to'] ? strtotime($prev['valid_to'] . ' UTC') : null;
                $newTo = $validTo ? strtotime($validTo . ' UTC') : null;
                $prevFrom = $prev['valid_from'] ? strtotime($prev['valid_from'] . ' UTC') : null;
                $newFrom = $validFrom ? strtotime($validFrom . ' UTC') : null;

                $isRenewal = false;
                if ($prevTo && $newTo && $newTo > $prevTo) $isRenewal = true;
                if ($prevFrom && $newFrom && $newFrom > $prevFrom) $isRenewal = true;

                $meta = [
                    'prev_fingerprint' => $prev['fingerprint_sha256'],
                    'new_fingerprint' => $finger,
                    'prev_valid_to' => $prev['valid_to'],
                    'new_valid_to' => $validTo,
                    'confirm_samples' => $samples,
                    'confirm_result' => 'confirmed',
                    'observed_fingerprints' => $observed,
                ];

                if ($prevTo && $newFrom) {
                    // Positive number means "renewed before previous expiry".
                    $meta['early_renewal_days'] = (int)floor(($prevTo - $newFrom) / 86400);
                }

                if ($isRenewal && (int)$m['notify_on_renewal'] === 1) {
                    self::createEvent($monitorId, 'renewed', 'info', "Certificate renewed/replaced (fingerprint changed).", $meta);
                    $out['renewed'] = 1;
                } elseif ((int)$m['notify_on_change'] === 1) {
                    self::createEvent($monitorId, 'changed', 'warn', "Certificate changed (fingerprint changed).", $meta);
                }
            } else {
                // Unstable endpoint / probable load-balancer variation.
                self::createEvent($monitorId, 'changed_unstable', 'warn', "Certificate change detected but not consistent across samples (possible false positive).", [
                    'prev_fingerprint'=>$prev['fingerprint_sha256'],
                    'new_fingerprint'=>$finger,
                    'confirm_samples' => $samples,
                    'confirm_result' => 'unstable',
                    'observed_fingerprints' => $observed,
                ]);
            }
        }

        // Expiry warnings
        $notifyDays = (int)$m['notify_days_before_expiry'];
        if ($daysRemaining !== null && $daysRemaining <= $notifyDays && $daysRemaining > 0) {
            self::createEvent($monitorId, 'expiry_warning', 'warn', "Certificate expires in {$daysRemaining} day(s).", [
                'days_remaining'=>$daysRemaining,
                'valid_to'=>$validTo,
            ]);
            $out['warned'] = 1;
        } elseif ($daysRemaining !== null && $daysRemaining <= 0) {
            self::createEvent($monitorId, 'expired', 'critical', "Certificate appears expired (days_remaining={$daysRemaining}).", [
                'days_remaining'=>$daysRemaining,
                'valid_to'=>$validTo,
            ]);
            $out['warned'] = 1;
        }

        return $out;
    }

    /**
     * Confirm certificate change across N samples, with a short delay between samples.
     * Returns observed fingerprints to explain "unstable" endpoints.
     * @return array{confirmed:bool,samples:int,observed_fingerprints:array<int,string>}
     */
    private static function confirmChange(array $monitorRow, string $newFingerprint): array {
        $samples = max(1, (int)cfg('TLS_SAMPLES_ON_CHANGE', 2));
        $observed = [];
        $confirmed = true;

        for ($i=0; $i<$samples; $i++) {
            if ($i > 0) {
                // Small delay reduces false positives on load-balanced endpoints.
                usleep(2000000);
            }
            $f = CertFetcher::fetch((string)$monitorRow['host'], (int)$monitorRow['port']);
            if (!$f['ok'] || empty($f['fingerprint_sha256'])) {
                $confirmed = false;
                break;
            }
            $fp = (string)$f['fingerprint_sha256'];
            $observed[] = $fp;
            if ($fp !== $newFingerprint) {
                $confirmed = false;
            }
        }

        return ['confirmed'=>$confirmed, 'samples'=>$samples, 'observed_fingerprints'=>$observed];
    }

    private static function createEvent(int $monitorId, string $type, string $severity, string $message, array $meta): void {
        $st = db()->prepare('INSERT INTO events (monitor_id, type, severity, message, created_at, meta_json) VALUES (:mid,:t,:s,:m,:c,:meta)');
        $created = db_now_utc();
        $st->execute([
            ':mid'=>$monitorId,
            ':t'=>$type,
            ':s'=>$severity,
            ':m'=>$message,
            ':c'=>$created,
            ':meta'=>json_encode($meta, JSON_UNESCAPED_SLASHES),
        ]);

        // Enqueue notifications for monitor owner.
        $eventId = (int)db()->lastInsertId();
        $m = MonitorService::getMonitorById($monitorId);
        if ($m) {
            Notifier::enqueueForEvent((int)$m['user_id'], $eventId, $monitorId, $type, $meta);
        }
    }

    private static function createSystemEvent(string $type, string $severity, string $message, array $meta): void {
        $st = db()->prepare("INSERT INTO events (monitor_id, type, severity, message, created_at, meta_json)
                              VALUES (NULL,:t,:s,:m,:c,:meta)");
        $st->execute([
            ':t'=>$type,
            ':s'=>$severity,
            ':m'=>$message,
            ':c'=>db_now_utc(),
            ':meta'=>json_encode($meta, JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * Dashboard fallback: if cron not run in >12h, create a 'cron_failed' event (once per 12h).
     */
    public static function cronHealthCheck(): void {
        $last = self::getSystemState('last_cron_run_at');
        if (!$last) return;

        $ts = strtotime($last . ' UTC');
        if ($ts === false) return;

        if ((time() - $ts) <= (12 * 3600)) return;

        // Deduplicate: only one 'cron_failed' in the last 12h.
        $st = db()->prepare("SELECT COUNT(*) AS c FROM events WHERE type='cron_failed' AND created_at >= :since");
        $since = gmdate('Y-m-d H:i:s', time() - 12*3600);
        $st->execute([':since'=>$since]);
        $c = (int)($st->fetch()['c'] ?? 0);
        if ($c > 0) return;

        $msg = "Cron/worker has not reported a successful run in >12 hours (last={$last} UTC).";
        $st2 = db()->prepare("INSERT INTO events (monitor_id, type, severity, message, created_at, meta_json)
                              VALUES (NULL,'cron_failed','critical',:m,:c,:meta)");
        $created = db_now_utc();
        $st2->execute([
            ':m'=>$msg,
            ':c'=>$created,
            ':meta'=>json_encode(['last_cron_run_at'=>$last], JSON_UNESCAPED_SLASHES),
        ]);

        $admin = cfg('ADMIN_EMAIL', '');
        if ($admin) {
            // best-effort email; no user_id context
            @mail($admin, "[Certinel] critical cron_failed", $msg, "From: ".cfg('MAIL_FROM_NAME','Certinel')." <".cfg('MAIL_FROM','no-reply@example.com').">");
        }
    }
}
