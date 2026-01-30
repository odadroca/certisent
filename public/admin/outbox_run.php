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
$res = Notifier::processOutbox(200);
$msg = 'Outbox processed: processed='.(int)($res['processed']??0).', sent='.(int)($res['sent']??0).', failed='.(int)($res['failed']??0).', pending='.(int)($res['pending']??0);
flash_set('success', $msg);
header('Location: '.url_for('admin/system.php'));
exit;
