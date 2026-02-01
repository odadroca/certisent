<?php
declare(strict_types=1);

/**
 * i18n audit: scan repository PHP files for t('key') calls and report missing translation keys per locale.
 *
 * Usage:
 *   php tools/i18n_audit.php
 *
 * Exit codes:
 *   0: no missing keys
 *   1: missing keys found
 */

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "ERROR: cannot resolve repo root\n");
    exit(2);
}

require_once $root . '/app/i18n.php';

$localesDir = $root . '/app/locales';
$localeFiles = glob($localesDir . '/*.php') ?: [];
$locales = [];
foreach ($localeFiles as $f) {
    $base = basename($f, '.php');
    if ($base !== '') $locales[] = $base;
}
sort($locales);

if (!$locales) {
    fwrite(STDERR, "ERROR: no locale catalogs found in app/locales\n");
    exit(2);
}

function scan_keys(string $root): array {
    $keys = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile()) continue;
        $path = $file->getPathname();
        if (substr($path, -4) !== '.php') continue;

        // Skip catalogs and generated/aux areas.
        if (strpos($path, DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'locales' . DIRECTORY_SEPARATOR) !== false) continue;
        if (strpos($path, DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR) !== false) continue;
        if (strpos($path, DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR) !== false) continue;

        $src = @file_get_contents($path);
        if (!is_string($src) || $src === '') continue;

        // Match literal keys only: t('a.b.c' ...) or t("a.b.c"...)
        if (preg_match_all('/\bt\s*\(\s*([\'"])([^\'"]+)\1/s', $src, $m)) {
            foreach ($m[2] as $k) {
                $k = trim((string)$k);
                if ($k !== '') $keys[$k] = true;
            }
        }
    }
    $out = array_keys($keys);
    sort($out);
    return $out;
}

$keys = scan_keys($root);

function load_catalog(string $path): array {
    $cat = require $path;
    return is_array($cat) ? $cat : [];
}

$missingByLocale = [];
foreach ($locales as $loc) {
    $catPath = $localesDir . '/' . $loc . '.php';
    $cat = is_file($catPath) ? load_catalog($catPath) : [];
    $missing = [];
    foreach ($keys as $k) {
        if (!array_key_exists($k, $cat)) $missing[] = $k;
    }
    $missingByLocale[$loc] = $missing;
}

$hasMissing = false;
echo "i18n audit\n";
echo "Locales: " . implode(', ', $locales) . "\n";
echo "Keys found: " . count($keys) . "\n\n";

foreach ($missingByLocale as $loc => $missing) {
    if (!$missing) {
        echo "[" . $loc . "] OK (no missing keys)\n";
        continue;
    }
    $hasMissing = true;
    echo "[" . $loc . "] MISSING " . count($missing) . " key(s):\n";
    foreach ($missing as $k) {
        echo "  - " . $k . "\n";
    }
    echo "\n";
}

exit($hasMissing ? 1 : 0);
