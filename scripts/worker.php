<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only.";
    exit;
}

$mode = 'due';
$limit = null;

foreach ($argv as $a) {
    if ($a === '--all') $mode = 'all';
    if ($a === '--due') $mode = 'due';
    if (str_starts_with($a, '--limit=')) {
        $limit = (int)substr($a, strlen('--limit='));
    }
}

try {
    $job = Worker::processJobs(50, 25);
    if ($mode === 'all') $res = Worker::runAllChecks($limit);
    else $res = Worker::runDueChecks($limit);

    // Safeguards: run after checks complete (bounded, non-blocking).
    $pruned = Worker::pruneSnapshots();
    $reconciled = Worker::reconcileDenormalized();

    echo json_encode([
        'ok'=>true,'mode'=>$mode,'jobs'=>$job,'result'=>$res,
        'safeguards'=>['pruned'=>$pruned,'reconciled'=>$reconciled],
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    Worker::setSystemState('last_cron_run_at', db_now_utc());
    Worker::setSystemState('last_cron_ok', '0');
    fwrite(STDERR, "ERROR: ".$e->getMessage().PHP_EOL);
    exit(1);
}
