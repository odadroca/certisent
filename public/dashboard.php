<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ui.php';

$user = require_login();
Worker::cronHealthCheck();

$view = (string)($_GET['view'] ?? 'cards');
$monitors = MonitorService::getMonitorsForUser($user);

render_header('Dashboard', $user);
?>
<div class="flex items-center justify-between mb-4">
  <div>
    <div class="text-lg font-semibold">Dashboard</div>
    <div class="text-xs text-gray-400">Monitors: <?php echo count($monitors); ?></div>
  </div>
  <div class="flex gap-3 items-center">
    <?php if ($user['role']==='admin'): ?>
      <a class="text-xs text-green-400 hover:underline" href="admin/users.php">Admin</a>
    <?php endif; ?>
    <a class="text-xs text-green-400 hover:underline" href="dashboard.php?view=<?php echo $view==='list'?'cards':'list'; ?>">
      Toggle <?php echo $view==='list'?'cards':'list'; ?> view
    </a>
    <?php if (has_role($user,'viewer')): ?>
      <a class="bg-green-700 text-white px-3 py-2 rounded" href="monitor_add.php">Add to monitor</a>
    <?php endif; ?>
    <a class="bg-white text-black px-3 py-2 rounded" href="index.php">Check now</a>
  </div>
</div>

<?php
// Show last cron heartbeat (for operators)
$lastCron = Worker::getSystemState('last_cron_run_at');
?>
<div class="text-xs text-gray-400 mb-6">Worker heartbeat: <?php echo $lastCron ? h($lastCron).' UTC' : 'unknown'; ?></div>

<?php if ($view === 'list'): ?>
  <div class="bg-white text-black rounded-2xl p-4 shadow overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead>
        <tr class="text-left border-b">
          <th class="py-2 pr-3">Status</th>
          <th class="py-2 pr-3">URL</th>
          <th class="py-2 pr-3">Issuer</th>
          <th class="py-2 pr-3">Valid to</th>
          <th class="py-2 pr-3">Days left</th>
          <th class="py-2 pr-3"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($monitors as $m): ?>
        <?php $snap = MonitorService::getLatestSnapshot((int)$m['id']); ?>
        <?php $displayStatus = $snap ? (string)($m['last_status'] ?? 'unknown') : 'not_checked'; ?>
        <tr class="border-b">
          <td class="py-2 pr-3"><?php echo badge_status($displayStatus); ?></td>
          <td class="py-2 pr-3 font-mono"><?php echo h($m['url']); ?></td>
          <td class="py-2 pr-3"><?php echo h((string)($snap['issuer_cn'] ?? '—')); ?></td>
          <td class="py-2 pr-3"><?php echo h((string)($snap['valid_to'] ?? '—')); ?><?php echo $snap ? ' UTC' : ''; ?></td>
          <td class="py-2 pr-3"><?php echo h((string)($snap['days_remaining'] ?? '—')); ?></td>
          <td class="py-2 pr-3">
            <a class="text-gray-800 hover:underline mr-3" href="monitor_view.php?id=<?php echo (int)$m['id']; ?>">View</a>
            <?php if (has_role($user,'viewer')): ?>
              <a class="text-green-700 hover:underline" href="monitor_edit.php?id=<?php echo (int)$m['id']; ?>">Edit</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php else: ?>
  <div class="grid md:grid-cols-2 gap-4">
    <?php foreach ($monitors as $m): ?>
      <?php $snap = MonitorService::getLatestSnapshot((int)$m['id']); ?>
      <?php $displayStatus = $snap ? (string)($m['last_status'] ?? 'unknown') : 'not_checked'; ?>
      <div class="bg-white text-black rounded-2xl p-5 shadow">
        <div class="flex items-start justify-between gap-3">
          <div>
            <div class="font-mono text-sm break-all"><?php echo h($m['url']); ?></div>
            <div class="text-xs text-gray-600 mt-1">Issuer: <?php echo h((string)($snap['issuer_cn'] ?? '—')); ?></div>
          </div>
          <div><?php echo badge_status($displayStatus); ?></div>
        </div>

        <div class="mt-4 space-y-2">
          <div class="text-xs text-gray-600 flex justify-between">
            <span>Valid from: <?php echo h((string)($snap['valid_from'] ?? '—')); ?><?php echo $snap ? ' UTC' : ''; ?></span>
            <span>Valid to: <?php echo h((string)($snap['valid_to'] ?? '—')); ?><?php echo $snap ? ' UTC' : ''; ?></span>
          </div>
          <?php echo progress_bar($snap ? (int)$snap['days_remaining'] : null, $snap['valid_from'] ?? null, $snap['valid_to'] ?? null); ?>
          <div class="text-xs text-gray-700 flex justify-between">
            <span>Days left: <span class="font-semibold"><?php echo h((string)($snap['days_remaining'] ?? '—')); ?></span></span>
            <span>Warn threshold: <?php echo (int)$m['notify_days_before_expiry']; ?> days</span>
          </div>
        </div>

        <div class="mt-4 flex gap-3 text-sm">
          <?php if (has_role($user,'viewer')): ?>
            <a class="text-gray-800 hover:underline" href="monitor_view.php?id=<?php echo (int)$m['id']; ?>">View</a>
            <a class="text-green-700 hover:underline" href="monitor_edit.php?id=<?php echo (int)$m['id']; ?>">Edit</a>
            <a class="text-red-700 hover:underline" href="monitor_delete.php?id=<?php echo (int)$m['id']; ?>">Delete</a>
          <?php endif; ?>
          <?php if (!has_role($user,'viewer')): ?>
            <a class="text-gray-800 hover:underline" href="monitor_view.php?id=<?php echo (int)$m['id']; ?>">View</a>
          <?php endif; ?>
          <a class="text-gray-800 hover:underline" href="events.php?monitor_id=<?php echo (int)$m['id']; ?>">Events</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php render_footer(); ?>
