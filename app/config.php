<?php
declare(strict_types=1);

/**
 * Tiny .env loader (no dependencies).
 * v0.3.1: searches multiple candidate locations to reduce accidental misplacement outages.
 */

function app_version(): string { return '0.7.6'; }

/**
 * Database schema version.
 *
 * This value changes only when a SQL migration changes the schema.
 * Patch releases (v0.4.x) should not require bumping schema_version.
 */
function schema_version(): string {
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
            'MAIL_FROM_NAME' => (string)env('MAIL_FROM_NAME', 'Certisent'),
                        // v0.7 i18n formatting (opt-in, default off).
            'I18N_FORMAT_DATES' => (string)env('I18N_FORMAT_DATES', 'false'),
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
            // v0.5.6 API key ownership (opt-in).
            // Default false: existing keys remain system-scoped; ownership enforced only for user-scoped keys.
            'API_KEYS_REQUIRE_OWNER' => (string)env('API_KEYS_REQUIRE_OWNER', 'false'),
            // Legacy fallback. Prefer scoped API keys stored in DB (v0.3+).
            'API_WORKER_KEY' => (string)env('API_WORKER_KEY', ''),
            'TLS_CONNECT_TIMEOUT_SECS' => (int)env('TLS_CONNECT_TIMEOUT_SECS', '7'),
            'TLS_READ_TIMEOUT_SECS' => (int)env('TLS_READ_TIMEOUT_SECS', '7'),
            'TLS_SAMPLES_ON_CHANGE' => (int)env('TLS_SAMPLES_ON_CHANGE', '2'),

            // v0.7.3: TLS trust validation probe (opt-in via monitor_settings.tls_validation_mode).
            // These timeouts apply only to the *separate* trust probe (CertFetcher remains verify_peer=false).
            'TLS_TRUST_CONNECT_TIMEOUT_SECS' => (int)env('TLS_TRUST_CONNECT_TIMEOUT_SECS', '4'),
            'TLS_TRUST_TIMEOUT_SECS' => (int)env('TLS_TRUST_TIMEOUT_SECS', '6'),
            // Optional override for environments without a working system CA bundle.
            // When empty, the system/default CA bundle is used.
            'TLS_CA_BUNDLE' => (string)env('TLS_CA_BUNDLE', ''),

            // v0.5 SSRF policy framework (default preserves v0.4.x behavior).
            'SSRF_MODE' => (string)env('SSRF_MODE', 'legacy'),
            'SSRF_ALLOW_CIDRS' => (string)env('SSRF_ALLOW_CIDRS', ''),
            'SSRF_ALLOW_HOSTS' => (string)env('SSRF_ALLOW_HOSTS', ''),
            'SSRF_ALLOW_PORTS' => (string)env('SSRF_ALLOW_PORTS', ''),

            // v0.5.1 Webhook egress hardening (default preserves v0.4.x behavior).
            // Modes:
            // - legacy: allow any URL (current behavior)
            // - public_only: require https and block private/reserved
            // - allowlist: require https and allow private/reserved only if allowlisted via SSRF_ALLOW_*.
            'WEBHOOK_MODE' => (string)env('WEBHOOK_MODE', 'legacy'),

            // v0.5.2 RSS tenancy hardening.
            // Default false: do not include system/global events for non-admin RSS tokens.
            'RSS_INCLUDE_SYSTEM_EVENTS' => (string)env('RSS_INCLUDE_SYSTEM_EVENTS', 'false'),

            // v0.5.4 Error page detail control.
            // - safe (default): unauthenticated error pages show only a correlation id.
            // - full: include config/DB details on error pages (not recommended on public instances).
            'ERROR_DETAIL_MODE' => (string)env('ERROR_DETAIL_MODE', 'safe'),

            // v0.5.5 Session/cookie hardening for reverse proxy deployments.
            // - TRUST_PROXY_HEADERS: when enabled, allow X-Forwarded-* to influence HTTPS detection.
            // - TRUSTED_PROXY_CIDRS: optional comma-separated CIDRs/IPs for proxies allowed to supply headers.
            //   If TRUST_PROXY_HEADERS=true and TRUSTED_PROXY_CIDRS is empty, any proxy is trusted.
            // - FORCE_SECURE_COOKIES: force Secure cookies even if HTTPS detection fails.
            'TRUST_PROXY_HEADERS' => (string)env('TRUST_PROXY_HEADERS', 'false'),
            'TRUSTED_PROXY_CIDRS' => (string)env('TRUSTED_PROXY_CIDRS', ''),
            'FORCE_SECURE_COOKIES' => (string)env('FORCE_SECURE_COOKIES', 'false'),

            // v0.5.7 Coarse rate limiting (defaults are intentionally high).
            // Set max<=0 or window<=0 to disable a limiter.
            'RATE_LIMIT_LOGIN_MAX' => (int)env('RATE_LIMIT_LOGIN_MAX', '60'),
            'RATE_LIMIT_LOGIN_WINDOW_SEC' => (int)env('RATE_LIMIT_LOGIN_WINDOW_SEC', '900'),
            'RATE_LIMIT_API_IP_MAX' => (int)env('RATE_LIMIT_API_IP_MAX', '600'),
            'RATE_LIMIT_API_IP_WINDOW_SEC' => (int)env('RATE_LIMIT_API_IP_WINDOW_SEC', '60'),
            'RATE_LIMIT_API_TOKEN_MAX' => (int)env('RATE_LIMIT_API_TOKEN_MAX', '1200'),
            'RATE_LIMIT_API_TOKEN_WINDOW_SEC' => (int)env('RATE_LIMIT_API_TOKEN_WINDOW_SEC', '60'),

            // v0.5.8 Baseline security headers (safe defaults).
            // CSP_MODE: report_only (default), enforce, off.
            // CSP_POLICY: Content-Security-Policy value (without the header name).
            // CSP_REPORT_URI: optional report endpoint (appended as `; report-uri <url>`).
            // HSTS is sent only when HTTPS is confirmed (including proxy mode from v0.5.5).
            'CSP_MODE' => (string)env('CSP_MODE', 'report_only'),
            'CSP_POLICY' => (string)env('CSP_POLICY', "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'"),
            'CSP_REPORT_URI' => (string)env('CSP_REPORT_URI', ''),
            'HSTS_ENABLED' => (string)env('HSTS_ENABLED', 'true'),
            'HSTS_MAX_AGE' => (int)env('HSTS_MAX_AGE', '15552000'),
            'HSTS_INCLUDE_SUBDOMAINS' => (string)env('HSTS_INCLUDE_SUBDOMAINS', 'false'),
            'HSTS_PRELOAD' => (string)env('HSTS_PRELOAD', 'false'),


            // v0.5.3 Registration bootstrap hardening.
            // REGISTRATION_MODE: open (default), invite, closed.
            // SETUP_ADMIN_TOKEN: optional token required for first-admin claim (and for invite registrations).
            // ADMIN_EMAIL (already used for admin notifications) also gates the first admin claim when set.
            'REGISTRATION_MODE' => (string)env('REGISTRATION_MODE', 'open'),
            'SETUP_ADMIN_TOKEN' => (string)env('SETUP_ADMIN_TOKEN', ''),
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
