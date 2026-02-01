<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ui.php';

$user = require_login();
Worker::cronHealthCheck();

$view = (string)($_GET['view'] ?? 'cards');
$monitors = MonitorService::getMonitorsForUser($user);

render_header(t('page.dashboard.title'), $user);

$tlsSummary = function(array $m): string {
    $mode = (string)($m['tls_validation_mode'] ?? 'off');
    if ($mode === 'off') {
        return t('tls.value.off');
    }
    if (array_key_exists('hostname_ok', $m) && $m['hostname_ok'] !== null && (int)$m['hostname_ok'] === 0) {
        return 'wrong.host';
    }
    if (array_key_exists('trust_ok', $m) && $m['trust_ok'] !== null && (int)$m['trust_ok'] === 0) {
        $cat = (string)($m['trust_category'] ?? 'tls_untrusted_unknown');
        if ($cat === 'tls_self_signed') {
            return t('tls.category.tls_self_signed');
        }
        if ($cat === 'tls_untrusted_root') {
            return t('tls.category.tls_untrusted_root');
        }
        return t('tls.category.tls_untrusted_unknown');
    }
    if (
        array_key_exists('hostname_ok', $m) && $m['hostname_ok'] !== null && (int)$m['hostname_ok'] === 1 &&
        array_key_exists('trust_ok', $m) && $m['trust_ok'] !== null && (int)$m['trust_ok'] === 1
    ) {
        return t('tls.value.ok');
    }
    return t('tls.value.unknown');
};
?>
<div class="flex items-center justify-between mb-4">
  <div>
    <div class="text-lg font-semibold"><?php echo t('page.dashboard.title'); ?></div>
    <div class="text-xs text-gray-400"><?php echo t('dashboard.monitors'); ?>: <?php echo count($monitors); ?></div>
  </div>
  <div class="flex gap-3 items-center">
    <?php if ($user['role']==='admin'): ?>
      <a class="text-xs text-green-400 hover:underline" href="admin/users.php"><?php echo t('dashboard.admin'); ?></a>
    <?php endif; ?>
    <a class="text-xs text-green-400 hover:underline" href="dashboard.php?view=<?php echo $view==='list'?'cards':'list'; ?>">
      <?php echo t('dashboard.toggle_view', ['view' => $view==='list' ? t('dashboard.view.cards') : t('dashboard.view.list')]); ?>
    </a>
    <?php if (has_role($user,'viewer')): ?>
      <a class="bg-green-700 text-white px-3 py-2 rounded" href="monitor_add.php"><?php echo t('dashboard.add_to_monitor'); ?></a>
    <?php endif; ?>
    <form method="post" action="check_now_all.php" class="inline">
      <?php echo csrf_field(); ?>
      <button class="bg-green-700 text-white px-3 py-2 rounded" type="submit"><?php echo t('dashboard.check_now_all'); ?></button>
    </form>
    <a class="bg-white text-black px-3 py-2 rounded" href="index.php"><?php echo t('nav.quick_check'); ?></a>
  </div>
</div>

<?php
// Show last cron heartbeat (for operators)
$lastCron = Worker::getSystemState('last_cron_run_at');
?>
<div class="text-xs text-gray-400 mb-6"><?php echo t('dashboard.worker_heartbeat'); ?>: <?php echo $lastCron ? h($lastCron).' UTC' : t('common.unknown'); ?></div>

<?php $job = Worker::getLatestJobForUser((int)$user['id']); ?>
<div class="text-xs text-gray-400 mb-6">
  <?php echo t('dashboard.last_check_now_job'); ?>: <?php echo $job ? ('#'.(int)$job['id'].' · '.h((string)$job['status']).' · '.t('dashboard.job.processed').'='.(int)$job['total_processed']) : t('common.none'); ?>
</div>

<?php if ($view === 'list'): ?>
  <div class="bg-white text-black rounded-2xl p-4 shadow overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead>
        <tr class="text-left border-b">
          <th class="py-2 pr-3"><?php echo t('dashboard.table.status'); ?></th>
          <th class="py-2 pr-3"><?php echo t('dashboard.table.url'); ?></th>
          <th class="py-2 pr-3"><?php echo t('dashboard.table.tls'); ?></th>
          <th class="py-2 pr-3"><?php echo t('dashboard.table.issuer'); ?></th>
          <th class="py-2 pr-3"><?php echo t('dashboard.table.valid_to'); ?></th>
          <th class="py-2 pr-3"><?php echo t('dashboard.table.days_left'); ?></th>
          <th class="py-2 pr-3"><?php echo t('dashboard.table.last_checked'); ?></th>
          <th class="py-2 pr-3"><?php echo t('dashboard.table.next_due'); ?></th>
          <th class="py-2 pr-3"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($monitors as $m): ?>
        <?php $displayStatus = $m['last_checked_at'] ? (string)($m['last_status'] ?? 'unknown') : 'not_checked'; ?>
        <tr class="border-b">
          <td class="py-2 pr-3"><?php echo badge_status($displayStatus); ?></td>
          <td class="py-2 pr-3 font-mono"><?php echo h($m['url']); ?></td>
          <td class="py-2 pr-3"><?php echo h($tlsSummary($m)); ?></td>
          <td class="py-2 pr-3"><?php echo h((string)($m['last_issuer_cn'] ?? '—')); ?></td>
          <td class="py-2 pr-3"><?php echo h(ui_dt($m['last_valid_to'] ?? null) ?: '—'); ?><?php echo $m['last_valid_to'] ? ' UTC' : ''; ?></td>
          <td class="py-2 pr-3"><?php echo h(ui_num($m['last_days_remaining'] ?? '—')); ?></td>
          <td class="py-2 pr-3"><?php echo h((string)($m['last_checked_at'] ?? '—')); ?><?php echo $m['last_checked_at'] ? ' UTC' : ''; ?></td>
          <td class="py-2 pr-3"><?php echo h((string)($m['next_due_at'] ?? '—')); ?><?php echo $m['next_due_at'] ? ' UTC' : ''; ?></td>
          <td class="py-2 pr-3">
            <a class="text-gray-800 hover:underline mr-3" href="monitor_view.php?id=<?php echo (int)$m['id']; ?>"><?php echo t('common.view'); ?></a>
            <?php if (has_role($user,'viewer')): ?>
              <a class="text-green-700 hover:underline" href="monitor_edit.php?id=<?php echo (int)$m['id']; ?>"><?php echo t('common.edit'); ?></a>
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
      <?php $displayStatus = $m['last_checked_at'] ? (string)($m['last_status'] ?? 'unknown') : 'not_checked'; ?>
      <div class="bg-white text-black rounded-2xl p-5 shadow">
        <div class="flex items-start justify-between gap-3">
          <div>
            <div class="font-mono text-sm break-all"><?php echo h($m['url']); ?></div>
            <div class="text-xs text-gray-600 mt-1"><?php echo t('dashboard.issuer'); ?>: <?php echo h((string)($m['last_issuer_cn'] ?? '—')); ?></div>
            <div class="text-xs text-gray-600"><?php echo t('dashboard.tls'); ?>: <?php echo h($tlsSummary($m)); ?></div>
          </div>
          <div><?php echo badge_status($displayStatus); ?></div>
        </div>

        <div class="mt-4 space-y-2">
          <div class="text-xs text-gray-600 flex justify-between">
            <span><?php echo t('dashboard.valid_from'); ?>: <?php echo h(ui_dt($m['last_valid_from'] ?? null) ?: '—'); ?><?php echo $m['last_valid_from'] ? ' UTC' : ''; ?></span>
            <span><?php echo t('dashboard.valid_to'); ?>: <?php echo h(ui_dt($m['last_valid_to'] ?? null) ?: '—'); ?><?php echo $m['last_valid_to'] ? ' UTC' : ''; ?></span>
          </div>
          <?php echo progress_bar($m['last_days_remaining'] !== null ? (int)$m['last_days_remaining'] : null, $m['last_valid_from'] ?? null, $m['last_valid_to'] ?? null); ?>
          <div class="text-xs text-gray-700 flex justify-between">
            <span><?php echo t('dashboard.days_left'); ?>: <span class="font-semibold"><?php echo h(ui_num($m['last_days_remaining'] ?? '—')); ?></span></span>
            <span><?php echo t('dashboard.warn_threshold'); ?>: <?php echo (int)$m['notify_days_before_expiry']; ?> <?php echo t('common.days'); ?></span>
          </div>
          <div class="text-xs text-gray-600 flex justify-between">
            <span><?php echo t('dashboard.last_checked'); ?>: <?php echo h((string)($m['last_checked_at'] ?? '—')); ?><?php echo $m['last_checked_at'] ? ' UTC' : ''; ?></span>
            <span><?php echo t('dashboard.next_due'); ?>: <?php echo h((string)($m['next_due_at'] ?? '—')); ?><?php echo $m['next_due_at'] ? ' UTC' : ''; ?></span>
          </div>
        </div>

        <div class="mt-4 flex gap-3 text-sm">
          <?php if (has_role($user,'viewer')): ?>
            <a class="text-gray-800 hover:underline" href="monitor_view.php?id=<?php echo (int)$m['id']; ?>"><?php echo t('common.view'); ?></a>
            <a class="text-green-700 hover:underline" href="monitor_edit.php?id=<?php echo (int)$m['id']; ?>"><?php echo t('common.edit'); ?></a>
            <a class="text-red-700 hover:underline" href="monitor_delete.php?id=<?php echo (int)$m['id']; ?>"><?php echo t('common.delete'); ?></a>
          <?php endif; ?>
          <?php if (!has_role($user,'viewer')): ?>
            <a class="text-gray-800 hover:underline" href="monitor_view.php?id=<?php echo (int)$m['id']; ?>"><?php echo t('common.view'); ?></a>
          <?php endif; ?>
          <a class="text-gray-800 hover:underline" href="events.php?monitor_id=<?php echo (int)$m['id']; ?>"><?php echo t('common.events'); ?></a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php render_footer(); ?>
