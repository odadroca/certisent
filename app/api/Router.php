<?php
declare(strict_types=1);

final class ApiRouter {

    public static function handle(): void {
        header('Content-Type: application/json; charset=utf-8');

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = $_SERVER['REQUEST_URI'] ?? '/';
        // Strip query string
        $path = explode('?', $path, 2)[0];

        // Expect /api/v1/...
        $idx = strpos($path, '/api/v1/');
        $sub = $idx === false ? '/' : substr($path, $idx + strlen('/api/v1/'));
        $sub = '/' . ltrim($sub, '/');

        // v0.5.7: coarse rate limiting (defaults high). Applies to all /api/v1/*.
        $rl = RateLimiter::checkApi(client_ip(), bearer_token_from_headers());
        if (!$rl['allowed']) {
            http_response_code(429);
            echo json_encode([
                'ok' => false,
                'error' => 'rate_limited',
                'scope' => $rl['scope'] ?? 'api',
                'retry_after' => (int)($rl['retry_after'] ?? 1),
            ], JSON_UNESCAPED_SLASHES);
            return;
        }

        if ($method === 'POST' && $sub === '/worker/run') {
            require_api_scope('run_worker');
            self::postWorkerRun();
            return;
        }
        if ($method === 'POST' && $sub === '/check') {
            $apiKey = require_api_scope('check_monitor');
            self::postCheck($apiKey);
            return;
        }
        if ($method === 'GET' && $sub === '/health') {
            require_api_any_scope(['run_worker','read_health']);
            echo json_encode(['ok'=>true,'time_utc'=>db_now_utc(),'last_cron_run_at'=>Worker::getSystemState('last_cron_run_at')]);
            return;
        }

        http_response_code(404);
        echo json_encode(['ok'=>false,'error'=>'not_found','path'=>$sub]);
    }

    private static function readJson(): array {
        $raw = file_get_contents('php://input');
        if (!$raw) return [];
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }

    private static function postWorkerRun(): void {
        $d = self::readJson();
        $mode = (string)($d['mode'] ?? 'due');
        $limit = isset($d['limit']) ? (int)$d['limit'] : null;

        try {
            if ($mode === 'all') {
                $res = Worker::runAllChecks($limit);
            } else {
                $res = Worker::runDueChecks($limit);
            }
            echo json_encode(['ok'=>true,'result'=>$res], JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            Worker::setSystemState('last_cron_run_at', db_now_utc());
            Worker::setSystemState('last_cron_ok', '0');
            http_response_code(500);
            echo json_encode(['ok'=>false,'error'=>'exception','message'=>$e->getMessage()]);
        }
    }

    private static function postCheck(array $apiKey): void {
        $d = self::readJson();
        $monitorId = isset($d['monitor_id']) ? (int)$d['monitor_id'] : null;
        $url = isset($d['url']) ? (string)$d['url'] : null;

        if ($monitorId) {
            // v0.5.6: user-scoped API keys must only operate on monitors owned by the key owner.
            $keyType = (string)($apiKey['key_type'] ?? 'system');
            $ownerId = isset($apiKey['owner_user_id']) ? (int)$apiKey['owner_user_id'] : 0;
            if ($keyType === 'user' || $keyType === 'user_scoped') {
                if ($ownerId <= 0) {
                    http_response_code(403);
                    echo json_encode(['ok'=>false,'error'=>'api_key_owner_required']);
                    return;
                }
                $st = db()->prepare('SELECT user_id FROM monitors WHERE id = :id LIMIT 1');
                $st->execute([':id'=>$monitorId]);
                $m = $st->fetch();
                if (!$m) {
                    http_response_code(404);
                    echo json_encode(['ok'=>false,'error'=>'monitor_not_found']);
                    return;
                }
                if ((int)$m['user_id'] !== $ownerId) {
                    http_response_code(403);
                    echo json_encode(['ok'=>false,'error'=>'forbidden_monitor']);
                    return;
                }
            }
            $out = Worker::checkOne($monitorId);
            echo json_encode(['ok'=>true,'monitor_id'=>$monitorId,'result'=>$out], JSON_UNESCAPED_SLASHES);
            return;
        }

        if ($url) {
            try {
                $p = MonitorService::parseUrl($url);
                $fetch = CertFetcher::fetch($p['host'], $p['port']);
                if (!$fetch['ok']) {
                    http_response_code(502);
                    echo json_encode(['ok'=>false,'error'=>$fetch['error']], JSON_UNESCAPED_SLASHES);
                    return;
                }
                $parsed = $fetch['parsed'] ?? null;
                $vf = $parsed['validFrom_time_t'] ?? null;
                $vt = $parsed['validTo_time_t'] ?? null;
                $summary = [
                    'url' => $p['url'],
                    'host' => $p['host'],
                    'port' => $p['port'],
                    'fingerprint_sha256' => $fetch['fingerprint_sha256'] ?? null,
                    'issuer_cn' => $parsed['issuer']['CN'] ?? null,
                    'subject_cn' => $parsed['subject']['CN'] ?? null,
                    'valid_from' => $vf ? gmdate('c', (int)$vf) : null,
                    'valid_to' => $vt ? gmdate('c', (int)$vt) : null,
                ];
                echo json_encode(['ok'=>true,'summary'=>$summary], JSON_UNESCAPED_SLASHES);
            } catch (Throwable $e) {
                http_response_code(400);
                echo json_encode(['ok'=>false,'error'=>'bad_request','message'=>$e->getMessage()]);
            }
            return;
        }

        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'bad_request','message'=>'monitor_id or url required']);
    }
}
