<?php
declare(strict_types=1);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

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
    if (basename($dir) === 'admin' || basename($dir) === 'reports') {
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
    $appName = 'Certisent';
    $lang = function_exists('current_locale') ? current_locale() : 'en';

    // Detect active page for nav highlighting.
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $isAdmin = str_contains(($_SERVER['SCRIPT_NAME'] ?? ''), '/admin/');
    $isReports = str_contains(($_SERVER['SCRIPT_NAME'] ?? ''), '/reports/');

    echo '<!doctype html><html lang="' . h($lang) . '">';
    echo '<head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . ' &middot; ' . $appName . '</title>';
    echo '<link rel="stylesheet" href="' . h(url_for('/assets/style.css')) . '">';
    echo '</head><body>';

    // ── Header ──
    echo '<header class="site-header"><div class="container"><div class="header-inner">';

    echo '<a href="' . h(url_for('index.php')) . '" class="header-brand">';
    echo '<img src="/assets/certisent-neg.png" alt="Certisent">';
    echo '<span class="header-brand-name">' . $appName . '</span>';
    echo '<span class="header-brand-tag">certificate sentinel</span>';
    echo '</a>';

    echo '<div class="header-user">';
    if ($user) {
        echo '<span class="hide-mobile">' . h($user['email']) . '</span>';
        echo '<span class="badge badge-neutral text-xs">' . h($user['role']) . '</span>';
        echo '<form class="inline" method="post" action="' . h(url_for('logout.php')) . '">';
        echo csrf_field();
        echo '<button class="btn btn-ghost-accent btn-xs" type="submit">' . h(function_exists('t') ? t('nav.sign_out') : 'Sign out') . '</button>';
        echo '</form>';
    } else {
        echo '<a class="btn btn-ghost-accent btn-sm" href="' . h(url_for('login.php')) . '">' . h(function_exists('t') ? t('nav.sign_in') : 'Sign in') . '</a>';
        echo '<a class="btn btn-outline-accent btn-sm" href="' . h(url_for('register.php')) . '">' . h(function_exists('t') ? t('nav.register') : 'Register') . '</a>';
    }
    echo '</div>';
    echo '</div></div></header>';

    // ── Navigation (signed-in only) ──
    if ($user) {
        echo '<nav class="site-nav"><div class="container"><div class="nav-inner">';

        $navItems = [
            ['dashboard.php', function_exists('t') ? t('nav.dashboard') : 'Dashboard'],
            ['reports/index.php', function_exists('t') ? t('nav.reports') : 'Reports'],
            ['history.php', function_exists('t') ? t('nav.history') : 'History'],
            ['settings.php', function_exists('t') ? t('nav.settings') : 'Settings'],
            ['index.php', function_exists('t') ? t('nav.quick_check') : 'Quick check'],
        ];
        foreach ($navItems as [$href, $label]) {
            $active = '';
            if (str_contains($href, 'reports/')) {
                $active = $isReports ? ' active' : '';
            } elseif (!$isAdmin && !$isReports && $script === $href) {
                $active = ' active';
            }
            echo '<a class="nav-link' . $active . '" href="' . h(url_for($href)) . '">' . h($label) . '</a>';
        }

        if (($user['role'] ?? '') === 'admin') {
            echo '<div class="nav-sep"></div>';

            $adminItems = [
                ['admin/system.php', function_exists('t') ? t('nav.admin_system') : 'System'],
                ['admin/monitors.php', function_exists('t') ? t('nav.admin_monitors') : 'Monitors'],
                ['admin/users.php', function_exists('t') ? t('nav.admin_users') : 'Users'],
                ['admin/email.php', function_exists('t') ? t('nav.admin_email') : 'Email'],
                ['admin/api_keys.php', function_exists('t') ? t('nav.admin_api_keys') : 'API Keys'],
                ['admin/outbox.php', function_exists('t') ? t('nav.admin_outbox') : 'Outbox'],
                ['admin/audit.php', 'Audit'],
            ];
            foreach ($adminItems as [$href, $label]) {
                $active = ($isAdmin && str_contains($href, $script)) ? ' active' : '';
                echo '<a class="nav-link' . $active . '" href="' . h(url_for($href)) . '">' . h($label) . '</a>';
            }
        }

        echo '</div></div></nav>';
    }

    // ── Flash Messages + Content ──
    echo '<main class="site-main"><div class="container">';
    $flashes = flash_pop_all();
    if ($flashes) {
        foreach ($flashes as $f) {
            $t = (string)($f['type'] ?? 'info');
            $msg = (string)($f['message'] ?? '');
            $cls = 'alert-info';
            if ($t === 'success') $cls = 'alert-success';
            elseif ($t === 'warn') $cls = 'alert-warn';
            elseif ($t === 'error') $cls = 'alert-error';
            echo '<div class="alert ' . $cls . '">' . h($msg) . '</div>';
        }
    }
}

function render_footer(): void {
    $v = function_exists('app_version') ? app_version() : 'unknown';
    echo '</div></main>';
    echo '<footer class="site-footer"><div class="container">';
    echo '<span class="version-pill">v' . h($v) . '</span>';
    echo ' &middot; UTC timestamps';
    echo '</div></footer>';
    echo '</body></html>';
}

function badge_status(?string $status): string {
    $status = $status ?: 'unknown';
    $map = [
        'ok'          => 'badge-ok',
        'warn'        => 'badge-warn',
        'critical'    => 'badge-crit',
        'not_checked' => 'badge-unknown',
        'unknown'     => 'badge-unknown',
    ];
    $cls = $map[$status] ?? 'badge-unknown';
    return '<span class="badge ' . $cls . '"><span class="badge-dot"></span>' . h(strtoupper($status)) . '</span>';
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

    $hasRun = isset($m['checked']) || isset($m['errors']) || isset($m['changed']) || isset($m['renewed']) || isset($m['warned']);
    if ($hasRun) {
        $parts[] = 'checked=' . (int)($m['checked'] ?? 0);
        $parts[] = 'errors=' . (int)($m['errors'] ?? 0);
        $parts[] = 'changed=' . (int)($m['changed'] ?? 0);
        $parts[] = 'renewed=' . (int)($m['renewed'] ?? 0);
        $parts[] = 'warned=' . (int)($m['warned'] ?? 0);
        if (isset($m['duration_ms'])) $parts[] = 'ms=' . (int)$m['duration_ms'];
    }
    if (isset($m['confirm_result'])) $parts[] = 'confirm=' . (string)$m['confirm_result'];
    if (isset($m['confirm_samples'])) $parts[] = 'samples=' . (int)$m['confirm_samples'];
    if (isset($m['observed_fingerprints']) && is_array($m['observed_fingerprints'])) {
        $fps = array_values(array_unique(array_map('strval', $m['observed_fingerprints'])));
        $parts[] = 'observed_fp=' . count($fps);
        $fps = array_slice($fps, 0, 3);
        $short = array_map(function($x){ return substr($x, 0, 12) . '…'; }, $fps);
        $parts[] = 'observed=' . implode(',', $short);
    }
    if (isset($m['early_renewal_days'])) $parts[] = 'early_renewal_days=' . (int)$m['early_renewal_days'];
    if (isset($m['prev_valid_to'])) $parts[] = 'prev_valid_to=' . (string)$m['prev_valid_to'];
    if (isset($m['new_valid_to'])) $parts[] = 'new_valid_to=' . (string)$m['new_valid_to'];
    if (isset($m['error'])) $parts[] = 'error=' . (string)$m['error'];
    if (isset($m['prev_fingerprint'])) $parts[] = 'prev_fp=' . substr((string)$m['prev_fingerprint'], 0, 12) . '…';
    if (isset($m['new_fingerprint'])) $parts[] = 'new_fp=' . substr((string)$m['new_fingerprint'], 0, 12) . '…';

    if (!$parts) {
        $j = json_encode($m, JSON_UNESCAPED_SLASHES);
        return $j ? $j : '';
    }
    return implode(' | ', $parts);
}

function progress_bar(?int $daysRemaining, ?string $validFrom, ?string $validTo): string {
    if ($daysRemaining === null || !$validFrom || !$validTo) {
        return '<div class="progress"><div class="progress-bar" style="width:0%"></div></div>';
    }
    $vf = strtotime($validFrom . ' UTC');
    $vt = strtotime($validTo . ' UTC');
    if (!$vf || !$vt || $vt <= $vf) return '<div class="progress"><div class="progress-bar" style="width:0%"></div></div>';

    $total = max(1, $vt - $vf);
    $left = max(0, $vt - time());
    $pct = (int)round(($left / $total) * 100);
    $pct = max(0, min(100, $pct));

    $cls = 'progress-ok';
    if ($daysRemaining <= 7) $cls = 'progress-crit';
    elseif ($daysRemaining <= 30) $cls = 'progress-warn';

    return '<div class="progress ' . $cls . '"><div class="progress-bar" style="width:' . $pct . '%"></div></div>';
}

/**
 * Flash message using a translation key.
 */
function flash_set_key(string $type, string $key, array $params = []): void {
    $msg = function_exists('t') ? t($key, $params) : $key;
    flash_set($type, $msg);
}

// ── Report Helpers ───────────────────────────────────────────

/**
 * Render a health ring (CSS conic-gradient donut chart).
 * @param array<string,int> $segments ['ok'=>N,'warn'=>N,'crit'=>N,'unknown'=>N]
 */
function render_health_ring(array $segments): string {
    $total = max(1, array_sum($segments));
    $okPct = round(($segments['ok'] ?? 0) / $total * 100);

    $colors = [
        'ok'      => 'var(--ok)',
        'warn'    => 'var(--warn)',
        'crit'    => 'var(--crit)',
        'unknown' => '#94a3b8',
    ];
    $labels = [
        'ok'      => 'OK',
        'warn'    => 'Warning',
        'crit'    => 'Critical',
        'unknown' => 'Unknown',
    ];

    // Build conic-gradient stops.
    $stops = [];
    $pos = 0;
    foreach (['ok','warn','crit','unknown'] as $k) {
        $v = $segments[$k] ?? 0;
        if ($v <= 0) continue;
        $pct = $v / $total * 100;
        $stops[] = $colors[$k] . ' ' . round($pos, 2) . '% ' . round($pos + $pct, 2) . '%';
        $pos += $pct;
    }
    if (empty($stops)) $stops[] = '#e2e8f0 0% 100%';
    $gradient = 'conic-gradient(' . implode(', ', $stops) . ')';

    $html = '<div class="flex items-center gap-6">';
    $html .= '<div class="health-ring" style="background:' . $gradient . '">';
    $html .= '<div class="health-ring-inner"><span class="health-ring-pct">' . (int)$okPct . '%</span>';
    $html .= '<span class="health-ring-label">Healthy</span></div>';
    $html .= '</div>';

    $html .= '<div class="health-ring-legend">';
    foreach (['ok','warn','crit','unknown'] as $k) {
        $v = $segments[$k] ?? 0;
        if ($v <= 0) continue;
        $html .= '<div class="health-ring-legend-item">';
        $html .= '<span class="health-ring-legend-dot" style="background:' . $colors[$k] . '"></span>';
        $html .= '<span>' . h($labels[$k]) . ': <strong>' . $v . '</strong></span>';
        $html .= '</div>';
    }
    $html .= '</div></div>';

    return $html;
}

/**
 * Render a horizontal bar chart.
 * @param array<int,array{label:string,value:int,color:string}> $rows
 */
function render_bar_chart(array $rows): string {
    if (empty($rows)) return '<div class="empty-state"><div class="empty-state-desc">No data</div></div>';
    $max = max(1, max(array_column($rows, 'value')));

    $html = '<div class="bar-chart">';
    foreach ($rows as $row) {
        $pct = round($row['value'] / $max * 100);
        $html .= '<div class="bar-row">';
        $html .= '<div class="bar-label" title="' . h($row['label']) . '">' . h($row['label']) . '</div>';
        $html .= '<div class="bar-track"><div class="bar-fill bar-fill--' . h($row['color'] ?? 'accent') . '" style="width:' . $pct . '%"></div></div>';
        $html .= '<div class="bar-count">' . (int)$row['value'] . '</div>';
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * Render a stacked health bar.
 * @param array<string,int> $segments ['ok'=>N,'warn'=>N,'crit'=>N,'unknown'=>N]
 */
function render_stacked_bar(array $segments): string {
    $total = max(1, array_sum($segments));
    $html = '<div class="stacked-bar">';
    foreach (['ok','warn','crit','unknown'] as $k) {
        $v = $segments[$k] ?? 0;
        if ($v <= 0) continue;
        $pct = round($v / $total * 100, 1);
        $html .= '<div class="stacked-bar-seg stacked-bar-seg--' . $k . '" style="width:' . $pct . '%" data-tip="' . h(ucfirst($k) . ': ' . $v) . '"></div>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * Render breadcrumbs.
 * @param array<int,array{label:string,href?:string}> $crumbs
 */
function render_breadcrumbs(array $crumbs): string {
    $html = '<nav class="breadcrumbs">';
    $last = count($crumbs) - 1;
    foreach ($crumbs as $i => $c) {
        if ($i === $last) {
            $html .= '<span class="breadcrumbs-current">' . h($c['label']) . '</span>';
        } else {
            $html .= '<a href="' . h($c['href'] ?? '#') . '">' . h($c['label']) . '</a>';
            $html .= '<span class="breadcrumbs-sep">/</span>';
        }
    }
    $html .= '</nav>';
    return $html;
}

/**
 * Render stat card.
 */
function render_stat_card(string $icon, string $value, string $label, string $variant = 'info'): string {
    return '<div class="stat-card stat-card--' . h($variant) . '">'
         . '<div class="stat-card-icon">' . $icon . '</div>'
         . '<div class="stat-card-data"><div class="stat-card-value">' . h($value) . '</div>'
         . '<div class="stat-card-label">' . h($label) . '</div></div></div>';
}
