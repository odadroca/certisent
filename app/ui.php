<?php
declare(strict_types=1);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

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
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>'.h($title).' · '.$appName.'</title>';
    echo '<script src="https://cdn.tailwindcss.com"></script>';
    echo '</head><body class="bg-black text-white min-h-screen">';
    echo '<div class="max-w-6xl mx-auto px-4 py-6">';
    echo '<div class="flex items-center justify-between mb-6">';
    echo '<div class="text-2xl font-semibold"><span class="text-green-400">Certinel</span> <span class="text-gray-300 text-base">certificate sentinel</span></div>';
    echo '<div class="text-sm">';
    if ($user) {
        echo '<span class="text-gray-300 mr-3">'.h($user['email']).' ('.h($user['role']).')</span>';
        echo '<a class="text-green-400 hover:underline" href="'.h(url_for('logout.php')).'">Sign out</a>';
    } else {
        echo '<a class="text-green-400 hover:underline mr-3" href="'.h(url_for('login.php')).'">Sign in</a>';
        echo '<a class="text-green-400 hover:underline" href="'.h(url_for('register.php')).'">Register</a>';
    }
    echo '</div></div>';
}

function render_footer(): void {
    echo '<div class="mt-10 text-xs text-gray-500">Certinel v0 · UTC timestamps</div>';
    echo '</div></body></html>';
}

function badge_status(?string $status): string {
    $status = $status ?: 'unknown';
    $map = [
        'ok' => 'bg-green-700',
        'warn' => 'bg-yellow-700',
        'critical' => 'bg-red-700',
        'unknown' => 'bg-gray-700',
    ];
    $cls = $map[$status] ?? 'bg-gray-700';
    return '<span class="px-2 py-1 rounded '.$cls.' text-xs">'.h(strtoupper($status)).'</span>';
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
