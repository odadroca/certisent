<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ui.php';

$user = require_login();
if (!has_role($user,'viewer')) { http_response_code(403); echo "Forbidden."; exit; }

$id = (int)($_GET['id'] ?? 0);
$m = MonitorService::getMonitorById($id);
if (!$m) { http_response_code(404); echo "Not found."; exit; }

// Authorization: owner or admin
if ($user['role'] !== 'admin' && (int)$m['user_id'] !== (int)$user['id']) {
    http_response_code(403); echo "Forbidden."; exit;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $url = (string)($_POST['url'] ?? '');
    $days = max(1, min(365, (int)($_POST['notify_days'] ?? 30)));
    $freq = max(5, min(1440, (int)($_POST['freq_min'] ?? 60)));
    $enabled = isset($_POST['enabled']) ? 1 : 0;
    $noc = isset($_POST['notify_on_change']) ? 1 : 0;
    $nor = isset($_POST['notify_on_renewal']) ? 1 : 0;

    try {
        MonitorService::updateMonitor((int)$user['id'], $id, $url, $days, $freq, $enabled, $noc, $nor);
        header('Location: dashboard.php');
        exit;
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

render_header('Edit monitor', $user);
?>
<div class="bg-white text-black rounded-2xl p-6 shadow max-w-xl">
  <h1 class="text-xl font-semibold mb-4">Edit monitor</h1>
  <?php if ($err): ?>
    <div class="mb-3 p-3 rounded bg-red-100 text-red-800 text-sm"><?php echo h($err); ?></div>
  <?php endif; ?>
  <form method="post" class="space-y-3">
    <?php echo csrf_field(); ?>
    <div>
      <label class="text-sm">URL</label>
      <input name="url" class="w-full border rounded px-3 py-2" value="<?php echo h($_POST['url'] ?? $m['url']); ?>" />
    </div>
    <div class="grid md:grid-cols-2 gap-3">
      <div>
        <label class="text-sm">Notify if expires within (days)</label>
        <input type="number" name="notify_days" class="w-full border rounded px-3 py-2" value="<?php echo h((string)($_POST['notify_days'] ?? $m['notify_days_before_expiry'])); ?>" />
      </div>
      <div>
        <label class="text-sm">Check frequency (minutes)</label>
        <input type="number" name="freq_min" class="w-full border rounded px-3 py-2" value="<?php echo h((string)($_POST['freq_min'] ?? $m['check_frequency_minutes'])); ?>" />
      </div>
    </div>
    <div class="flex items-center gap-2">
      <input type="checkbox" name="enabled" <?php echo ((int)($m['enabled'])===1 ? 'checked' : ''); ?> />
      <label class="text-sm">Enabled</label>
    </div>

    <div class="grid md:grid-cols-2 gap-3">
      <div class="flex items-center gap-2">
        <input type="checkbox" name="notify_on_change" <?php echo ((int)($m['notify_on_change'])===1 ? 'checked' : ''); ?> />
        <label class="text-sm">Notify on change</label>
      </div>
      <div class="flex items-center gap-2">
        <input type="checkbox" name="notify_on_renewal" <?php echo ((int)($m['notify_on_renewal'])===1 ? 'checked' : ''); ?> />
        <label class="text-sm">Notify on renewal</label>
      </div>
    </div>
    <button class="bg-green-700 text-white px-4 py-2 rounded">Save</button>
    <a class="ml-3 text-green-400 hover:underline" href="dashboard.php">Cancel</a>
  </form>

  <div class="mt-6 text-xs text-gray-600">
    <div>Tip: run the worker via cron or call the API worker endpoint.</div>
  </div>
</div>
<?php render_footer(); ?>
