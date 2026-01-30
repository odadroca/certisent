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

render_header('Add monitor', $user);
?>
<div class="bg-white text-black rounded-2xl p-6 shadow max-w-xl">
  <h1 class="text-xl font-semibold mb-4">Add URL to monitoring</h1>
  <?php if ($err): ?>
    <div class="mb-3 p-3 rounded bg-red-100 text-red-800 text-sm"><?php echo h($err); ?></div>
  <?php endif; ?>
  <form method="post" class="space-y-3">
    <?php echo csrf_field(); ?>
    <div>
      <label class="text-sm">URL</label>
      <input name="url" class="w-full border rounded px-3 py-2" placeholder="https://api.example.com" value="<?php echo h($_POST['url'] ?? ''); ?>" />
      <div class="text-xs text-gray-600 mt-1">HTTPS only in v0. Port is supported (e.g. https://example.com:8443/).</div>
    </div>
    <div>
      <label class="text-sm">Notify if expires within (days)</label>
      <input type="number" name="notify_days" class="w-full border rounded px-3 py-2" value="<?php echo h((string)($_POST['notify_days'] ?? '30')); ?>" />
    </div>
    <button class="bg-green-700 text-white px-4 py-2 rounded">Add</button>
    <a class="ml-3 text-green-400 hover:underline" href="dashboard.php">Cancel</a>
  </form>
</div>
<?php render_footer(); ?>
