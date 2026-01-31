<?php
declare(strict_types=1);

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';

// Error handling: log server-side, keep UI messages non-verbose.
if (is_dev()) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
}

set_exception_handler(function(Throwable $e): void {
    $cid = log_error('exception', $e->getMessage(), [
        'type' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => is_dev() ? $e->getTraceAsString() : null,
    ]);

    if (headers_sent()) return;

    if ($e instanceof PDOException) {
        render_db_error($cid, [
            'db_host' => (string)cfg('DB_HOST', ''),
            'db_name' => (string)cfg('DB_NAME', ''),
            'loaded_from' => env_loaded_from(),
        ]);
        return;
    }

    render_internal_error($cid);
});

set_error_handler(function(int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function(): void {
    $err = error_get_last();
    if (!$err) return;
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array((int)$err['type'], $fatal, true)) return;
    $cid = log_error('fatal', (string)($err['message'] ?? 'fatal'), [
        'file' => (string)($err['file'] ?? ''),
        'line' => (int)($err['line'] ?? 0),
        'type' => (int)($err['type'] ?? 0),
    ]);
    if (!headers_sent()) {
        render_internal_error($cid);
    }
});

// Fail fast if required config keys are missing.
$missing = missing_config_keys();
if (count($missing) > 0) {
    if (!headers_sent()) {
        $cid = log_error('config', 'missing_config_keys', [
            'missing' => $missing,
            'searched' => env_searched_paths(),
            'loaded_from' => env_loaded_from(),
        ]);
        render_config_error($cid, [
            'missing' => $missing,
            'searched' => env_searched_paths(),
            'loaded_from' => env_loaded_from(),
        ]);
    }
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';

require_once __DIR__ . '/services/Audit.php';
require_once __DIR__ . '/services/SsrfPolicy.php';
require_once __DIR__ . '/services/CertFetcher.php';
require_once __DIR__ . '/services/SmtpClient.php';
require_once __DIR__ . '/services/Emailer.php';
require_once __DIR__ . '/services/Notifier.php';
require_once __DIR__ . '/services/MonitorService.php';
require_once __DIR__ . '/services/Worker.php';

require_once __DIR__ . '/api/Router.php';

date_default_timezone_set('UTC'); // keep cron consistent; UI prints UTC by default.
