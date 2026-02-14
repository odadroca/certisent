<?php
declare(strict_types=1);

/**
 * Minimal test runner (zero dependencies).
 *
 * Usage:  php tests/run.php
 *
 * Exit code 0 = all pass, 1 = failures.
 * Each test file in tests/ matching *_test.php is included and executed.
 */

$passed = 0;
$failed = 0;
$errors = [];

function assert_true(bool $cond, string $label): void {
    global $passed, $failed, $errors;
    if ($cond) {
        $passed++;
    } else {
        $failed++;
        $errors[] = "FAIL: {$label}";
    }
}

function assert_false(bool $cond, string $label): void {
    assert_true(!$cond, $label);
}

function assert_eq($expected, $actual, string $label): void {
    global $passed, $failed, $errors;
    if ($expected === $actual) {
        $passed++;
    } else {
        $failed++;
        $expectedStr = var_export($expected, true);
        $actualStr = var_export($actual, true);
        $errors[] = "FAIL: {$label} — expected {$expectedStr}, got {$actualStr}";
    }
}

function assert_neq($notExpected, $actual, string $label): void {
    global $passed, $failed, $errors;
    if ($notExpected !== $actual) {
        $passed++;
    } else {
        $failed++;
        $actualStr = var_export($actual, true);
        $errors[] = "FAIL: {$label} — did not expect {$actualStr}";
    }
}

function assert_throws(callable $fn, string $label): void {
    global $passed, $failed, $errors;
    try {
        $fn();
        $failed++;
        $errors[] = "FAIL: {$label} — expected exception, none thrown";
    } catch (Throwable $e) {
        $passed++;
    }
}

// Load only the modules needed for unit tests (no DB, no bootstrap).
require_once __DIR__ . '/../app/services/TlsValidator.php';

// MonitorService::parseUrl() requires config.php for the cfg() function,
// but we can stub it minimally for unit tests.
if (!function_exists('cfg')) {
    function cfg(string $key, $default = null) { return $default; }
}
require_once __DIR__ . '/../app/services/MonitorService.php';

// Discover and run test files.
$testFiles = glob(__DIR__ . '/*_test.php');
if ($testFiles === false) $testFiles = [];
sort($testFiles);

foreach ($testFiles as $file) {
    $name = basename($file);
    echo "--- {$name}\n";
    try {
        require $file;
    } catch (Throwable $e) {
        $failed++;
        $errors[] = "ERROR in {$name}: " . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
    }
}

echo "\n";
echo "Passed: {$passed}  Failed: {$failed}\n";
if (count($errors) > 0) {
    echo "\nFailures:\n";
    foreach ($errors as $e) {
        echo "  {$e}\n";
    }
    exit(1);
}
echo "All tests passed.\n";
exit(0);
