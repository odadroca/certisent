<?php
declare(strict_types=1);

/**
 * Minimal file logger for shared hosting.
 * - Writes to app/logs/app.log
 * - Includes a correlation id per incident.
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
    // Best-effort; ignore errors.
    @file_put_contents(log_path(), $json . "\n", FILE_APPEND | LOCK_EX);
    return $cid;
}

function is_dev(): bool {
    return (string)cfg('APP_ENV', 'prod') === 'dev';
}

function render_internal_error(string $cid): void {
    http_response_code(500);
    $msg = 'Internal Server Error. Ref: ' . $cid;
    // Minimal HTML; avoid depending on ui.php here.
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>500</title></head>';
    echo '<body style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#000;color:#fff;padding:24px">';
    echo '<div style="max-width:860px;margin:0 auto">';
    echo '<h1 style="font-size:20px;margin:0 0 12px 0">500</h1>';
    echo '<div style="opacity:.8">'.htmlspecialchars($msg, ENT_QUOTES, 'UTF-8').'</div>';
    echo '</div></body></html>';
}
