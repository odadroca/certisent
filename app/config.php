<?php
declare(strict_types=1);

/**
 * Tiny .env loader (no dependencies).
 * - Reads project root .env (same folder as this file's parent).
 * - For shared hosting, protect .env with .htaccess.
 */
function env_load(string $path): void {
    if (!is_file($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        // Strip surrounding quotes if present
        $v = trim($v, "\"'");
        if ($k !== '' && getenv($k) === false) {
            putenv($k . '=' . $v);
            $_ENV[$k] = $v;
        }
    }
}

$root = dirname(__DIR__);
env_load($root . '/.env');

function env(string $key, ?string $default = null): ?string {
    $v = getenv($key);
    if ($v === false) return $default;
    return $v;
}

function cfg(string $key, $default = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = [
            'APP_ENV' => env('APP_ENV', 'prod'),
            'APP_URL' => rtrim((string)env('APP_URL', ''), '/'),
            'APP_SECRET' => (string)env('APP_SECRET', ''),
            'DB_HOST' => (string)env('DB_HOST', 'localhost'),
            'DB_NAME' => (string)env('DB_NAME', ''),
            'DB_USER' => (string)env('DB_USER', ''),
            'DB_PASS' => (string)env('DB_PASS', ''),
            'MAIL_FROM' => (string)env('MAIL_FROM', 'no-reply@example.com'),
            'MAIL_FROM_NAME' => (string)env('MAIL_FROM_NAME', 'Certinel'),
            'ADMIN_EMAIL' => (string)env('ADMIN_EMAIL', ''),
            'API_WORKER_KEY' => (string)env('API_WORKER_KEY', ''),
            'TLS_CONNECT_TIMEOUT_SECS' => (int)env('TLS_CONNECT_TIMEOUT_SECS', '7'),
            'TLS_READ_TIMEOUT_SECS' => (int)env('TLS_READ_TIMEOUT_SECS', '7'),
            'TLS_SAMPLES_ON_CHANGE' => (int)env('TLS_SAMPLES_ON_CHANGE', '2'),
        ];
    }
    return $cache[$key] ?? $default;
}

function app_base_url(): string {
    return (string)cfg('APP_URL', '');
}

/**
 * Build an absolute URL using APP_URL as the base.
 * - If APP_URL is empty, returns a relative URL.
 * - Does not inject '/public' (APP_URL may already point to the deployed base path).
 */
function app_url(string $path = ''): string {
    $base = rtrim((string)cfg('APP_URL', ''), '/');
    $path = ltrim($path, '/');
    if ($base === '') {
        return $path === '' ? '' : '/' . $path;
    }
    return $path === '' ? $base : ($base . '/' . $path);
}

function app_secret(): string {
    $s = (string)cfg('APP_SECRET', '');
    if ($s === '') {
        // Fail fast in prod; allow empty in dev only.
        if (cfg('APP_ENV') !== 'dev') {
            http_response_code(500);
            echo "Misconfiguration: APP_SECRET missing.";
            exit;
        }
    }
    return $s;
}
