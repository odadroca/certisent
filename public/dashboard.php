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
<div class="page-header">
  <div>
    <div class="page-title"><?php echo t('page.dashboard.title'); ?></div>
    <div class="text-xs text-light-muted"><?php echo t('dashboard.monitors'); ?>: <?php echo count($monitors); ?></div>
  </div>
  <div class="flex items-center gap-3">
    <?php if ($user['role']==='admin'): ?>
      <a class="text-xs" href="admin/users.php"><?php echo t('dashboard.admin'); ?></a>
    <?php endif; ?>
    <a class="text-xs" href="dashboard.php?view=<?php echo $view==='list'?'cards':'list'; ?>">
      <?php echo t('dashboard.toggle_view', ['view' => $view==='list' ? t('dashboard.view.cards') : t('dashboard.view.list')]); ?>
    </a>
    <?php if (has_role($user,'viewer')): ?>
      <a class="btn btn-primary btn-sm" href="monitor_add.php"><?php echo t('dashboard.add_to_monitor'); ?></a>
    <?php endif; ?>
    <form method="post" action="check_now_all.php" class="inline">
      <?php echo csrf_field(); ?>
      <button class="btn btn-primary btn-sm" type="submit"><?php echo t('dashboard.check_now_all'); ?></button>
    </form>
    <a class="btn btn-secondary btn-sm" href="index.php"><?php echo t('nav.quick_check'); ?></a>
  </div>
</div>

<?php
// Show last cron heartbeat (for operators)
$lastCron = Worker::getSystemState('last_cron_run_at');
?>
<div class="text-xs text-light-muted mb-6"><?php echo t('dashboard.worker_heartbeat'); ?>: <?php echo $lastCron ? h($lastCron).' UTC' : t('common.unknown'); ?></div>

<?php $job = Worker::getLatestJobForUser((int)$user['id']); ?>
<div class="text-xs text-light-muted mb-6">
  <?php echo t('dashboard.last_check_now_job'); ?>: <?php echo $job ? ('#'.(int)$job['id'].' · '.h((string)$job['status']).' · '.t('dashboard.job.processed').'='.(int)$job['total_processed']) : t('common.none'); ?>
</div>

<?php if ($view === 'list'): ?>
  <div class="card">
    <div class="card-body">
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th><?php echo t('dashboard.table.status'); ?></th>
              <th><?php echo t('dashboard.table.url'); ?></th>
              <th><?php echo t('dashboard.table.tls'); ?></th>
              <th><?php echo t('dashboard.table.issuer'); ?></th>
              <th><?php echo t('dashboard.table.valid_to'); ?></th>
              <th><?php echo t('dashboard.table.days_left'); ?></th>
              <th><?php echo t('dashboard.table.last_checked'); ?></th>
              <th><?php echo t('dashboard.table.next_due'); ?></th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($monitors as $m): ?>
            <?php $displayStatus = $m['last_checked_at'] ? (string)($m['last_status'] ?? 'unknown') : 'not_checked'; ?>
            <tr>
              <td><?php echo badge_status($displayStatus); ?></td>
              <td class="font-mono"><?php echo h($m['url']); ?></td>
              <td><?php echo h($tlsSummary($m)); ?></td>
              <td><?php echo h((string)($m['last_issuer_cn'] ?? '—')); ?></td>
              <td><?php echo h(ui_dt($m['last_valid_to'] ?? null) ?: '—'); ?><?php echo $m['last_valid_to'] ? ' UTC' : ''; ?></td>
              <td><?php echo h(ui_num($m['last_days_remaining'] ?? '—')); ?></td>
              <td><?php echo h((string)($m['last_checked_at'] ?? '—')); ?><?php echo $m['last_checked_at'] ? ' UTC' : ''; ?></td>
              <td><?php echo h((string)($m['next_due_at'] ?? '—')); ?><?php echo $m['next_due_at'] ? ' UTC' : ''; ?></td>
              <td>
                <a class="text-sub mr-3" href="monitor_view.php?id=<?php echo (int)$m['id']; ?>"><?php echo t('common.view'); ?></a>
                <?php if (has_role($user,'viewer')): ?>
                  <a href="monitor_edit.php?id=<?php echo (int)$m['id']; ?>"><?php echo t('common.edit'); ?></a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="grid-2">
    <?php foreach ($monitors as $m): ?>
      <?php $displayStatus = $m['last_checked_at'] ? (string)($m['last_status'] ?? 'unknown') : 'not_checked'; ?>
      <div class="monitor-card">
        <div class="monitor-card-head">
          <div>
            <div class="monitor-card-url"><?php echo h($m['url']); ?></div>
            <div class="monitor-card-meta"><?php echo t('dashboard.issuer'); ?>: <?php echo h((string)($m['last_issuer_cn'] ?? '—')); ?></div>
            <div class="monitor-card-meta"><?php echo t('dashboard.tls'); ?>: <?php echo h($tlsSummary($m)); ?></div>
          </div>
          <div><?php echo badge_status($displayStatus); ?></div>
        </div>

        <div class="monitor-card-stats">
          <span><?php echo t('dashboard.valid_from'); ?>: <?php echo h(ui_dt($m['last_valid_from'] ?? null) ?: '—'); ?><?php echo $m['last_valid_from'] ? ' UTC' : ''; ?></span>
          <span><?php echo t('dashboard.valid_to'); ?>: <?php echo h(ui_dt($m['last_valid_to'] ?? null) ?: '—'); ?><?php echo $m['last_valid_to'] ? ' UTC' : ''; ?></span>
        </div>
        <?php echo progress_bar($m['last_days_remaining'] !== null ? (int)$m['last_days_remaining'] : null, $m['last_valid_from'] ?? null, $m['last_valid_to'] ?? null); ?>
        <div class="monitor-card-stats">
          <span><?php echo t('dashboard.days_left'); ?>: <span class="font-semibold"><?php echo h(ui_num($m['last_days_remaining'] ?? '—')); ?></span></span>
          <span><?php echo t('dashboard.warn_threshold'); ?>: <?php echo (int)$m['notify_days_before_expiry']; ?> <?php echo t('common.days'); ?></span>
        </div>
        <div class="monitor-card-stats">
          <span><?php echo t('dashboard.last_checked'); ?>: <?php echo h((string)($m['last_checked_at'] ?? '—')); ?><?php echo $m['last_checked_at'] ? ' UTC' : ''; ?></span>
          <span><?php echo t('dashboard.next_due'); ?>: <?php echo h((string)($m['next_due_at'] ?? '—')); ?><?php echo $m['next_due_at'] ? ' UTC' : ''; ?></span>
        </div>

        <div class="monitor-card-actions">
          <?php if (has_role($user,'viewer')): ?>
            <a class="text-sub" href="monitor_view.php?id=<?php echo (int)$m['id']; ?>"><?php echo t('common.view'); ?></a>
            <a href="monitor_edit.php?id=<?php echo (int)$m['id']; ?>"><?php echo t('common.edit'); ?></a>
            <a class="btn-ghost" href="monitor_delete.php?id=<?php echo (int)$m['id']; ?>"><?php echo t('common.delete'); ?></a>
          <?php endif; ?>
          <?php if (!has_role($user,'viewer')): ?>
            <a class="text-sub" href="monitor_view.php?id=<?php echo (int)$m['id']; ?>"><?php echo t('common.view'); ?></a>
          <?php endif; ?>
          <a class="text-sub" href="events.php?monitor_id=<?php echo (int)$m['id']; ?>"><?php echo t('common.events'); ?></a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php render_footer(); ?>
