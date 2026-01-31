<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ui.php';

$user = require_role('viewer');

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $url = (string)($_POST['url'] ?? '');
    $days = (int)($_POST['notify_days'] ?? 30);
    $days = max(1, min(365, $days));

    try {
        MonitorService::addMonitor((int)$user['id'], $url, $days);
        header('Location: dashboard.php');
        exit;
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

render_header(t('page.monitor_add.title'), $user);
?>
<div class="bg-white text-black rounded-2xl p-6 shadow max-w-xl">
  <h1 class="text-xl font-semibold mb-4"><?php echo t('monitor_add.heading'); ?></h1>
  <?php if ($err): ?>
    <div class="mb-3 p-3 rounded bg-red-100 text-red-800 text-sm"><?php echo h($err); ?></div>
  <?php endif; ?>
  <form method="post" class="space-y-3">
    <?php echo csrf_field(); ?>
    <div>
      <label class="text-sm"><?php echo t('common.url'); ?></label>
      <input name="url" class="w-full border rounded px-3 py-2" placeholder="<?php echo h(t('landing.url_placeholder')); ?>" value="<?php echo h($_POST['url'] ?? ''); ?>" />
      <div class="text-xs text-gray-600 mt-1"><?php echo t('monitor_add.https_only_note'); ?></div>
    </div>
    <div>
      <label class="text-sm"><?php echo t('monitor.common.notify_days'); ?></label>
      <input type="number" name="notify_days" class="w-full border rounded px-3 py-2" value="<?php echo h((string)($_POST['notify_days'] ?? '30')); ?>" />
    </div>
    <button class="bg-green-700 text-white px-4 py-2 rounded"><?php echo t('common.add'); ?></button>
    <a class="ml-3 text-green-400 hover:underline" href="dashboard.php"><?php echo t('common.cancel'); ?></a>
  </form>
</div>
<?php render_footer(); ?>
