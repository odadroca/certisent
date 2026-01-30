<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ui.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

csrf_verify();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    flash_set('error', 'Missing monitor id.');
    header('Location: dashboard.php');
    exit;
}

$m = MonitorService::getMonitorById($id);
if (!$m) {
    flash_set('error', 'Monitor not found.');
    header('Location: dashboard.php');
    exit;
}

// Only viewer/admin can trigger checks (auditor is read-only)
if ($user['role'] === 'auditor') {
    flash_set('error', 'Auditor role is read-only.');
    header('Location: monitor_view.php?id=' . $id);
    exit;
}

// Owner OR admin
if ($user['role'] !== 'admin' && (int)$m['user_id'] !== (int)$user['id']) {
    http_response_code(403);
    echo 'Forbidden.';
    exit;
}

$res = Worker::checkOne($id);
$errors = (int)($res['errors'] ?? 0);
$changed = (int)($res['changed'] ?? 0);
$renewed = (int)($res['renewed'] ?? 0);
$warned = (int)($res['warned'] ?? 0);

if ($errors > 0) {
    flash_set('error', 'Check completed with errors (see events).');
} else {
    $msg = 'Check completed.';
    $msg .= ' changed=' . $changed . ' renewed=' . $renewed . ' warned=' . $warned;
    flash_set('success', $msg);
}

header('Location: monitor_view.php?id=' . $id);
exit;
