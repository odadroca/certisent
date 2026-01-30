<?php
declare(strict_types=1);

/**
 * Minimal file logger for shared hosting.
 * - Writes to app/logs/app.log (best-effort)
 * - Includes a correlation id per incident.
 * - Provides dedicated non-verbose error pages for config and DB outages.
 */

function log_path(): string {
    return __DIR__ . '/logs/app.log';
}

/** @return string correlation id */
function log_error(string $level, string $message, array $context = []): string {
    $cid = bin2hex(random_bytes(6));
    $line = [
        'ts_utc' => gmdate('Y-m-d H:i:s'),
        'level' => $level,
        'cid' => $cid,
        'message' => $message,
        'context' => $context,
    ];
    $json = json_encode($line, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $json = '{"ts_utc":"'.gmdate('Y-m-d H:i:s').'","level":"'.$level.'","cid":"'.$cid.'","message":"json_encode_failed"}';
    }
    @file_put_contents(log_path(), $json . "\n", FILE_APPEND | LOCK_EX);
    return $cid;
}

function render_internal_error(string $cid): void {
    http_response_code(500);
    $msg = 'Internal Server Error. Ref: ' . $cid;
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>500</title></head>';
    echo '<body style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#000;color:#fff;padding:24px">';
    echo '<div style="max-width:860px;margin:0 auto">';
    echo '<h1 style="font-size:20px;margin:0 0 12px 0">500</h1>';
    echo '<div style="opacity:.8">'.htmlspecialchars($msg, ENT_QUOTES, 'UTF-8').'</div>';
    echo '</div></body></html>';
}

function render_config_error(array $details): void {
    http_response_code(500);
    $missing = $details['missing'] ?? [];
    $searched = $details['searched'] ?? [];
    $loaded = (string)($details['loaded_from'] ?? '');
    $missingStr = is_array($missing) ? implode(', ', $missing) : '';

    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Config error</title></head>';
    echo '<body style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#000;color:#fff;padding:24px">';
    echo '<div style="max-width:860px;margin:0 auto">';
    echo '<h1 style="font-size:20px;margin:0 0 12px 0">Configuration missing</h1>';
    echo '<div style="opacity:.9;margin-bottom:10px">Missing required keys: <b>'.htmlspecialchars($missingStr, ENT_QUOTES, 'UTF-8').'</b></div>';
    echo '<div style="opacity:.8;margin-bottom:10px">Loaded .env from: <code>'.htmlspecialchars($loaded === '' ? '(none)' : $loaded, ENT_QUOTES, 'UTF-8').'</code></div>';
    echo '<div style="opacity:.8">Searched paths:</div>';
    echo '<ul style="opacity:.8">';
    if (is_array($searched)) {
        foreach ($searched as $p) {
            echo '<li><code>'.htmlspecialchars((string)$p, ENT_QUOTES, 'UTF-8').'</code></li>';
        }
    }
    echo '</ul>';
    echo '</div></body></html>';
}

function render_db_error(string $cid, array $details): void {
    http_response_code(500);
    $dbHost = (string)($details['db_host'] ?? '');
    $dbName = (string)($details['db_name'] ?? '');
    $loaded = (string)($details['loaded_from'] ?? '');

    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>DB error</title></head>';
    echo '<body style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#000;color:#fff;padding:24px">';
    echo '<div style="max-width:860px;margin:0 auto">';
    echo '<h1 style="font-size:20px;margin:0 0 12px 0">Database unavailable</h1>';
    echo '<div style="opacity:.9;margin-bottom:10px">Connection failed. Ref: <b>'.htmlspecialchars($cid, ENT_QUOTES, 'UTF-8').'</b></div>';
    echo '<div style="opacity:.8;margin-bottom:6px">DB host: <code>'.htmlspecialchars($dbHost, ENT_QUOTES, 'UTF-8').'</code></div>';
    echo '<div style="opacity:.8;margin-bottom:10px">DB name: <code>'.htmlspecialchars($dbName, ENT_QUOTES, 'UTF-8').'</code></div>';
    echo '<div style="opacity:.8">Loaded .env from: <code>'.htmlspecialchars($loaded === '' ? '(none)' : $loaded, ENT_QUOTES, 'UTF-8').'</code></div>';
    echo '</div></body></html>';
}
