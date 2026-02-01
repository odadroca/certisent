<?php
declare(strict_types=1);

final class MonitorService {

    public static function parseUrl(string $url): array {
        $url = trim($url);
        if ($url === '') throw new RuntimeException('Empty URL');

        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        $p = parse_url($url);
        if (!$p || empty($p['host'])) throw new RuntimeException('Invalid URL');
        $scheme = strtolower($p['scheme'] ?? 'https');
        if ($scheme !== 'https') throw new RuntimeException('Only https is supported in v0');

        $host = $p['host'];
        $port = (int)($p['port'] ?? 443);

        $norm = 'https://' . $host . ($port !== 443 ? (':' . $port) : '') . '/';
        return ['url'=>$norm, 'host'=>$host, 'port'=>$port];
    }

    public static function addMonitor(int $userId, string $url, int $notifyDays): int {
        $parsed = self::parseUrl($url);

        $st = db()->prepare('INSERT INTO monitors (user_id,url,host,port,enabled,created_at,updated_at) VALUES (:uid,:url,:host,:port,1,:c,:u)');
        $now = db_now_utc();
        $st->execute([
            ':uid'=>$userId,
            ':url'=>$parsed['url'],
            ':host'=>$parsed['host'],
            ':port'=>$parsed['port'],
            ':c'=>$now,
            ':u'=>$now,
        ]);
        $mid = (int)db()->lastInsertId();

        $st2 = db()->prepare('INSERT INTO monitor_settings (monitor_id, notify_days_before_expiry, check_frequency_minutes, notify_on_change, notify_on_renewal) VALUES (:mid,:days,60,1,1)');
        $st2->execute([':mid'=>$mid, ':days'=>$notifyDays]);

        Audit::log($userId, 'monitor.create', 'monitor', $mid, ['url'=>$parsed['url']]);
        return $mid;
    }

    public static function updateMonitor(
        int $actorUserId,
        int $monitorId,
        string $url,
        int $notifyDays,
        int $freqMin,
        int $enabled,
        ?int $notifyOnChange = null,
        ?int $notifyOnRenewal = null
    ): void {
        $parsed = self::parseUrl($url);
        $now = db_now_utc();

        $st = db()->prepare('UPDATE monitors SET url=:url, host=:host, port=:port, enabled=:en, updated_at=:u WHERE id=:id');
        $st->execute([
            ':url'=>$parsed['url'],
            ':host'=>$parsed['host'],
            ':port'=>$parsed['port'],
            ':en'=>$enabled ? 1 : 0,
            ':u'=>$now,
            ':id'=>$monitorId,
        ]);

        $fields = 'notify_days_before_expiry=:d, check_frequency_minutes=:f';
        $params = [':d'=>$notifyDays, ':f'=>$freqMin, ':id'=>$monitorId];
        if ($notifyOnChange !== null) {
            $fields .= ', notify_on_change=:noc';
            $params[':noc'] = $notifyOnChange ? 1 : 0;
        }
        if ($notifyOnRenewal !== null) {
            $fields .= ', notify_on_renewal=:nor';
            $params[':nor'] = $notifyOnRenewal ? 1 : 0;
        }
        $st2 = db()->prepare('UPDATE monitor_settings SET '.$fields.' WHERE monitor_id=:id');
        $st2->execute($params);

        $meta = ['url'=>$parsed['url'], 'enabled'=>$enabled ? 1 : 0, 'notify_days_before_expiry'=>$notifyDays, 'check_frequency_minutes'=>$freqMin];
        if ($notifyOnChange !== null) $meta['notify_on_change'] = $notifyOnChange ? 1 : 0;
        if ($notifyOnRenewal !== null) $meta['notify_on_renewal'] = $notifyOnRenewal ? 1 : 0;
        Audit::log($actorUserId, 'monitor.update', 'monitor', $monitorId, $meta);
    }

    public static function deleteMonitor(int $actorUserId, int $monitorId): void {
        $st = db()->prepare('DELETE FROM monitors WHERE id=:id');
        $st->execute([':id'=>$monitorId]);
        Audit::log($actorUserId, 'monitor.delete', 'monitor', $monitorId, []);
    }

    public static function getMonitorsForUser(array $user): array {
        if ($user['role'] === 'admin' || $user['role'] === 'auditor') {
            $sql = "SELECT m.*, s.notify_days_before_expiry, s.check_frequency_minutes,
                    m.last_status AS last_status,
                    m.last_days_remaining AS last_days_remaining,
                    m.last_valid_to AS last_valid_to,
                    m.last_checked_at AS last_checked_at,
                    CASE WHEN m.last_checked_at IS NULL THEN NULL ELSE DATE_ADD(m.last_checked_at, INTERVAL s.check_frequency_minutes MINUTE) END AS next_due_at
                    FROM monitors m
                    JOIN monitor_settings s ON s.monitor_id=m.id
                    ORDER BY m.updated_at DESC";
            return db()->query($sql)->fetchAll();
        }
        $st = db()->prepare("SELECT m.*, s.notify_days_before_expiry, s.check_frequency_minutes,
                m.last_status AS last_status,
                m.last_days_remaining AS last_days_remaining,
                m.last_valid_to AS last_valid_to,
                m.last_checked_at AS last_checked_at,
                CASE WHEN m.last_checked_at IS NULL THEN NULL ELSE DATE_ADD(m.last_checked_at, INTERVAL s.check_frequency_minutes MINUTE) END AS next_due_at
                FROM monitors m
                JOIN monitor_settings s ON s.monitor_id=m.id
                WHERE m.user_id=:uid
                ORDER BY m.updated_at DESC");
        $st->execute([':uid'=>$user['id']]);
        return $st->fetchAll();
    }

    public static function getMonitorById(int $monitorId): ?array {
        $st = db()->prepare("SELECT m.*, s.notify_days_before_expiry, s.check_frequency_minutes, s.notify_on_change, s.notify_on_renewal, s.tls_validation_mode
                             FROM monitors m JOIN monitor_settings s ON s.monitor_id=m.id WHERE m.id=:id");
        $st->execute([':id'=>$monitorId]);
        $r = $st->fetch();
        return $r ?: null;
    }

    public static function getLatestSnapshot(int $monitorId): ?array {
        $st = db()->prepare("SELECT * FROM cert_snapshots WHERE monitor_id=:id ORDER BY fetched_at DESC LIMIT 1");
        $st->execute([':id'=>$monitorId]);
        $r = $st->fetch();
        return $r ?: null;
    }
}
