<?php
declare(strict_types=1);

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';

require_once __DIR__ . '/services/Audit.php';
require_once __DIR__ . '/services/CertFetcher.php';
require_once __DIR__ . '/services/Notifier.php';
require_once __DIR__ . '/services/MonitorService.php';
require_once __DIR__ . '/services/Worker.php';

require_once __DIR__ . '/api/Router.php';

date_default_timezone_set('UTC'); // keep cron consistent; UI prints UTC by default.
