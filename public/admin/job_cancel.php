<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

$user = require_role('admin');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed.';
    exit;
}
csrf_verify();
$jobId = (int)($_POST['job_id'] ?? 0);
if ($jobId <= 0) {
    flash_set('error','Missing job_id');
    header('Location: '.url_for('admin/system.php'));
    exit;
}
$ok = Worker::cancelJob($jobId);
flash_set($ok?'success':'info', $ok ? ('Job #'.$jobId.' cancelled') : ('Job #'.$jobId.' not cancelled'));
header('Location: '.url_for('admin/system.php'));
exit;
