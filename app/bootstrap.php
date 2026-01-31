<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Determine if the current request should be treated as HTTPS.
 *
 * Defaults preserve legacy behavior: rely on $_SERVER['HTTPS'].
 * When TRUST_PROXY_HEADERS=true, selected proxy headers are honored, optionally gated by TRUSTED_PROXY_CIDRS.
 * When FORCE_SECURE_COOKIES=true, cookies are always marked Secure.
 */
function certinel_parse_bool(string $raw): bool {
    $v = strtolower(trim($raw));
    return in_array($v, ['1','true','yes','on'], true);
}

function certinel_ip_in_cidr(string $ip, string $cidr): bool {
    $cidr = trim($cidr);
    if ($cidr === '') return false;
    $net = $cidr;
    $bits = null;
    if (str_contains($cidr, '/')) {
        [$net, $bitsRaw] = explode('/', $cidr, 2);
        $net = trim($net);
        $bitsRaw = trim($bitsRaw);
        if ($bitsRaw === '' || !ctype_digit($bitsRaw)) return false;
        $bits = (int)$bitsRaw;
    }

    $ipBin = @inet_pton($ip);
    $netBin = @inet_pton($net);
    if ($ipBin === false || $netBin === false) return false;
    if (strlen($ipBin) !== strlen($netBin)) return false;

    $totalBits = (strlen($ipBin) === 16) ? 128 : 32;
    if ($bits === null) $bits = $totalBits;
    if ($bits < 0 || $bits > $totalBits) return false;

    $fullBytes = intdiv($bits, 8);
    $remBits = $bits % 8;

    if ($fullBytes > 0) {
        if (substr($ipBin, 0, $fullBytes) !== substr($netBin, 0, $fullBytes)) return false;
    }
    if ($remBits === 0) return true;
    $mask = (0xFF << (8 - $remBits)) & 0xFF;
    return ((ord($ipBin[$fullBytes]) & $mask) === (ord($netBin[$fullBytes]) & $mask));
}

function certinel_is_trusted_proxy(string $remoteAddr): bool {
    $raw = trim((string)cfg('TRUSTED_PROXY_CIDRS', ''));
    if ($remoteAddr === '') return false;
    if ($raw === '') {
        // Optional list: when TRUST_PROXY_HEADERS=true and no list is provided, trust all.
        return true;
    }
    $parts = array_filter(array_map('trim', explode(',', $raw)), fn($x) => $x !== '');
    foreach ($parts as $cidr) {
        if (certinel_ip_in_cidr($remoteAddr, $cidr)) return true;
    }
    return false;
}

function certinel_https_from_proxy_headers(): bool {
    $xfp = (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    if ($xfp !== '') {
        $first = strtolower(trim(explode(',', $xfp, 2)[0]));
        if ($first === 'https') return true;
        if ($first === 'http') return false;
    }
    $xfs = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')));
    if (in_array($xfs, ['1','true','yes','on'], true)) return true;

    $fwd = (string)($_SERVER['HTTP_FORWARDED'] ?? '');
    if ($fwd !== '') {
        // Very small parser for Forwarded: proto=https
        $chunks = explode(';', $fwd);
        foreach ($chunks as $c) {
            $c = trim($c);
            if (stripos($c, 'proto=') === 0) {
                $p = strtolower(trim(substr($c, strlen('proto=')), " \t\n\r\0\x0B\"'"));
                return $p === 'https';
            }
        }
    }
    return false;
}

function certinel_is_https(): bool {
    $force = certinel_parse_bool((string)cfg('FORCE_SECURE_COOKIES', 'false'));
    if ($force) return true;

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if ($https) return true;

    $trust = certinel_parse_bool((string)cfg('TRUST_PROXY_HEADERS', 'false'));
    if (!$trust) return false;

    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (!certinel_is_trusted_proxy($remote)) return false;

    return certinel_https_from_proxy_headers();
}

$isHttps = certinel_is_https();
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

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
