<?php
declare(strict_types=1);

final class Notifier {

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

        foreach ($enabled as $ch) {
            $dedupeKey = hash('sha256', implode('|', [
                (string)$userId,
                $ch,
                (string)($monitorId ?? 0),
                $eventType,
                $keyMaterial,
            ]));

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

    /**
     * Process pending outbox entries.
     * @return array{processed:int,sent:int,failed:int,pending:int}
     */
    public static function processOutbox(int $limit = 100): array {
        $now = db_now_utc();
        $st = db()->prepare("SELECT o.*, u.email, u.notify_channels_json
                             FROM notification_outbox o
                             JOIN users u ON u.id=o.user_id
                             WHERE o.status='pending'
                               AND (o.next_retry_at IS NULL OR o.next_retry_at <= :now)
                             ORDER BY o.created_at ASC
                             LIMIT :lim");
        $st->bindValue(':now', $now);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();
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

                if ($ch === 'email') {
                    if (empty($r['email'])) {
                        $err = 'missing_email';
                    } else {
                        $send = Emailer::sendText((string)$r['email'], self::emailSubject($event, $monitor), self::renderEmailBody($event, $monitor));
                        $ok = (bool)($send['ok'] ?? false);
                        if (!$ok) $err = (string)($send['error'] ?? 'mail_failed');
                    }
                } elseif ($ch === 'slack') {
                    $url = (string)($channels['slack_webhook'] ?? '');
                    if ($url === '') {
                        $err = 'missing_slack_webhook';
                    } else {
                        [$ok, $err] = self::sendWebhook($url, ['text' => self::renderWebhookText($event, $monitor)]);
                    }
                } elseif ($ch === 'teams') {
                    $url = (string)($channels['teams_webhook'] ?? '');
                    if ($url === '') {
                        $err = 'missing_teams_webhook';
                    } else {
                        $payload = [
                            '@type' => 'MessageCard',
                            '@context' => 'http://schema.org/extensions',
                            'summary' => 'Certinel alert',
                            'title' => 'Certinel: '.($event['type'] ?? 'event'),
                            'text' => self::renderWebhookText($event, $monitor),
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
        $st = db()->prepare('SELECT id,email,role,notify_channels_json FROM users WHERE id=:id');
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

    private static function emailSubject(array $event, ?array $monitor): string {
        $host = $monitor['host'] ?? 'system';
        return "[Certinel] {$event['severity']} {$event['type']} — {$host}";
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

    private static function renderEmailBody(array $event, ?array $monitor): string {
        $lines = [];
        $lines[] = "Event: {$event['type']} ({$event['severity']})";
        $lines[] = "Time: {$event['created_at']} UTC";
        if ($monitor) {
            $lines[] = "Target: {$monitor['url']}";
        }
        $lines[] = "";
        $lines[] = (string)($event['message'] ?? '');
        $meta = format_event_meta($event['meta_json'] ?? null);
        if ($meta !== '') {
            $lines[] = "";
            $lines[] = "Meta: {$meta}";
        }
        $lines[] = "";
        $lines[] = "Certinel";
        return implode("\n", $lines);
    }

    private static function renderWebhookText(array $event, ?array $monitor): string {
        $target = $monitor ? (string)$monitor['url'] : 'system';
        $meta = format_event_meta($event['meta_json'] ?? null);
        $metaPart = $meta ? " | {$meta}" : '';
        return "{$event['severity']} {$event['type']} — {$target} — {$event['message']} (UTC {$event['created_at']}){$metaPart}";
    }
}
