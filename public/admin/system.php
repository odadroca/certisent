<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/ui.php';

$user = require_login();
require_role($user, 'admin');

$now = time();
$lastCron = Worker::getSystemState('last_cron_run_at');
$lastCronOk = Worker::getSystemState('last_cron_ok');
$lastCronTs = $lastCron ? strtotime($lastCron . ' UTC') : false;
$ageSeconds = ($lastCronTs !== false) ? max(0, $now - $lastCronTs) : null;

$since24 = gmdate('Y-m-d H:i:s', $now - 24*3600);

// Event counts by severity (last 24h)
$stCounts = db()->prepare("SELECT severity, COUNT(*) AS c FROM events WHERE created_at >= :since GROUP BY severity");
$stCounts->execute([':since' => $since24]);
$counts = ['info'=>0,'warn'=>0,'critical'=>0];
foreach ($stCounts->fetchAll() as $r) {
    $sev = (string)($r['severity'] ?? '');
    if (isset($counts[$sev])) $counts[$sev] = (int)$r['c'];
}

// Recent system events (monitor_id is NULL)
$stSys = db()->prepare("SELECT id, created_at, severity, type, message, meta_json FROM events WHERE monitor_id IS NULL ORDER BY created_at DESC LIMIT 50");
$stSys->execute();
$sysEvents = $stSys->fetchAll();

render_header('Admin · System', $user);
?>

<div class="flex items-start justify-between mb-4">
  <div>
    <div class="text-lg font-semibold">System</div>
    <div class="text-sm text-gray-400">Operator diagnostics (UTC)</div>
  </div>
  <div class="text-sm">
    <a class="text-green-400 hover:underline" href="monitors.php">Monitors</a>
    <span class="text-gray-600 mx-2">·</span>
    <a class="text-green-400 hover:underline" href="users.php">Users</a>
    <span class="text-gray-600 mx-2">·</span>
    <a class="text-green-400 hover:underline" href="audit.php">Audit</a>
  </div>
</div>

<div class="grid md:grid-cols-2 gap-4">
  <div class="bg-white text-black rounded-2xl p-6 shadow">
    <h2 class="font-semibold mb-3">Worker heartbeat</h2>

    <?php if (!$lastCron): ?>
      <div class="text-sm text-gray-700">No heartbeat recorded yet.</div>
    <?php else: ?>
      <div class="text-sm text-gray-700 space-y-2">
        <div><span class="text-gray-500">Last run:</span> <span class="font-mono text-xs"><?php echo h($lastCron); ?> UTC</span></div>
        <div><span class="text-gray-500">Last ok flag:</span> <?php echo h((string)($lastCronOk ?? '')); ?></div>
        <div>
          <span class="text-gray-500">Age:</span>
          <?php
            if ($ageSeconds === null) {
                echo '<span class="text-gray-700">unknown</span>';
            } else {
                $hours = (int)floor($ageSeconds / 3600);
                $mins = (int)floor(($ageSeconds % 3600) / 60);
                $ageStr = $hours . 'h ' . $mins . 'm';
                $stale = $ageSeconds > 12*3600;
                echo $stale
                  ? '<span class="font-semibold text-red-700">' . h($ageStr) . ' (stale)</span>'
                  : '<span class="font-semibold text-green-700">' . h($ageStr) . '</span>';
            }
          ?>
        </div>
      </div>

      <?php if ($ageSeconds !== null && $ageSeconds > 12*3600): ?>
        <div class="mt-4 p-3 rounded bg-red-100 text-red-800 text-sm">
          Heartbeat is older than 12h. If cron is configured, check Hostinger cron logs and PHP runtime errors.
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="bg-white text-black rounded-2xl p-6 shadow">
    <h2 class="font-semibold mb-3">Events (last 24h)</h2>
    <div class="text-sm text-gray-700 space-y-2">
      <div><span class="text-gray-500">Critical:</span> <?php echo (int)$counts['critical']; ?></div>
      <div><span class="text-gray-500">Warn:</span> <?php echo (int)$counts['warn']; ?></div>
      <div><span class="text-gray-500">Info:</span> <?php echo (int)$counts['info']; ?></div>
    </div>
    <div class="mt-4 text-sm">
      <a class="text-green-700 hover:underline" href="<?php echo h(url_for('history.php?severity=critical')); ?>">View critical events</a>
    </div>
  </div>
</div>

<div class="mt-6 bg-white text-black rounded-2xl p-6 shadow overflow-x-auto">
  <h2 class="font-semibold mb-3">System events (50)</h2>
  <?php if (!$sysEvents): ?>
    <div class="text-sm text-gray-700">No system events recorded.</div>
  <?php else: ?>
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
        <?php foreach ($sysEvents as $e): ?>
          <tr class="border-b align-top">
            <td class="py-2 pr-3 font-mono text-xs"><?php echo h((string)$e['created_at']); ?></td>
            <td class="py-2 pr-3"><?php echo h((string)$e['severity']); ?></td>
            <td class="py-2 pr-3 font-mono text-xs"><?php echo h((string)$e['type']); ?></td>
            <td class="py-2 pr-3"><?php echo h((string)$e['message']); ?></td>
            <td class="py-2 pr-3 font-mono text-xs break-all text-gray-600"><?php echo h(format_event_meta((string)($e['meta_json'] ?? ''))); ?></td>
          </tr>
          <?php if (!empty($e['meta_json'])): ?>
            <tr class="border-b">
              <td></td><td></td><td></td><td></td>
              <td>
                <details>
                  <summary class="cursor-pointer text-xs text-gray-600">raw</summary>
                  <pre class="mt-2 p-2 bg-gray-100 rounded text-xs overflow-x-auto"><?php echo h((string)$e['meta_json']); ?></pre>
                </details>
              </td>
            </tr>
          <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
