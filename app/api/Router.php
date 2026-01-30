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

        if ($method === 'POST' && $sub === '/worker/run') {
            require_api_scope('run_worker');
            self::postWorkerRun();
            return;
        }
        if ($method === 'POST' && $sub === '/check') {
            require_api_scope('check_monitor');
            self::postCheck();
            return;
        }
        if ($method === 'GET' && $sub === '/health') {
            require_api_scope('run_worker');
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

    private static function postCheck(): void {
        $d = self::readJson();
        $monitorId = isset($d['monitor_id']) ? (int)$d['monitor_id'] : null;
        $url = isset($d['url']) ? (string)$d['url'] : null;

        if ($monitorId) {
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
