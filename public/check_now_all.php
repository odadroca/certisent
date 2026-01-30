<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ui.php';

$user = require_login();
if (!has_role($user,'viewer')) {
    http_response_code(403);
    echo "Forbidden.";
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Method not allowed.";
    exit;
}
csrf_verify();

$jobId = Worker::createRunAllJob((int)$user['id']);

// Try to run at least a slice immediately (time-boxed) so the UI reacts.
$t0 = microtime(true);
$last = null;
while ((microtime(true) - $t0) < 12) {
    $last = Worker::processJobs(50, 6, $jobId);
    if (!$last) break;
    if (($last['status'] ?? '') === 'completed' || ($last['status'] ?? '') === 'failed' || ($last['status'] ?? '') === 'cancelled') {
        break;
    }
}

if ($last && ($last['status'] ?? '') === 'completed') {
    flash_set('success', 'Check-now job completed. Total processed: '.(int)($last['total_processed'] ?? 0));
} else {
    flash_set('info', 'Check-now job started (job #'.$jobId.'). Refresh dashboard to see progress.');
}

header('Location: '.url_for('dashboard.php'));
exit;
