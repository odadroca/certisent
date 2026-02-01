<?php
declare(strict_types=1);

final class Notifier {

    private static ?bool $hasRepeatCountCol = null;

    private static function hasRepeatCountColumn(): bool {
        if (self::$hasRepeatCountCol !== null) return self::$hasRepeatCountCol;
        if (function_exists('db_has_column')) {
            try {
                self::$hasRepeatCountCol = (bool)db_has_column('users', 'notify_repeat_count');
            } catch (Throwable $e) {
                self::$hasRepeatCountCol = false;
            }
        } else {
            self::$hasRepeatCountCol = false;
        }
        return self::$hasRepeatCountCol;
    }


    /**
     * Enqueue notifications for a given event (reliable delivery).
     *
     * Dedupe is enforced via dedupe_key unique index.
     */
    public static function enqueueForEvent(int $userId, int $eventId, ?int $monitorId, string $eventType, array $meta): void {
        $user = self::getUser($userId);
        if (!$user) return;

        $channels = json_decode($user['notify_channels_json'] ?? '{}', true);
        if (!is_array($channels)) $channels = [];

        $enabled = [];
        // Email defaults to enabled.
        if (($channels['email'] ?? true) && !empty($user['email'])) {
            $enabled[] = 'email';
        }
        if (!empty($channels['slack_webhook'])) {
            $enabled[] = 'slack';
        }
        if (!empty($channels['teams_webhook'])) {
            $enabled[] = 'teams';
        }

        if (!$enabled) return;

        $keyMaterial = '';
        if (isset($meta['new_fingerprint'])) $keyMaterial = (string)$meta['new_fingerprint'];
        elseif (isset($meta['fingerprint'])) $keyMaterial = (string)$meta['fingerprint'];
        elseif (isset($meta['valid_to'])) $keyMaterial = (string)$meta['valid_to'];

        $repeat = 1;
        if (isset($user['notify_repeat_count'])) {
            $repeat = (int)$user['notify_repeat_count'];
        }
        $repeat = max(1, min(5, $repeat));

        foreach ($enabled as $ch) {
            for ($i = 1; $i <= $repeat; $i++) {
                $parts = [
                    (string)$userId,
                    $ch,
                    (string)($monitorId ?? 0),
                    $eventType,
                    $keyMaterial,
                ];
                if ($repeat > 1) {
                    $parts[] = 'repeat=' . (string)$i;
                }

                $dedupeKey = hash('sha256', implode('|', $parts));

                $now = db_now_utc();
                $st = db()->prepare('INSERT IGNORE INTO notification_outbox
                    (user_id, monitor_id, event_id, channel, status, attempts, next_retry_at, last_error, dedupe_key, created_at, updated_at)
                    VALUES
                    (:uid,:mid,:eid,:ch,\'pending\',0,NULL,NULL,:dk,:c,:u)');
                $st->execute([
                    ':uid'=>$userId,
                    ':mid'=>$monitorId,
                    ':eid'=>$eventId,
                    ':ch'=>$ch,
                    ':dk'=>$dedupeKey,
                    ':c'=>$now,
                    ':u'=>$now,
                ]);
            }
        }
    }

    /**
     * Process pending outbox entries.
     * @return array{processed:int,sent:int,failed:int,pending:int}
     */
    public static function processOutbox(int $limit = 100): array {
        $now = db_now_utc();
        $limit = max(1, min(1000, $limit));
        $sql = "SELECT o.*, u.email, u.notify_channels_json, u.locale
                             FROM notification_outbox o
                             JOIN users u ON u.id=o.user_id
                             WHERE o.status='pending'
                               AND (o.next_retry_at IS NULL OR o.next_retry_at <= :now)
                             ORDER BY o.created_at ASC
                             LIMIT {$limit}";
        $st = db()->prepare($sql);
        $st->execute([':now' => $now]);
        $rows = $st->fetchAll();

        $processed = 0; $sent = 0; $failed = 0;

        foreach ($rows as $r) {
            $processed++;
            $ok = false;
            $err = null;

            try {
                $event = self::getEvent((int)$r['event_id']);
                $monitor = $r['monitor_id'] ? MonitorService::getMonitorById((int)$r['monitor_id']) : null;

                $channels = json_decode($r['notify_channels_json'] ?? '{}', true);
                if (!is_array($channels)) $channels = [];

                $ch = (string)$r['channel'];

                $loc = 'en';
                if (isset($r['locale']) && function_exists('normalize_locale')) {
                    $loc = normalize_locale((string)$r['locale']);
                } elseif (isset($r['locale'])) {
                    $loc = (string)$r['locale'];
                }
                $loc = is_string($loc) && $loc !== '' ? $loc : 'en';

                if ($ch === 'email') {
                    if (empty($r['email'])) {
                        $err = 'missing_email';
                    } else {
                        $send = Emailer::sendText((string)$r['email'], self::emailSubject($event, $monitor, $loc), self::renderEmailBody($event, $monitor, $loc));
                        $ok = (bool)($send['ok'] ?? false);
                        if (!$ok) $err = (string)($send['error'] ?? 'mail_failed');
                    }
                } elseif ($ch === 'slack') {
                    $url = (string)($channels['slack_webhook'] ?? '');
                    if ($url === '') {
                        $err = 'missing_slack_webhook';
                    } else {
                        [$ok, $err] = self::sendWebhook($url, ['text' => self::renderWebhookText($event, $monitor, $loc)]);
                    }
                } elseif ($ch === 'teams') {
                    $url = (string)($channels['teams_webhook'] ?? '');
                    if ($url === '') {
                        $err = 'missing_teams_webhook';
                    } else {
                        $payload = [
                            '@type' => 'MessageCard',
                            '@context' => 'http://schema.org/extensions',
                            'summary' => self::tr('notify.teams.summary', [], $loc, 'Certisent alert'),
                            'title' => self::tr('notify.teams.title', ['type' => (string)($event['type'] ?? 'event')], $loc, 'Certisent: '.(string)($event['type'] ?? 'event')),
                            'text' => self::renderWebhookText($event, $monitor, $loc),
                        ];
                        [$ok, $err] = self::sendWebhook($url, $payload);
                    }
                } else {
                    $err = 'unknown_channel';
                }
            } catch (Throwable $e) {
                $ok = false;
                $err = 'exception: '.$e->getMessage();
            }

            $attempts = (int)$r['attempts'] + 1;
            $nextRetry = null;
            $status = 'pending';

            if ($ok) {
                $status = 'sent';
                $sent++;
                $err = null;
            } else {
                if ($attempts >= 5) {
                    $status = 'failed';
                    $failed++;
                    $nextRetry = null;
                } else {
                    $status = 'pending';
                    $backoff = min(3600, (int)(60 * (2 ** max(0, $attempts-1))));
                    $nextRetry = gmdate('Y-m-d H:i:s', time() + $backoff);
                }
            }

            $err = self::sanitizeError((string)$err, (string)($r['notify_channels_json'] ?? ''));

            $up = db()->prepare("UPDATE notification_outbox
                                 SET status=:st, attempts=:a, next_retry_at=:n, last_error=:e, updated_at=:u
                                 WHERE id=:id");
            $up->execute([
                ':st'=>$status,
                ':a'=>$attempts,
                ':n'=>$nextRetry,
                ':e'=>$err,
                ':u'=>db_now_utc(),
                ':id'=>$r['id'],
            ]);
        }

        $pending = (int)(db()->query("SELECT COUNT(*) c FROM notification_outbox WHERE status='pending'")->fetch()['c'] ?? 0);
        return ['processed'=>$processed,'sent'=>$sent,'failed'=>$failed,'pending'=>$pending];
    }

    private static function sanitizeError(string $err, string $channelsJson): string {
        if ($err === '') return '';
        // Avoid leaking webhook URLs in DB.
        $channels = json_decode($channelsJson, true);
        if (is_array($channels)) {
            foreach (['slack_webhook','teams_webhook'] as $k) {
                if (!empty($channels[$k]) && is_string($channels[$k])) {
                    $err = str_replace($channels[$k], '[redacted]', $err);
                }
            }
        }
        // Generic URL redact
        $err = preg_replace('#https?://\S+#', '[url]', $err);
        return mb_substr($err, 0, 512);
    }

    private static function getUser(int $id): ?array {
        $cols = 'id,email,role,notify_channels_json';
        if (self::hasRepeatCountColumn()) {
            $cols .= ',notify_repeat_count';
        }
        $st = db()->prepare('SELECT ' . $cols . ' FROM users WHERE id=:id');
        $st->execute([':id'=>$id]);
        $r = $st->fetch();
        return $r ?: null;
    }

    private static function getEvent(int $eventId): array {
        $st = db()->prepare('SELECT * FROM events WHERE id=:id');
        $st->execute([':id'=>$eventId]);
        $r = $st->fetch();
        if (!$r) return ['type'=>'event','severity'=>'info','message'=>'','created_at'=>db_now_utc()];
        return $r;
    }


private static function tr(string $key, array $params, string $locale, string $fallback): string {
    if (function_exists('t')) {
        try {
            return t($key, $params, $locale);
        } catch (Throwable $e) {
            // Fall through to fallback.
        }
    }
    $s = $fallback;
    foreach ($params as $k => $v) {
        $s = str_replace('{' . (string)$k . '}', (string)$v, $s);
    }
    return $s;
}

    private static function emailSubject(array $event, ?array $monitor, string $locale = 'en'): string {
        $host = (string)($monitor['host'] ?? 'system');
        return self::tr('notify.email.subject', [
            'severity' => (string)($event['severity'] ?? 'info'),
            'type' => (string)($event['type'] ?? 'event'),
            'host' => $host,
        ], $locale, "[Certisent] ".(string)($event['severity'] ?? 'info')." ".(string)($event['type'] ?? 'event')." — {$host}");
    }

    /**
     * @return array{0:bool,1:?string}
     */
    private static function sendWebhook(string $url, array $payload): array {
        $policy = SsrfPolicy::evaluateWebhookUrl($url);
        if (!($policy['ok'] ?? false)) {
            $reason = (string)($policy['reason'] ?? 'blocked');
            return [false, 'webhook_ssrf_blocked: ' . $reason];
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        // Prevent redirect-based bypass and reduce protocol ambiguity.
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 0);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            // In legacy mode we preserve prior behavior; SsrfPolicy already required https for non-legacy.
            $wm = strtolower(trim((string)cfg('WEBHOOK_MODE', 'legacy')));
            if ($wm !== 'legacy') {
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
                if (defined('CURLOPT_REDIR_PROTOCOLS')) {
                    curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
                }
            }
        }

        $resp = curl_exec($ch);
        $errNo = curl_errno($ch);
        $errMsg = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errNo !== 0) {
            return [false, "curl_errno={$errNo} {$errMsg}"];
        }
        if ($code < 200 || $code >= 300) {
            $snippet = is_string($resp) ? mb_substr($resp, 0, 200) : '';
            return [false, "http={$code} resp={$snippet}"];
        }
        return [true, null];
    }

    private static function renderEmailBody(array $event, ?array $monitor, string $locale = 'en'): string {
    $lines = [];
    $lines[] = self::tr('notify.email.line.event', [
        'type' => (string)($event['type'] ?? 'event'),
        'severity' => (string)($event['severity'] ?? 'info'),
    ], $locale, "Event: ".(string)($event['type'] ?? 'event')." (".(string)($event['severity'] ?? 'info').")");

    $lines[] = self::tr('notify.email.line.time', [
        'time' => (string)($event['created_at'] ?? db_now_utc()),
    ], $locale, "Time: ".(string)($event['created_at'] ?? db_now_utc())." UTC");

    if ($monitor) {
        $lines[] = self::tr('notify.email.line.target', [
            'target' => (string)($monitor['url'] ?? ''),
        ], $locale, "Target: ".(string)($monitor['url'] ?? ''));
    }

    $lines[] = "";
    $lines[] = (string)($event['message'] ?? '');

    $meta = self::formatEventMeta($event['meta_json'] ?? null);
    if ($meta !== '') {
        $lines[] = "";
        $lines[] = self::tr('notify.email.line.meta', [
            'meta' => (string)$meta,
        ], $locale, "Meta: {$meta}");
    }

    $lines[] = "";
    $lines[] = self::tr('notify.email.signature', [], $locale, 'Certisent');

    return implode("\n", $lines);
}


    
    /** @return array<string,mixed> */
    private static function metaArray(?string $metaJson): array {
        if (!$metaJson) return [];
        $arr = json_decode($metaJson, true);
        return is_array($arr) ? $arr : [];
    }

    /**
     * Human-readable meta summary for notifications.
     *
     * Note: Mirrors the UI helper but is defined here to avoid a hard dependency on app/ui.php
     * (workers/API do not load UI helpers).
     */
    private static function formatEventMeta(?string $metaJson): string {
        $m = self::metaArray($metaJson);
        if (!$m) return '';

        $parts = [];

        // Worker run summary
        $hasRun = isset($m['checked']) || isset($m['errors']) || isset($m['changed']) || isset($m['renewed']) || isset($m['warned']);
        if ($hasRun) {
            $parts[] = 'checked=' . (int)($m['checked'] ?? 0);
            $parts[] = 'errors=' . (int)($m['errors'] ?? 0);
            $parts[] = 'changed=' . (int)($m['changed'] ?? 0);
            $parts[] = 'renewed=' . (int)($m['renewed'] ?? 0);
            $parts[] = 'warned=' . (int)($m['warned'] ?? 0);
            if (isset($m['duration_ms'])) $parts[] = 'ms=' . (int)$m['duration_ms'];
        }

        // Change/renew confirmation
        if (isset($m['confirm_result'])) $parts[] = 'confirm=' . (string)$m['confirm_result'];
        if (isset($m['confirm_samples'])) $parts[] = 'samples=' . (int)$m['confirm_samples'];

        if (isset($m['observed_fingerprints']) && is_array($m['observed_fingerprints'])) {
            $fps = array_values(array_unique(array_map('strval', $m['observed_fingerprints'])));
            $parts[] = 'observed_fp=' . count($fps);
            $fps = array_slice($fps, 0, 3);
            $short = array_map(function($x){ return substr($x, 0, 12) . '…'; }, $fps);
            $parts[] = 'observed=' . implode(',', $short);
        }

        // Renewal timing
        if (isset($m['early_renewal_days'])) $parts[] = 'early_renewal_days=' . (int)$m['early_renewal_days'];
        if (isset($m['prev_valid_to'])) $parts[] = 'prev_valid_to=' . (string)$m['prev_valid_to'];
        if (isset($m['new_valid_to'])) $parts[] = 'new_valid_to=' . (string)$m['new_valid_to'];

        // Error (short)
        if (isset($m['error'])) $parts[] = 'error=' . (string)$m['error'];

        // Fingerprints are long; show only a prefix.
        if (isset($m['prev_fingerprint'])) $parts[] = 'prev_fp=' . substr((string)$m['prev_fingerprint'], 0, 12) . '…';
        if (isset($m['new_fingerprint'])) $parts[] = 'new_fp=' . substr((string)$m['new_fingerprint'], 0, 12) . '…';

        if (!$parts) {
            $j = json_encode($m, JSON_UNESCAPED_SLASHES);
            return $j ? $j : '';
        }
        return implode(' | ', $parts);
    }

private static function renderWebhookText(array $event, ?array $monitor, string $locale = 'en'): string {
    $target = $monitor ? (string)($monitor['url'] ?? '') : 'system';
    $meta = self::formatEventMeta($event['meta_json'] ?? null);
    $metaPart = $meta ? " | {$meta}" : '';

    return self::tr('notify.webhook.text', [
        'severity' => (string)($event['severity'] ?? 'info'),
        'type' => (string)($event['type'] ?? 'event'),
        'target' => $target,
        'message' => (string)($event['message'] ?? ''),
        'time' => (string)($event['created_at'] ?? db_now_utc()),
        'meta_part' => $metaPart,
    ], $locale, (string)($event['severity'] ?? 'info')." ".(string)($event['type'] ?? 'event')." — {$target} — ".(string)($event['message'] ?? '')." (UTC ".(string)($event['created_at'] ?? db_now_utc())."){$metaPart}");
}

}
