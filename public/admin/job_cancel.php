<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/ui.php';

$user = require_role('admin');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo h(t('errors.method_not_allowed'));
    exit;
}
csrf_verify();
$jobId = (int)($_POST['job_id'] ?? 0);
if ($jobId <= 0) {
    flash_set('error', t('admin.system.err.missing_job_id'));
    header('Location: '.url_for('admin/system.php'));
    exit;
}
$ok = Worker::cancelJob($jobId);
flash_set($ok?'success':'info', $ok ? t('admin.system.ok_job_cancelled', ['id'=>$jobId]) : t('admin.system.info_job_not_cancelled', ['id'=>$jobId]));
header('Location: '.url_for('admin/system.php'));
exit;
