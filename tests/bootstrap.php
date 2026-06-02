<?php
declare(strict_types=1);

/**
 * Minimal test bootstrap.
 *
 * Loads only the pure service classes that don't require DB/HTTP/session state.
 * Tests that need wider app context should require additional files explicitly.
 */

$root = dirname(__DIR__);

require_once $root . '/app/services/TlsValidator.php';
