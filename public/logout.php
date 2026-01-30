<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$u = current_user();
if ($u) {
    Audit::log((int)$u['id'], 'user.logout', 'user', (int)$u['id'], []);
}
logout_user();
header('Location: index.php');
exit;
