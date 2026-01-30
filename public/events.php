<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ui.php';

$user = require_login();

$monitorId = isset($_GET['monitor_id']) ? (int)$_GET['monitor_id'] : null;
$m = null;
if ($monitorId) {
    $m = MonitorService::getMonitorById($monitorId);
    if (!$m) { http_response_code(404); echo "Not found."; exit; }
    if ($user['role'] !== 'admin' && $user['role'] !== 'auditor' && (int)$m['user_id'] !== (int)$user['id']) {
        http_response_code(403); echo "Forbidden."; exit;
    }
}

if ($monitorId) {
    $st = db()->prepare("SELECT * FROM events WHERE monitor_id=:mid ORDER BY created_at DESC LIMIT 200");
    $st->execute([':mid'=>$monitorId]);
    $events = $st->fetchAll();
} else {
    if ($user['role'] !== 'admin' && $user['role'] !== 'auditor') {
        http_response_code(403); echo "Forbidden."; exit;
    }
    $events = db()->query("SELECT * FROM events ORDER BY created_at DESC LIMIT 200")->fetchAll();
}

render_header('Events', $user);
?>
<div class="bg-white text-black rounded-2xl p-6 shadow">
  <div class="flex items-start justify-between mb-4">
    <div>
      <h1 class="text-xl font-semibold">Event history</h1>
      <?php if ($m): ?>
        <div class="text-sm text-gray-700 font-mono mt-1"><?php echo h($m['url']); ?></div>
      <?php endif; ?>
    </div>
    <a class="text-green-700 hover:underline" href="dashboard.php">Back</a>
  </div>

  <div class="overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead>
        <tr class="text-left border-b">
          <th class="py-2 pr-3">Time (UTC)</th>
          <th class="py-2 pr-3">Severity</th>
          <th class="py-2 pr-3">Type</th>
          <th class="py-2 pr-3">Message</th>
          <th class="py-2 pr-3">Meta</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($events as $e): ?>
          <tr class="border-b align-top">
            <td class="py-2 pr-3 font-mono text-xs"><?php echo h($e['created_at']); ?></td>
            <td class="py-2 pr-3"><?php echo h($e['severity']); ?></td>
            <td class="py-2 pr-3 font-mono text-xs"><?php echo h($e['type']); ?></td>
            <td class="py-2 pr-3"><?php echo h($e['message']); ?></td>
            <td class="py-2 pr-3">
              <div class="font-mono text-xs break-all text-gray-600"><?php echo h(format_event_meta((string)($e['meta_json'] ?? ''))); ?></div>
              <?php if (!empty($e['meta_json'])): ?>
                <details class="mt-1">
                  <summary class="cursor-pointer text-xs text-gray-500">raw</summary>
                  <pre class="mt-2 p-2 bg-gray-100 rounded text-xs overflow-x-auto"><?php echo h((string)$e['meta_json']); ?></pre>
                </details>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php render_footer(); ?>
