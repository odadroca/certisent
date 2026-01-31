<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ui.php';

$user = require_login();
if (!has_role($user,'viewer')) { http_response_code(403); echo t('common.forbidden'); exit; }

$id = (int)($_GET['id'] ?? 0);
$m = MonitorService::getMonitorById($id);
if (!$m) { http_response_code(404); echo t('common.not_found'); exit; }

if ($user['role'] !== 'admin' && (int)$m['user_id'] !== (int)$user['id']) {
    http_response_code(403); echo t('common.forbidden'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    MonitorService::deleteMonitor((int)$user['id'], $id);
    header('Location: dashboard.php');
    exit;
}

render_header(t('page.monitor_delete.title'), $user);
?>
<div class="bg-white text-black rounded-2xl p-6 shadow max-w-xl">
  <h1 class="text-xl font-semibold mb-4"><?php echo t('monitor_delete.heading'); ?></h1>
  <div class="text-sm text-gray-700 mb-4">
    <?php echo t('monitor_delete.stops_monitoring'); ?>: <span class="font-mono"><?php echo h($m['url']); ?></span>
  </div>
  <form method="post" class="flex gap-3 items-center">
    <?php echo csrf_field(); ?>
    <button class="bg-red-700 text-white px-4 py-2 rounded"><?php echo t('common.delete'); ?></button>
    <a class="text-green-700 hover:underline" href="dashboard.php"><?php echo t('common.cancel'); ?></a>
  </form>
</div>
<?php render_footer(); ?>
