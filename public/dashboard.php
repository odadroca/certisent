<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ui.php';

$user = require_login();
Worker::cronHealthCheck();

$view = (string)($_GET['view'] ?? 'cards');
$monitors = MonitorService::getMonitorsForUser($user);

// ── Compute summary stats ──
$countOk = 0; $countWarn = 0; $countCrit = 0; $countUnknown = 0;
$expiringSoon = []; // monitors expiring within 30 days
foreach ($monitors as $m) {
    $st = $m['last_checked_at'] ? (string)($m['last_status'] ?? 'unknown') : 'not_checked';
    if ($st === 'ok') $countOk++;
    elseif ($st === 'warn') $countWarn++;
    elseif ($st === 'critical') $countCrit++;
    else $countUnknown++;

    $days = $m['last_days_remaining'] !== null ? (int)$m['last_days_remaining'] : null;
    if ($days !== null && $days <= 30 && $m['last_checked_at']) {
        $expiringSoon[] = $m;
    }
}
usort($expiringSoon, fn($a, $b) => ((int)($a['last_days_remaining'] ?? 999)) - ((int)($b['last_days_remaining'] ?? 999)));

$totalMonitors = count($monitors);

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

render_header(t('page.dashboard.title'), $user);
?>

<!-- Summary Stat Cards -->
<div class="stat-cards">
  <?php echo render_stat_card('&#9632;', (string)$totalMonitors, 'Total Monitors', 'info'); ?>
  <?php echo render_stat_card('&#10003;', (string)$countOk, 'Healthy', 'ok'); ?>
  <?php echo render_stat_card('&#9888;', (string)$countWarn, 'Warning', 'warn'); ?>
  <?php echo render_stat_card('&#10007;', (string)$countCrit, 'Critical', 'crit'); ?>
</div>

<!-- Attention Banner -->
<?php if ($countCrit > 0): ?>
  <div class="attention-banner attention-banner--crit">
    <div class="attention-banner-icon">&#9888;</div>
    <div class="attention-banner-text"><strong><?php echo $countCrit; ?> certificate<?php echo $countCrit > 1 ? 's' : ''; ?></strong> need<?php echo $countCrit === 1 ? 's' : ''; ?> immediate attention</div>
    <a class="btn btn-secondary btn-sm attention-banner-action" href="reports/index.php">View Reports</a>
  </div>
<?php elseif ($countWarn > 0): ?>
  <div class="attention-banner attention-banner--warn">
    <div class="attention-banner-icon">&#9888;</div>
    <div class="attention-banner-text"><strong><?php echo $countWarn; ?> certificate<?php echo $countWarn > 1 ? 's' : ''; ?></strong> expiring soon</div>
    <a class="btn btn-secondary btn-sm attention-banner-action" href="reports/expiry.php">Expiry Timeline</a>
  </div>
<?php elseif ($totalMonitors > 0 && $countOk === $totalMonitors): ?>
  <div class="attention-banner attention-banner--ok">
    <div class="attention-banner-icon">&#10003;</div>
    <div class="attention-banner-text">All <strong><?php echo $totalMonitors; ?></strong> certificates are healthy</div>
  </div>
<?php endif; ?>

<!-- Health Bar -->
<?php if ($totalMonitors > 0): ?>
  <div class="mb-4">
    <?php echo render_stacked_bar(['ok' => $countOk, 'warn' => $countWarn, 'crit' => $countCrit, 'unknown' => $countUnknown]); ?>
    <div class="summary-row mt-2">
      <div class="summary-row-item"><span class="summary-row-dot" style="background:var(--ok)"></span> <?php echo $countOk; ?> OK</div>
      <div class="summary-row-item"><span class="summary-row-dot" style="background:var(--warn)"></span> <?php echo $countWarn; ?> Warning</div>
      <div class="summary-row-item"><span class="summary-row-dot" style="background:var(--crit)"></span> <?php echo $countCrit; ?> Critical</div>
      <?php if ($countUnknown > 0): ?>
        <div class="summary-row-item"><span class="summary-row-dot" style="background:#94a3b8"></span> <?php echo $countUnknown; ?> Unknown</div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="page-header">
  <div>
    <div class="page-title"><?php echo t('page.dashboard.title'); ?></div>
    <?php
    $lastCron = Worker::getSystemState('last_cron_run_at');
    $job = Worker::getLatestJobForUser((int)$user['id']);
    ?>
    <div class="text-xs text-light-muted">
      <?php echo t('dashboard.worker_heartbeat'); ?>: <?php echo $lastCron ? h($lastCron).' UTC' : t('common.unknown'); ?>
      <?php if ($job): ?>
        &middot; <?php echo t('dashboard.last_check_now_job'); ?>: #<?php echo (int)$job['id']; ?> <?php echo h((string)$job['status']); ?>
      <?php endif; ?>
    </div>
  </div>
  <div class="flex items-center gap-3">
    <a class="text-xs" href="dashboard.php?view=<?php echo $view==='list'?'cards':'list'; ?>">
      <?php echo t('dashboard.toggle_view', ['view' => $view==='list' ? t('dashboard.view.cards') : t('dashboard.view.list')]); ?>
    </a>
    <?php if (has_role($user,'viewer')): ?>
      <a class="btn btn-primary btn-sm" href="monitor_add.php"><?php echo t('dashboard.add_to_monitor'); ?></a>
    <?php endif; ?>
    <form method="post" action="check_now_all.php" class="inline">
      <?php echo csrf_field(); ?>
      <button class="btn btn-secondary btn-sm" type="submit"><?php echo t('dashboard.check_now_all'); ?></button>
    </form>
  </div>
</div>

<!-- Expiring Soon (if any within 30 days) -->
<?php if (!empty($expiringSoon)): ?>
  <div class="card card-warn mb-6">
    <div class="card-header flex-between">
      <span>Expiring Soon (<?php echo count($expiringSoon); ?>)</span>
      <a class="text-xs" href="reports/expiry.php">View full timeline</a>
    </div>
    <div class="card-body">
      <?php foreach (array_slice($expiringSoon, 0, 5) as $es): ?>
        <?php $days = (int)($es['last_days_remaining'] ?? 0); ?>
        <div class="expiry-row">
          <?php echo badge_status($days <= 0 ? 'critical' : ($days <= 7 ? 'critical' : 'warn')); ?>
          <div class="expiry-row-url"><a href="monitor_view.php?id=<?php echo (int)$es['id']; ?>"><?php echo h($es['url']); ?></a></div>
          <div class="expiry-row-date"><?php echo h(ui_dt($es['last_valid_to'] ?? null) ?: '—'); ?></div>
          <div class="expiry-row-days <?php echo $days <= 7 ? 'expiry-row-days--crit' : 'expiry-row-days--warn'; ?>"><?php echo $days; ?>d</div>
          <div class="expiry-row-bar">
            <?php echo progress_bar($days, $es['last_valid_from'] ?? null, $es['last_valid_to'] ?? null); ?>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (count($expiringSoon) > 5): ?>
        <div class="mt-3 text-sm"><a href="reports/expiry.php">+<?php echo count($expiringSoon) - 5; ?> more</a></div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($view === 'list'): ?>
  <div class="card">
    <div class="card-body">
      <div class="table-wrap">
        <table class="table table-striped">
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
              <td><span class="issuer-tag"><?php echo h((string)($m['last_issuer_cn'] ?? '—')); ?></span></td>
              <td class="td-nowrap"><?php echo h(ui_dt($m['last_valid_to'] ?? null) ?: '—'); ?><?php echo $m['last_valid_to'] ? ' UTC' : ''; ?></td>
              <td class="td-right <?php
                $d = $m['last_days_remaining'];
                if ($d !== null) {
                    echo (int)$d <= 7 ? 'num-crit' : ((int)$d <= 30 ? 'num-warn' : 'num-ok');
                }
              ?>"><?php echo h(ui_num($m['last_days_remaining'] ?? '—')); ?></td>
              <td class="td-nowrap"><?php echo h((string)($m['last_checked_at'] ?? '—')); ?><?php echo $m['last_checked_at'] ? ' UTC' : ''; ?></td>
              <td class="td-nowrap"><?php echo h((string)($m['next_due_at'] ?? '—')); ?><?php echo $m['next_due_at'] ? ' UTC' : ''; ?></td>
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
            <div class="monitor-card-meta"><?php echo t('dashboard.issuer'); ?>: <span class="issuer-tag"><?php echo h((string)($m['last_issuer_cn'] ?? '—')); ?></span></div>
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
