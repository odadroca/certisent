<?php
declare(strict_types=1);

final class Worker {

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

    public static function runDueChecks(?int $limit = null): array {
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
        return $results;
    }

    public static function runAllChecks(?int $limit = null): array {
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
        return $results;
    }

    private static function isDue(int $monitorId): bool {
        $m = MonitorService::getMonitorById($monitorId);
        if (!$m) return false;
        $freq = max(5, (int)$m['check_frequency_minutes']); // safety clamp
        $last = MonitorService::getLatestSnapshot($monitorId);
        if (!$last) return true;
        $lastTs = strtotime((string)$last['fetched_at'] . ' UTC');
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

        $out = ['errors'=>0,'changed'=>0,'renewed'=>0,'warned'=>0];

        // Evaluate events
        if ($status === 'critical' && $err !== null) {
            self::createEvent($monitorId, 'check_failed', 'critical', "TLS fetch failed: {$err}", ['error'=>$err]);
            $out['errors'] = 1;
            return $out;
        }

        // Change/Renewal detection
        if ($prev && $finger && !empty($prev['fingerprint_sha256']) && $prev['fingerprint_sha256'] !== $finger) {
            $samples = max(1, (int)cfg('TLS_SAMPLES_ON_CHANGE', 2));
            $confirmOk = self::confirmChange($m, $finger);
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
                    'confirm_result' => 'unstable'
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

    private static function confirmChange(array $monitorRow, string $newFingerprint): bool {
        $samples = max(1, (int)cfg('TLS_SAMPLES_ON_CHANGE', 2));
        for ($i=0; $i<$samples; $i++) {
            $f = CertFetcher::fetch((string)$monitorRow['host'], (int)$monitorRow['port']);
            if (!$f['ok'] || empty($f['fingerprint_sha256'])) return false;
            if ($f['fingerprint_sha256'] !== $newFingerprint) return false;
        }
        return true;
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

        // Notify monitor owner (and only owner in v0).
        $m = MonitorService::getMonitorById($monitorId);
        if ($m) {
            Notifier::sendEvent((int)$m['user_id'], [
                'type'=>$type,
                'severity'=>$severity,
                'message'=>$message,
                'created_at'=>$created
            ], $m);
        }
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
