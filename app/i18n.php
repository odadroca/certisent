<?php
declare(strict_types=1);

/**
 * Minimal localization foundation.
 *
 * Goals:
 * - Default-preserving: English remains default for existing deployments.
 * - Non-breaking: if the users.locale column is missing, fall back to 'en'.
 * - No hard dependency in UI: callers may use function_exists() to probe.
 */

/** @return array<int,string> */
function supported_locales(): array {
    // Keep this list small and explicit.
    return ['en', 'pt'];
}

function normalize_locale(string $raw): string {
    $raw = strtolower(trim($raw));
    if ($raw === '') return 'en';

    // Accept exact match (en, pt) or language-only from tags (pt-pt, en-us).
    $raw = str_replace('_', '-', $raw);
    $parts = explode('-', $raw, 2);
    $lang = trim($parts[0] ?? '');
    if ($lang === '') return 'en';
    return in_array($lang, supported_locales(), true) ? $lang : 'en';
}

/**
 * Resolve the locale for the current request.
 *
 * Order:
 * 1) $_SESSION['locale'] cache
 * 2) Logged-in user's DB preference (if users.locale exists)
 * 3) Fallback: 'en'
 */
function current_locale(): string {
    $cached = $_SESSION['locale'] ?? null;
    if (is_string($cached) && $cached !== '') {
        return normalize_locale($cached);
    }

    // Only attempt DB lookup when user is logged in and schema supports it.
    if (function_exists('current_user')) {
        $u = current_user();
        if (is_array($u) && isset($u['id']) && function_exists('db_has_column') && db_has_column('users', 'locale')) {
            try {
                $pdo = db();
                $st = $pdo->prepare('SELECT locale FROM users WHERE id = :id LIMIT 1');
                $st->execute([':id' => (int)$u['id']]);
                $row = $st->fetch();
                $loc = normalize_locale((string)($row['locale'] ?? 'en'));
                $_SESSION['locale'] = $loc;
                return $loc;
            } catch (Throwable $e) {
                // Fall through to default.
            }
        }
    }

    $_SESSION['locale'] = 'en';
    return 'en';
}

/**
 * Translate a key.
 *
 * - $key uses dot-notation.
 * - $params replaces {name} placeholders.
 * - Fallback: selected locale -> 'en' -> key
 */
function t(string $key, array $params = [], ?string $locale = null): string {
    $loc = normalize_locale($locale ?? current_locale());

    static $catalogCache = []; // [locale => array]
    $load = function(string $l) use (&$catalogCache): array {
        if (isset($catalogCache[$l]) && is_array($catalogCache[$l])) return $catalogCache[$l];
        $path = __DIR__ . '/locales/' . $l . '.php';
        if (!is_file($path)) {
            $catalogCache[$l] = [];
            return [];
        }
        $cat = require $path;
        $catalogCache[$l] = is_array($cat) ? $cat : [];
        return $catalogCache[$l];
    };

    $cat = $load($loc);
    $en = ($loc === 'en') ? $cat : $load('en');

    $s = (string)($cat[$key] ?? $en[$key] ?? $key);
    if ($params) {
        foreach ($params as $k => $v) {
            $s = str_replace('{' . (string)$k . '}', (string)$v, $s);
        }
    }
    return $s;
}

/**
 * Parse common truthy strings.
 */
function i18n_truthy($v): bool {
    if (is_bool($v)) return $v;
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1','true','yes','on','y'], true);
}

/**
 * Whether locale-aware formatting is enabled (opt-in).
 * Default: off (preserves current outputs).
 */
function i18n_format_enabled(): bool {
    if (!function_exists('cfg')) return false;
    return i18n_truthy(cfg('I18N_FORMAT_DATES', 'false'));
}

/**
 * Format a UTC datetime string (DB format) for UI.
 *
 * When formatting is disabled, returns the input unchanged.
 * When enabled, uses ext/intl if available; otherwise returns the input unchanged.
 */
function fmt_datetime_ui(?string $dbDatetime, ?string $locale = null): string {
    $s = (string)($dbDatetime ?? '');
    if ($s === '') return '';
    if (!i18n_format_enabled()) return $s;

    if (!class_exists('IntlDateFormatter')) return $s;
    try {
        $dt = new DateTimeImmutable($s, new DateTimeZone('UTC'));
        $loc = normalize_locale($locale ?? current_locale());
        $fmt = new IntlDateFormatter($loc, IntlDateFormatter::MEDIUM, IntlDateFormatter::SHORT, 'UTC');
        $out = $fmt->format($dt);
        return is_string($out) && $out !== '' ? $out : $s;
    } catch (Throwable $e) {
        return $s;
    }
}

/**
 * Format a number for UI.
 *
 * When formatting is disabled, returns the number as-is (string-cast).
 * When enabled, uses ext/intl NumberFormatter if available; otherwise returns the input.
 */
function fmt_number_ui($n, ?string $locale = null): string {
    if ($n === null) return '';
    if (is_string($n) && !is_numeric($n)) return (string)$n;

    $s = (string)$n;
    if (!i18n_format_enabled()) return $s;

    if (!class_exists('NumberFormatter')) return $s;
    try {
        $loc = normalize_locale($locale ?? current_locale());
        $nf = new NumberFormatter($loc, NumberFormatter::DECIMAL);
        // Preserve integer-like outputs.
        if (is_int($n) || (is_string($n) && ctype_digit($n))) {
            $nf->setAttribute(NumberFormatter::FRACTION_DIGITS, 0);
        }
        $out = $nf->format($n);
        return is_string($out) && $out !== '' ? $out : $s;
    } catch (Throwable $e) {
        return $s;
    }
}
