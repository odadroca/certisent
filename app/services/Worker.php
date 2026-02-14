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
        $pdo = db();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $now = db_now_utc();
        if ($driver === 'sqlite') {
            $st = $pdo->prepare("INSERT OR REPLACE INTO system_state (\"key\",\"value\",updated_at) VALUES (:k,:v,:u)");
        } else {
            $st = $pdo->prepare("INSERT INTO system_state (`key`,`value`,`updated_at`) VALUES (:k,:v,:u)
                                 ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_at=VALUES(updated_at)");
        }
        $st->execute([':k'=>$key, ':v'=>$value, ':u'=>$now]);
    }

    public static function getSystemState(string $key): ?string {
        $st = db()->prepare("SELECT `value` FROM system_state WHERE `key`=:k");
        $st->execute([':k'=>$key]);
        $r = $st->fetch();
        return $r ? (string)$r['value'] : null;
    }

    /**
     * Correlation IDs are used for server-side log tracing of worker runs and job processing.
     * They are not persisted in the DB and are not shown to unauthenticated users.
     */
    private static function newCorrelationId(): string {
        try {
            return bin2hex(random_bytes(8));
        } catch (Throwable $e) {
            // Fallback for environments with restricted CSPRNG access.
            return substr(str_replace('.', '', uniqid('', true)), 0, 16);
        }
    }

    /**
     * Server-side logging helper (error_log). Keep payloads minimal (no secrets).
     */
    private static function logWithCid(string $cid, string $event, array $meta = []): void {
        $line = '[certinel] cid=' . $cid . ' ' . $event;
        if (!empty($meta)) {
            $line .= ' ' . json_encode($meta, JSON_UNESCAPED_SLASHES);
        }
        error_log($line);
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

        $cid = self::newCorrelationId();
        self::logWithCid($cid, 'job_processing_start', [
            'only_job_id' => $onlyJobId,
            'max_checks' => $maxChecks,
            'max_seconds' => $maxSeconds,
        ]);


        if ($onlyJobId !== null) {
            $st = db()->prepare("SELECT * FROM worker_jobs WHERE id=:id LIMIT 1");
            $st->execute([':id'=>$onlyJobId]);
        } else {
            $st = db()->prepare("SELECT * FROM worker_jobs WHERE status IN ('pending','running') ORDER BY created_at ASC LIMIT 1");
            $st->execute();
        }
        $job = $st->fetch();
        if (!$job) {
            self::logWithCid($cid, 'job_processing_no_job');
            return null;
        }

        $jobId = (int)$job['id'];
        $status = (string)$job['status'];
        self::logWithCid($cid, 'job_processing_selected', ['job_id'=>$jobId,'status'=>$status,'type'=>(string)$job['type']]);
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
            self::logWithCid($cid, 'job_processing_exit', ['job_id'=>$jobId,'status'=>$status,'total_processed'=>(int)$job['total_processed']]);
            return ['id'=>$jobId,'status'=>$status,'total_processed'=>(int)$job['total_processed']];
        }

        if ((string)$job['type'] !== 'run_all') {
            $fail = db()->prepare("UPDATE worker_jobs SET status='failed', error=:e, updated_at=:u, finished_at=:u WHERE id=:id");
            $fail->execute([':e'=>'unknown_job_type', ':u'=>db_now_utc(), ':id'=>$jobId]);
            self::createSystemEvent('job_failed','warn','Worker job failed (unknown type).',['job_id'=>$jobId]);
            self::logWithCid($cid, 'job_processing_exit', ['job_id'=>$jobId,'status'=>'failed','error'=>'unknown_job_type']);
            return ['id'=>$jobId,'status'=>'failed','error'=>'unknown_job_type'];
        }

        $lastId = $job['last_monitor_id'] !== null ? (int)$job['last_monitor_id'] : 0;
        $processed = (int)$job['total_processed'];

        $maxChecks = max(1, min(10000, $maxChecks));
        $sql = "SELECT id FROM monitors WHERE enabled=1 AND id > :last ORDER BY id ASC LIMIT {$maxChecks}";
        $sel = db()->prepare($sql);
        $sel->bindValue(':last', $lastId, PDO::PARAM_INT);
        $sel->execute();
$rows = $sel->fetchAll();

        $checked = 0; $errors=0; $changed=0; $renewed=0; $warned=0;
        // Cancellation responsiveness: check job status between monitor checks.
        $stStatus = db()->prepare("SELECT status FROM worker_jobs WHERE id=:id LIMIT 1");
        $stopStatus = 'running';

        foreach ($rows as $r) {
            $stStatus->execute([':id'=>$jobId]);
            $cur = $stStatus->fetch();
            $stopStatus = $cur ? (string)$cur['status'] : 'running';
            if ($stopStatus !== 'running') {
                break;
            }

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

        if ($stopStatus === 'cancelled') {
            // Persist progress even if cancellation occurred mid-batch.
            $upC = db()->prepare("UPDATE worker_jobs SET total_processed=:p, last_monitor_id=:last, updated_at=:u, finished_at=COALESCE(finished_at,:u) WHERE id=:id AND status='cancelled'");
            $upC->execute([
                ':p'=>$processed,
                ':last'=>($lastId>0?$lastId:null),
                ':u'=>db_now_utc(),
                ':id'=>$jobId,
            ]);

            self::logWithCid($cid, 'job_processing_exit', ['job_id'=>$jobId,'status'=>'cancelled','total_processed'=>$processed,'last_monitor_id'=>$lastId]);
            return [
                'id'=>$jobId,
                'status'=>'cancelled',
                'batch' => ['checked'=>$checked,'errors'=>$errors,'changed'=>$changed,'renewed'=>$renewed,'warned'=>$warned],
                'total_processed'=>$processed,
                'last_monitor_id'=>$lastId,
            ];
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

        $exitStatus = (count($rows)===0 ? 'completed' : 'running');
        self::logWithCid($cid, 'job_processing_exit', ['job_id'=>$jobId,'status'=>$exitStatus,'total_processed'=>$processed,'last_monitor_id'=>$lastId]);

        return [
            'id'=>$jobId,
            'status'=>$exitStatus,
            'batch' => ['checked'=>$checked,'errors'=>$errors,'changed'=>$changed,'renewed'=>$renewed,'warned'=>$warned],
            'total_processed'=>$processed,
            'last_monitor_id'=>$lastId,
        ];
    }

    public static function runDueChecks(?int $limit = null): array {
        self::requireSchemaVersion(schema_version());
        $t0 = microtime(true);
        $cid = self::newCorrelationId();
        self::logWithCid($cid, 'worker_due_start', ['limit'=>$limit]);
        // Fetch all information needed to decide whether each monitor is due in one query
        // (avoid re-loading each monitor and its latest snapshot in a loop).
        $sql = "SELECT
                    m.id,
                    m.last_checked_at,
                    ms.check_frequency_minutes,
                    ls.last_fetched_at
                FROM monitors m
                JOIN monitor_settings ms ON ms.monitor_id=m.id
                LEFT JOIN (
                    SELECT monitor_id, MAX(fetched_at) AS last_fetched_at
                    FROM cert_snapshots
                    GROUP BY monitor_id
                ) ls ON ls.monitor_id=m.id
                WHERE m.enabled=1
                ORDER BY m.updated_at DESC";
        $rows = db()->query($sql)->fetchAll();
        $done = 0;
        $results = ['checked'=>0,'errors'=>0,'changed'=>0,'renewed'=>0,'warned'=>0];

        foreach ($rows as $row) {
            if ($limit !== null && $done >= $limit) break;
            $monitorId = (int)$row['id'];

            if (!self::isDueRow($row)) continue;

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
        self::logWithCid($cid, 'worker_due_end', array_merge($results, ['duration_ms'=>$durationMs,'outbox'=>$outbox]));
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

    /**
     * Decide if a monitor is due based on a row from the runDueChecks() query.
     *
     * Semantics match the previous implementation:
     * - frequency is clamped to >= 5 minutes
     * - if last_checked_at is null, fall back to latest snapshot fetched_at
     * - if there are no snapshots, the monitor is due
     */
    private static function isDueRow(array $row): bool {
        $freq = max(5, (int)($row['check_frequency_minutes'] ?? 0)); // safety clamp

        $lastChecked = $row['last_checked_at'] ?? null;
        if (!$lastChecked) {
            $lastFetched = $row['last_fetched_at'] ?? null;
            if (!$lastFetched) return true;
            $lastChecked = (string)$lastFetched;
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
        // v0.7.2: optional TLS identity validation (hostname mismatch)
        $tlsMode = strtolower(trim((string)($m['tls_validation_mode'] ?? 'off')));
        if (!in_array($tlsMode, ['off','observe','enforce'], true)) $tlsMode = 'off';
        $hostnameOk = null;
        $hostnameErr = null;
        $hostnameShouldUpdate = 0;
        // v0.7.3: optional TLS trust validation (self-signed / untrusted-root)
        $trustOk = null;
        $trustCategory = null;
        $trustErr = null;
        $trustShouldUpdate = 0;

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

                // v0.7.2: hostname validation (opt-in per monitor)
                if ($tlsMode !== 'off') {
                    $hv = TlsValidator::validateHostname((string)$m['host'], $parsed);
                    $hostnameShouldUpdate = 1;
                    $hostnameOk = $hv['ok'] ? 1 : 0;
                    if (!$hv['ok']) {
                        $hostnameErr = (string)($hv['error'] ?? 'hostname_mismatch');
                        $cands = $hv['candidates'] ?? [];
                        if (is_array($cands) && count($cands) > 0) {
                            $list = implode(', ', array_slice(array_values(array_map('strval', $cands)), 0, 6));
                            if ($list !== '') {
                                $hostnameErr .= ' (candidates: ' . $list . ')';
                            }
                        }
                        if (strlen($hostnameErr) > 255) {
                            $hostnameErr = substr($hostnameErr, 0, 252) . '...';
                        }
                    } else {
                        $hostnameErr = null;
                    }
                }

                // v0.7.3: trust validation (opt-in per monitor). Chain trust only, using system CA bundle.
                if ($tlsMode !== 'off') {
                    $tv = TlsValidator::validateTrust((string)$m['host'], (int)$m['port']);
                    $trustShouldUpdate = 1;
                    if (($tv['ok'] ?? false) === true) {
                        $trustOk = 1;
                        $trustCategory = null;
                        $trustErr = null;
                    } else {
                        $trustOk = 0;
                        $tType = (string)($tv['type'] ?? 'untrusted');
                        $trustCategory = $tType === 'untrusted' ? (string)($tv['category'] ?? 'tls_untrusted_unknown') : 'tls_untrusted_unknown';
                        $tErr = (string)($tv['error'] ?? ($tType === 'probe_error' ? 'probe_error' : 'untrusted'));
                        if ($tType === 'probe_error' && !str_starts_with($tErr, 'probe_error')) {
                            $tErr = 'probe_error: ' . $tErr;
                        }
                        if (strlen($tErr) > 255) $tErr = substr($tErr, 0, 252) . '...';
                        $trustErr = $tErr;
                    }
                }

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
                             last_valid_from=:vf, last_valid_to=:vt, last_days_remaining=:days, last_error=:err,
                             hostname_ok = CASE WHEN :hupd=1 THEN :hok ELSE hostname_ok END,
                             hostname_error = CASE WHEN :hupd=1 THEN :herr ELSE hostname_error END,
                             trust_ok = CASE WHEN :tupd=1 THEN :tok ELSE trust_ok END,
                             trust_category = CASE WHEN :tupd=1 THEN :tcat ELSE trust_category END,
                             trust_error = CASE WHEN :tupd=1 THEN :terr ELSE trust_error END,
                             updated_at=:u WHERE id=:id');
        $up->execute([
            ':c'=>$now,
            ':st'=>$status,
            ':fp'=>$finger,
            ':issuer'=>$issuer,
            ':vf'=>$validFrom,
            ':vt'=>$validTo,
            ':days'=>$daysRemaining,
            ':err'=>$err,
            ':hupd'=>$hostnameShouldUpdate,
            ':hok'=>$hostnameOk,
            ':herr'=>$hostnameErr,
            ':tupd'=>$trustShouldUpdate,
            ':tok'=>$trustOk,
            ':tcat'=>$trustCategory,
            ':terr'=>$trustErr,
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

        // For dedupe logic: fingerprint changed since previous snapshot?
        $fpChanged = ($prev && $finger && !empty($prev['fingerprint_sha256']) && $prev['fingerprint_sha256'] !== $finger);


        // v0.7.4: TLS invalid state events (opt-in).
        // Emit only when tls_validation_mode is observe/enforce, and dedupe to avoid repeated event spam:
        // - Create an event when the invalid classification changes OR the certificate fingerprint changes.
        if ($tlsMode !== 'off') {
            // Hostname mismatch ("wrong.host" style)
            $nowWrongHost = ($hostnameShouldUpdate === 1 && $hostnameOk === 0);
            $prevWrongHost = ((int)($m['hostname_ok'] ?? 1) === 0);
            if ($nowWrongHost && ((!$prevWrongHost) || $fpChanged)) {
                self::createEvent($monitorId, 'tls_wrong_host', 'warn', 'TLS hostname mismatch (certificate does not match host).', [
                    'host' => (string)$m['host'],
                    'port' => (int)$m['port'],
                    'fingerprint' => (string)($finger ?? ''),
                    'hostname_error' => (string)($hostnameErr ?? ''),
                ]);
            }

            // Trust failures (chain validation) — only emit named categories requested in v0.7.4.
            $nowTrustCat = null;
            if ($trustShouldUpdate === 1 && $trustOk === 0) {
                $nowTrustCat = (string)($trustCategory ?? 'tls_untrusted_unknown');
            }
            $prevTrustCat = (string)($m['trust_category'] ?? '');

            if ($nowTrustCat === 'tls_self_signed' && (($prevTrustCat !== 'tls_self_signed') || $fpChanged)) {
                self::createEvent($monitorId, 'tls_self_signed', 'warn', 'TLS trust failure: self-signed certificate.', [
                    'host' => (string)$m['host'],
                    'port' => (int)$m['port'],
                    'fingerprint' => (string)($finger ?? ''),
                    'trust_category' => $nowTrustCat,
                    'trust_error' => (string)($trustErr ?? ''),
                ]);
            } elseif ($nowTrustCat === 'tls_untrusted_root' && (($prevTrustCat !== 'tls_untrusted_root') || $fpChanged)) {
                self::createEvent($monitorId, 'tls_untrusted_root', 'warn', 'TLS trust failure: untrusted certificate chain.', [
                    'host' => (string)$m['host'],
                    'port' => (int)$m['port'],
                    'fingerprint' => (string)($finger ?? ''),
                    'trust_category' => $nowTrustCat,
                    'trust_error' => (string)($trustErr ?? ''),
                ]);
            }
        }

        // v0.7.6: Certisent-defined pinning (SPKI sha256) (opt-in).
        // Pinning is independent from tls_validation_mode.
        $pinMode = strtolower(trim((string)($m['pin_mode'] ?? 'off')));
        if (!in_array($pinMode, ['off','observe','enforce'], true)) $pinMode = 'off';
        $pinVal = TlsValidator::normalizeSpkiPin((string)($m['pin_spki_sha256'] ?? ''));
        if ($pinMode !== 'off' && $pinVal !== '' && TlsValidator::isValidSpkiPin($pinVal) && is_string($pem) && $pem !== '') {
            $spki = TlsValidator::computeSpkiSha256($pem);
            if (($spki['ok'] ?? false) === true) {
                $obs = (string)($spki['sha256_base64'] ?? '');
                if ($obs !== '' && !hash_equals($pinVal, $obs)) {
                    $sev = ($pinMode === 'enforce') ? 'critical' : 'warn';
                    if (self::shouldEmitPinMismatchEvent($monitorId, (string)($finger ?? ''), $pinVal, $obs, (bool)$fpChanged)) {
                        self::createEvent($monitorId, 'tls_pin_mismatch', $sev, 'TLS pin mismatch (SPKI sha256 does not match configured pin).', [
                            'host' => (string)$m['host'],
                            'port' => (int)$m['port'],
                            'fingerprint' => (string)($finger ?? ''),
                            'pin_mode' => $pinMode,
                            'pin_spki_sha256' => $pinVal,
                            'observed_spki_sha256' => $obs,
                        ]);
                    }
                }
            }
        }

        return $out;
    }

    private static function shouldEmitPinMismatchEvent(int $monitorId, string $fingerprint, string $pin, string $observed, bool $fpChanged): bool {
        if ($fpChanged) return true;

        $st = db()->prepare("SELECT meta_json FROM events WHERE monitor_id=:mid AND type='tls_pin_mismatch' ORDER BY created_at DESC LIMIT 1");
        $st->execute([':mid' => $monitorId]);
        $r = $st->fetch();
        if (!$r) return true;

        $meta = json_decode((string)($r['meta_json'] ?? ''), true);
        if (!is_array($meta)) return true;

        return !(
            (string)($meta['fingerprint'] ?? '') === $fingerprint &&
            (string)($meta['pin_spki_sha256'] ?? '') === $pin &&
            (string)($meta['observed_spki_sha256'] ?? '') === $observed
        );
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
            $from = cfg('MAIL_FROM_NAME','Certisent') . ' <' . cfg('MAIL_FROM','no-reply@example.com') . '>';
            $sent = mail($admin, "[Certisent] critical cron_failed", $msg, "From: " . $from);
            if (!$sent) {
                error_log('[certisent] cron_health_alert_failed: mail() returned false for admin=' . $admin);
            }
        }
    }

    /**
     * Prune old snapshots beyond the configured retention window.
     *
     * Policy:
     * - Keep all snapshots newer than SNAPSHOT_RETENTION_DAYS (default 90, 0=disabled).
     * - Always keep the most recent SNAPSHOT_KEEP_PER_MONITOR snapshots per monitor (default 10).
     * - Process in small batches to avoid long-running deletes on shared hosting.
     *
     * @return array{deleted:int,skipped_monitors:int}
     */
    public static function pruneSnapshots(int $batchSize = 500): array {
        $retentionDays = (int)cfg('SNAPSHOT_RETENTION_DAYS', 90);
        if ($retentionDays <= 0) {
            return ['deleted' => 0, 'skipped_monitors' => 0];
        }

        $keepPerMonitor = max(1, (int)cfg('SNAPSHOT_KEEP_PER_MONITOR', 10));
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($retentionDays * 86400));
        $batchSize = max(1, min(5000, $batchSize));

        $monitors = db()->query("SELECT id FROM monitors")->fetchAll();
        $totalDeleted = 0;
        $skipped = 0;

        foreach ($monitors as $mon) {
            $mid = (int)$mon['id'];

            // Count total snapshots for this monitor.
            $countSt = db()->prepare("SELECT COUNT(*) AS c FROM cert_snapshots WHERE monitor_id = :mid");
            $countSt->execute([':mid' => $mid]);
            $total = (int)($countSt->fetch()['c'] ?? 0);

            if ($total <= $keepPerMonitor) {
                $skipped++;
                continue;
            }

            // Find the fetched_at of the Nth newest snapshot (the retention floor).
            $floorSt = db()->prepare(
                "SELECT fetched_at FROM cert_snapshots WHERE monitor_id = :mid ORDER BY fetched_at DESC LIMIT 1 OFFSET :off"
            );
            $floorSt->bindValue(':mid', $mid, PDO::PARAM_INT);
            $floorSt->bindValue(':off', $keepPerMonitor - 1, PDO::PARAM_INT);
            $floorSt->execute();
            $floorRow = $floorSt->fetch();
            if (!$floorRow) {
                $skipped++;
                continue;
            }
            $floorDate = (string)$floorRow['fetched_at'];

            // Delete snapshots older than both the cutoff AND the per-monitor floor.
            // Uses a sub-select for portability (MySQL DELETE...LIMIT is non-standard).
            $delSt = db()->prepare(
                "DELETE FROM cert_snapshots WHERE id IN (
                    SELECT id FROM cert_snapshots
                    WHERE monitor_id = :mid AND fetched_at < :cutoff AND fetched_at < :floor
                    ORDER BY fetched_at ASC LIMIT :lim
                )"
            );
            $delSt->bindValue(':mid', $mid, PDO::PARAM_INT);
            $delSt->bindValue(':cutoff', $cutoff);
            $delSt->bindValue(':floor', $floorDate);
            $delSt->bindValue(':lim', $batchSize, PDO::PARAM_INT);
            $delSt->execute();
            $totalDeleted += $delSt->rowCount();
        }

        if ($totalDeleted > 0) {
            self::createSystemEvent('snapshots_pruned', 'info', "Pruned {$totalDeleted} old snapshot(s).", [
                'deleted' => $totalDeleted,
                'retention_days' => $retentionDays,
                'keep_per_monitor' => $keepPerMonitor,
            ]);
        }

        return ['deleted' => $totalDeleted, 'skipped_monitors' => $skipped];
    }

    /**
     * Reconcile denormalized "last known" fields on the monitors table.
     *
     * If the worker crashes between inserting a snapshot and updating the monitor row,
     * the denormalized state becomes stale. This routine detects and repairs that drift.
     *
     * @return array{checked:int,repaired:int}
     */
    public static function reconcileDenormalized(): array {
        $sql = "SELECT m.id,
                       m.last_checked_at AS m_last_checked,
                       m.last_fingerprint_sha256 AS m_fp,
                       m.last_status AS m_status,
                       m.last_days_remaining AS m_days,
                       cs.fetched_at AS s_fetched,
                       cs.fingerprint_sha256 AS s_fp,
                       cs.status AS s_status,
                       cs.days_remaining AS s_days,
                       cs.issuer_cn AS s_issuer,
                       cs.subject_cn AS s_subject,
                       cs.valid_from AS s_vf,
                       cs.valid_to AS s_vt,
                       cs.error AS s_err
                FROM monitors m
                INNER JOIN (
                    SELECT monitor_id, MAX(fetched_at) AS max_fetched
                    FROM cert_snapshots
                    GROUP BY monitor_id
                ) latest ON latest.monitor_id = m.id
                INNER JOIN cert_snapshots cs
                    ON cs.monitor_id = latest.monitor_id AND cs.fetched_at = latest.max_fetched
                WHERE m.enabled = 1";
        $rows = db()->query($sql)->fetchAll();

        $checked = 0;
        $repaired = 0;

        foreach ($rows as $row) {
            $checked++;
            $mid = (int)$row['id'];

            // Detect drift: fingerprint mismatch or last_checked_at behind latest snapshot.
            $fpDrift = ((string)($row['m_fp'] ?? '')) !== ((string)($row['s_fp'] ?? ''));
            $timeDrift = ($row['m_last_checked'] === null && $row['s_fetched'] !== null)
                || ($row['m_last_checked'] !== null && $row['s_fetched'] !== null
                    && strtotime((string)$row['s_fetched'] . ' UTC') > strtotime((string)$row['m_last_checked'] . ' UTC'));
            $statusDrift = ((string)($row['m_status'] ?? '')) !== ((string)($row['s_status'] ?? ''));

            if (!$fpDrift && !$timeDrift && !$statusDrift) {
                continue;
            }

            $up = db()->prepare("UPDATE monitors SET
                last_checked_at = :c,
                last_status = :st,
                last_fingerprint_sha256 = :fp,
                last_issuer_cn = :issuer,
                last_valid_from = :vf,
                last_valid_to = :vt,
                last_days_remaining = :days,
                last_error = :err,
                updated_at = :u
                WHERE id = :id");
            $up->execute([
                ':c' => $row['s_fetched'],
                ':st' => $row['s_status'],
                ':fp' => $row['s_fp'],
                ':issuer' => $row['s_issuer'],
                ':vf' => $row['s_vf'],
                ':vt' => $row['s_vt'],
                ':days' => $row['s_days'],
                ':err' => $row['s_err'],
                ':u' => db_now_utc(),
                ':id' => $mid,
            ]);
            $repaired++;
        }

        if ($repaired > 0) {
            self::createSystemEvent('monitors_reconciled', 'info', "Reconciled {$repaired} monitor(s) with stale denormalized data.", [
                'checked' => $checked,
                'repaired' => $repaired,
            ]);
        }

        return ['checked' => $checked, 'repaired' => $repaired];
    }
}
