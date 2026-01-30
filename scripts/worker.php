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
    if ($mode === 'all') $res = Worker::runAllChecks($limit);
    else $res = Worker::runDueChecks($limit);

    echo json_encode(['ok'=>true,'mode'=>$mode,'result'=>$res], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    Worker::setSystemState('last_cron_run_at', db_now_utc());
    Worker::setSystemState('last_cron_ok', '0');
    fwrite(STDERR, "ERROR: ".$e->getMessage().PHP_EOL);
    exit(1);
}
