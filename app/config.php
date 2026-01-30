<?php
declare(strict_types=1);

/**
 * Tiny .env loader (no dependencies).
 * v0.3.1: searches multiple candidate locations to reduce accidental misplacement outages.
 */

function app_version(): string {
    return '0.4';
}

/**
 * Load a .env file into getenv()/$_ENV (only for keys not already set).
 * @return bool whether the file was loaded.
 */
function env_load(string $path): bool {
    if (!is_file($path)) {
        return false;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return false;
    }
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        $v = trim($v, "\"'");
        if ($k !== '' && getenv($k) === false) {
            putenv($k . '=' . $v);
            $_ENV[$k] = $v;
        }
    }
    return true;
}

/**
 * Candidate .env paths, in order.
 * - project root
 * - one level above (common misplacement)
 * - public/ (sometimes moved there)
 * @return array<int,string>
 */
function env_candidate_paths(): array {
    $root = dirname(__DIR__);
    return [
        $root . '/.env',
        dirname($root) . '/.env',
        $root . '/public/.env',
    ];
}

/**
 * Load env from the first existing candidate.
 */
function env_bootstrap(): void {
    $paths = env_candidate_paths();
    $loadedFrom = '';
    foreach ($paths as $p) {
        if (env_load($p)) {
            $loadedFrom = $p;
            break;
        }
    }
    $GLOBALS['__CERTINEL_ENV_SEARCHED'] = $paths;
    $GLOBALS['__CERTINEL_ENV_LOADED_FROM'] = $loadedFrom;
}

env_bootstrap();

function env_loaded_from(): string {
    return (string)($GLOBALS['__CERTINEL_ENV_LOADED_FROM'] ?? '');
}

/** @return array<int,string> */
function env_searched_paths(): array {
    $p = $GLOBALS['__CERTINEL_ENV_SEARCHED'] ?? [];
    return is_array($p) ? $p : [];
}

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
            'MAIL_TRANSPORT' => (string)env('MAIL_TRANSPORT', 'mail'),
            'SMTP_HOST' => (string)env('SMTP_HOST', ''),
            'SMTP_PORT' => (int)env('SMTP_PORT', '587'),
            'SMTP_USER' => (string)env('SMTP_USER', ''),
            'SMTP_PASS' => (string)env('SMTP_PASS', ''),
            'SMTP_ENCRYPTION' => (string)env('SMTP_ENCRYPTION', 'starttls'),
            'SMTP_TIMEOUT_SECS' => (int)env('SMTP_TIMEOUT_SECS', '12'),
            'MAIL_API_URL' => (string)env('MAIL_API_URL', ''),
            'MAIL_API_TOKEN' => (string)env('MAIL_API_TOKEN', ''),
            'ADMIN_EMAIL' => (string)env('ADMIN_EMAIL', ''),
            // Legacy fallback. Prefer scoped API keys stored in DB (v0.3+).
            'API_WORKER_KEY' => (string)env('API_WORKER_KEY', ''),
            'TLS_CONNECT_TIMEOUT_SECS' => (int)env('TLS_CONNECT_TIMEOUT_SECS', '7'),
            'TLS_READ_TIMEOUT_SECS' => (int)env('TLS_READ_TIMEOUT_SECS', '7'),
            'TLS_SAMPLES_ON_CHANGE' => (int)env('TLS_SAMPLES_ON_CHANGE', '2'),
        ];
    }
    return $cache[$key] ?? $default;
}

function is_dev(): bool {
    return (string)cfg('APP_ENV', 'prod') === 'dev';
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

/** @return array<int,string> */
function missing_config_keys(): array {
    $required = ['APP_SECRET', 'DB_NAME', 'DB_USER', 'DB_PASS'];
    $missing = [];
    foreach ($required as $k) {
        if ((string)cfg($k, '') === '') {
            $missing[] = $k;
        }
    }
    return $missing;
}
