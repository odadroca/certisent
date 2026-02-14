<?php
declare(strict_types=1);

/**
 * End-to-end test runner (SQLite-backed, zero external dependencies).
 *
 * Usage:  php tests/run_e2e.php
 *
 * Exercises safeguard methods (pruneSnapshots, reconcileDenormalized,
 * cronHealthCheck) against a real in-memory database.
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

// Load the E2E bootstrap (stubs cfg/db/db_now_utc, creates SQLite schema).
require_once __DIR__ . '/e2e_bootstrap.php';

// Load the Worker class (the code under test).
require_once __DIR__ . '/../app/services/Worker.php';

// Initialize schema.
e2e_create_schema();

// Discover and run E2E test files.
$testFiles = glob(__DIR__ . '/e2e_*_test.php');
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
echo "All E2E tests passed.\n";
exit(0);
