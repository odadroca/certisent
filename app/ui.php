<?php
declare(strict_types=1);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/**
 * Flash messages stored in session.
 * Types: info|success|warn|error
 */
/**
 * Locale-aware UI helpers (opt-in via I18N_FORMAT_DATES).
 * These return raw strings; callers should still escape with h().
 */
function ui_dt(?string $dbDatetime): string {
    if (function_exists('fmt_datetime_ui')) return fmt_datetime_ui($dbDatetime);
    return (string)($dbDatetime ?? '');
}

function ui_num($n): string {
    if (function_exists('fmt_number_ui')) return fmt_number_ui($n);
    return (string)($n ?? '');
}

function flash_set(string $type, string $message): void {
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** @return array<int,array{type:string,message:string}> */
function flash_pop_all(): array {
    $out = [];
    if (isset($_SESSION['flash']) && is_array($_SESSION['flash'])) {
        $out = $_SESSION['flash'];
    }
    unset($_SESSION['flash']);
    return $out;
}

/**
 * Returns URL path to the /public folder (no trailing slash).
 * Works even when running from /public/admin/*.
 */
function public_base_path(): string {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if (basename($dir) === 'admin') {
        $dir = rtrim(str_replace('\\', '/', dirname($dir)), '/');
    }
    return $dir === '' ? '' : $dir;
}

function url_for(string $path): string {
    $base = public_base_path();
    if ($path === '') return $base;
    if ($path[0] !== '/') $path = '/' . $path;
    return $base . $path;
}

function render_header(string $title, ?array $user = null): void {
    $appName = 'Certinel';
    $lang = function_exists('current_locale') ? current_locale() : 'en';
    echo '<!doctype html><html lang="'.h($lang).'"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>'.h($title).' · '.$appName.'</title>';
    echo '<script src="https://cdn.tailwindcss.com"></script>';
    echo '<style>:root{--app-bg:#0b2840;--app-accent:#09d2e8;--app-card:rgba(255,255,255,.06);--app-border:rgba(255,255,255,.12);}body{background:var(--app-bg);color:#fff;}a.accent,span.accent{color:var(--app-accent);} .card{background:var(--app-card);border:1px solid var(--app-border);} .btn{background:var(--app-accent);color:#001018;} .btn-outline{border:1px solid var(--app-accent);color:var(--app-accent);} .bg-green-400,.bg-green-500,.bg-green-600,.bg-green-700{background-color:var(--app-accent)!important;} .text-green-400{color:var(--app-accent)!important;} .text-green-700{color:var(--app-accent)!important;} </style>';
    echo '</head><body class="min-h-screen">';
    echo '<div class="max-w-6xl mx-auto px-4 py-6">';

    echo '<div class="flex items-center justify-between">';
    echo '<div class="text-2xl font-semibold">';
    echo '<a href="'.h(url_for('index.php')).'" class="hover:opacity-90">';
    echo '<span class="accent">Certinel</span> <span class="text-gray-300 text-base">certificate sentinel</span>';
    echo '</a>';
    echo '</div>';

    echo '<div class="text-sm">';
    if ($user) {
        echo '<span class="text-gray-300 mr-3">'.h($user['email']).' ('.h($user['role']).')</span>';
        echo '<form class="inline" method="post" action="'.h(url_for('logout.php')).'">'; echo csrf_field(); echo '<button class="accent hover:underline" type="submit">'.h(function_exists('t') ? t('nav.sign_out') : 'Sign out').'</button>'; echo '</form>';

    } else {
        echo '<a class="accent hover:underline mr-3" href="'.h(url_for('login.php')).'">'.h(function_exists('t') ? t('nav.sign_in') : 'Sign in').'</a>';
        echo '<a class="accent hover:underline" href="'.h(url_for('register.php')).'">'.h(function_exists('t') ? t('nav.register') : 'Register').'</a>';
    }
    echo '</div>';
    echo '</div>';

    // Signed-in navigation
    if ($user) {
        echo '<div class="mt-4 flex flex-wrap gap-3 text-sm">';
        echo '<a class="accent hover:underline" href="'.h(url_for('dashboard.php')).'">'.h(function_exists('t') ? t('nav.dashboard') : 'Dashboard').'</a>';
        echo '<a class="accent hover:underline" href="'.h(url_for('history.php')).'">'.h(function_exists('t') ? t('nav.history') : 'History').'</a>';
        echo '<a class="accent hover:underline" href="'.h(url_for('settings.php')).'">'.h(function_exists('t') ? t('nav.settings') : 'Settings').'</a>';
        echo '<a class="accent hover:underline" href="'.h(url_for('index.php')).'">'.h(function_exists('t') ? t('nav.quick_check') : 'Quick check').'</a>';
        if (($user['role'] ?? '') === 'admin') {
            echo '<a class="accent hover:underline" href="'.h(url_for('admin/monitors.php')).'">'.h(function_exists('t') ? t('nav.admin_monitors') : 'Admin · Monitors').'</a>';
            echo '<a class="accent hover:underline" href="'.h(url_for('admin/users.php')).'">'.h(function_exists('t') ? t('nav.admin_users') : 'Admin · Users').'</a>';
            echo '<a class="accent hover:underline" href="'.h(url_for('admin/system.php')).'">'.h(function_exists('t') ? t('nav.admin_system') : 'Admin · System').'</a>';
            echo '<a class="accent hover:underline" href="'.h(url_for('admin/email.php')).'">'.h(function_exists('t') ? t('nav.admin_email') : 'Admin · Email').'</a>';
            echo '<a class="accent hover:underline" href="'.h(url_for('admin/api_keys.php')).'">'.h(function_exists('t') ? t('nav.admin_api_keys') : 'Admin · API Keys').'</a>';
            echo '<a class="accent hover:underline" href="'.h(url_for('admin/outbox.php')).'">'.h(function_exists('t') ? t('nav.admin_outbox') : 'Admin · Outbox').'</a>';
        }
        echo '</div>';
    }

    // Flash messages
    $flashes = flash_pop_all();
    if ($flashes) {
        echo '<div class="mt-4 space-y-2">';
        foreach ($flashes as $f) {
            $t = (string)($f['type'] ?? 'info');
            $msg = (string)($f['message'] ?? '');
            $cls = 'bg-gray-800 text-gray-100';
            if ($t === 'success') $cls = 'bg-green-900 text-green-100';
            elseif ($t === 'warn') $cls = 'bg-yellow-900 text-yellow-100';
            elseif ($t === 'error') $cls = 'bg-red-900 text-red-100';
            echo '<div class="p-3 rounded-lg text-sm '.$cls.'">'.h($msg).'</div>';
        }
        echo '</div>';
    }

    echo '<div class="mt-6">';
}

function render_footer(): void {
    echo '</div>'; // content wrapper
    $v = function_exists('app_version') ? app_version() : 'unknown';
    echo '<div class="mt-10 text-xs text-gray-500">Certinel v'.h($v).' · UTC timestamps</div>';
    echo '</div></body></html>';
}

function badge_status(?string $status): string {
    $status = $status ?: 'unknown';
    $map = [
        'ok' => 'bg-green-700',
        'warn' => 'bg-yellow-700',
        'critical' => 'bg-red-700',
        'not_checked' => 'bg-slate-700',
        'unknown' => 'bg-gray-700',
    ];
    $cls = $map[$status] ?? 'bg-gray-700';
    return '<span class="px-2 py-1 rounded '.$cls.' text-xs">'.h(strtoupper($status)).'</span>';
}

/**
 * Parse event meta JSON safely.
 * @return array<string,mixed>
 */
function event_meta_array(?string $metaJson): array {
    if (!$metaJson) return [];
    $arr = json_decode($metaJson, true);
    return is_array($arr) ? $arr : [];
}

/**
 * Human-readable meta summary (keeps only a few high-signal fields).
 */
function format_event_meta(?string $metaJson): string {
    $m = event_meta_array($metaJson);
    if (!$m) return '';

    $parts = [];

    // Worker run summary
    $hasRun = isset($m['checked']) || isset($m['errors']) || isset($m['changed']) || isset($m['renewed']) || isset($m['warned']);
    if ($hasRun) {
        $parts[] = 'checked=' . (int)($m['checked'] ?? 0);
        $parts[] = 'errors=' . (int)($m['errors'] ?? 0);
        $parts[] = 'changed=' . (int)($m['changed'] ?? 0);
        $parts[] = 'renewed=' . (int)($m['renewed'] ?? 0);
        $parts[] = 'warned=' . (int)($m['warned'] ?? 0);
        if (isset($m['duration_ms'])) $parts[] = 'ms=' . (int)$m['duration_ms'];
    }

    // Change/renew confirmation
    if (isset($m['confirm_result'])) {
        $parts[] = 'confirm=' . (string)$m['confirm_result'];
    }
    if (isset($m['confirm_samples'])) {
        $parts[] = 'samples=' . (int)$m['confirm_samples'];
    }
    if (isset($m['observed_fingerprints']) && is_array($m['observed_fingerprints'])) {
        $fps = array_values(array_unique(array_map('strval', $m['observed_fingerprints'])));
        $parts[] = 'observed_fp=' . count($fps);
        $fps = array_slice($fps, 0, 3);
        $short = array_map(function($x){ return substr($x, 0, 12) . '…'; }, $fps);
        $parts[] = 'observed=' . implode(',', $short);
    }

    // Renewal timing
    if (isset($m['early_renewal_days'])) {
        $parts[] = 'early_renewal_days=' . (int)$m['early_renewal_days'];
    }
    if (isset($m['prev_valid_to'])) {
        $parts[] = 'prev_valid_to=' . (string)$m['prev_valid_to'];
    }
    if (isset($m['new_valid_to'])) {
        $parts[] = 'new_valid_to=' . (string)$m['new_valid_to'];
    }

    // Error (short)
    if (isset($m['error'])) {
        $parts[] = 'error=' . (string)$m['error'];
    }

    // Fingerprints are long; show only a prefix.
    if (isset($m['prev_fingerprint'])) {
        $parts[] = 'prev_fp=' . substr((string)$m['prev_fingerprint'], 0, 12) . '…';
    }
    if (isset($m['new_fingerprint'])) {
        $parts[] = 'new_fp=' . substr((string)$m['new_fingerprint'], 0, 12) . '…';
    }

    if (!$parts) {
        $j = json_encode($m, JSON_UNESCAPED_SLASHES);
        return $j ? $j : '';
    }
    return implode(' | ', $parts);
}

function progress_bar(?int $daysRemaining, ?string $validFrom, ?string $validTo): string {
    if ($daysRemaining === null || !$validFrom || !$validTo) {
        return '<div class="h-2 bg-gray-800 rounded"></div>';
    }
    $vf = strtotime($validFrom . ' UTC');
    $vt = strtotime($validTo . ' UTC');
    if (!$vf || !$vt || $vt <= $vf) return '<div class="h-2 bg-gray-800 rounded"></div>';

    $total = max(1, $vt - $vf);
    $left = max(0, $vt - time());
    $pct = (int)round(($left / $total) * 100);
    $pct = max(0, min(100, $pct));
    return '<div class="h-2 bg-gray-800 rounded overflow-hidden"><div class="h-2 bg-green-400" style="width: '.$pct.'%"></div></div>';
}

/**
 * Flash message using a translation key.
 * Additive helper: preserves flash_set() for existing callers.
 */
function flash_set_key(string $type, string $key, array $params = []): void {
    $msg = function_exists('t') ? t($key, $params) : $key;
    flash_set($type, $msg);
}


