<?php
declare(strict_types=1);

final class Notifier {

    public static function sendEvent(int $userId, array $eventRow, array $monitorRow): void {
        $user = self::getUser($userId);
        if (!$user) return;

        $channels = json_decode($user['notify_channels_json'] ?? '{}', true);
        if (!is_array($channels)) $channels = [];

        // Always allow email if configured and user has email.
        if (($channels['email'] ?? true) && !empty($user['email'])) {
            self::sendEmail(
                to: $user['email'],
                subject: "[Certinel] {$eventRow['severity']} {$eventRow['type']} — {$monitorRow['host']}",
                body: self::renderEmailBody($eventRow, $monitorRow)
            );
        }

        if (!empty($channels['slack_webhook'])) {
            self::sendWebhook(
                url: (string)$channels['slack_webhook'],
                payload: ['text' => self::renderWebhookText($eventRow, $monitorRow)]
            );
        }

        if (!empty($channels['teams_webhook'])) {
            // Simple Teams connector payload (not exhaustive).
            self::sendWebhook(
                url: (string)$channels['teams_webhook'],
                payload: [
                    '@type' => 'MessageCard',
                    '@context' => 'http://schema.org/extensions',
                    'summary' => 'Certinel alert',
                    'themeColor' => ($eventRow['severity'] === 'critical' ? 'ff0000' : ($eventRow['severity']==='warn' ? 'ffa500' : '2eb886')),
                    'title' => "Certinel: {$eventRow['type']}",
                    'text' => self::renderWebhookText($eventRow, $monitorRow),
                ]
            );
        }
    }

    private static function getUser(int $id): ?array {
        $st = db()->prepare('SELECT id,email,role,notify_channels_json FROM users WHERE id=:id');
        $st->execute([':id'=>$id]);
        $r = $st->fetch();
        return $r ?: null;
    }

    private static function sendEmail(string $to, string $subject, string $body): void {
        $from = cfg('MAIL_FROM', 'no-reply@example.com');
        $fromName = cfg('MAIL_FROM_NAME', 'Certinel');
        $headers = [];
        $headers[] = "From: {$fromName} <{$from}>";
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Content-Type: text/plain; charset=utf-8";

        // Shared hosting: mail() availability varies.
        @mail($to, $subject, $body, implode("\r\n", $headers));
    }

    private static function sendWebhook(string $url, array $payload): void {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        @curl_exec($ch);
        @curl_close($ch);
    }

    private static function renderEmailBody(array $event, array $monitor): string {
        $lines = [];
        $lines[] = "Event: {$event['type']} ({$event['severity']})";
        $lines[] = "Target: {$monitor['url']}";
        $lines[] = "Time: {$event['created_at']} UTC";
        $lines[] = "";
        $lines[] = $event['message'];
        $lines[] = "";
        $lines[] = "Certinel";
        return implode("\n", $lines);
    }

    private static function renderWebhookText(array $event, array $monitor): string {
        return "{$event['severity']} {$event['type']} — {$monitor['url']} — {$event['message']} (UTC {$event['created_at']})";
    }
}
