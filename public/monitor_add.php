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
<div class="card max-w-xl">
  <div class="card-body">
    <h1 class="page-title mb-4"><?php echo t('monitor_add.heading'); ?></h1>
    <?php if ($err): ?>
      <div class="form-error"><?php echo h($err); ?></div>
    <?php endif; ?>
    <form method="post" class="space-y-3">
      <?php echo csrf_field(); ?>
      <div>
        <label class="form-label"><?php echo t('common.url'); ?></label>
        <input name="url" class="form-input" placeholder="<?php echo h(t('landing.url_placeholder')); ?>" value="<?php echo h($_POST['url'] ?? ''); ?>" />
        <div class="form-help"><?php echo t('monitor_add.https_only_note'); ?></div>
      </div>
      <div>
        <label class="form-label"><?php echo t('monitor.common.notify_days'); ?></label>
        <input type="number" name="notify_days" class="form-input" value="<?php echo h((string)($_POST['notify_days'] ?? '30')); ?>" />
      </div>
      <button class="btn btn-primary"><?php echo t('common.add'); ?></button>
      <a class="ml-auto" href="dashboard.php"><?php echo t('common.cancel'); ?></a>
    </form>
  </div>
</div>
<?php render_footer(); ?>
