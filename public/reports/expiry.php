<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/ui.php';

$user = require_login();

$monitors = MonitorService::getMonitorsForUser($user);

// ── Group by urgency ──
$expired = [];
$thisWeek = [];     // 0-7 days
$thisMonth = [];    // 8-30 days
$thisQuarter = [];  // 31-90 days
$healthy = [];      // >90 days
$unchecked = [];

foreach ($monitors as $m) {
    if (!$m['last_checked_at']) {
        $unchecked[] = $m;
        continue;
    }
    $days = $m['last_days_remaining'] !== null ? (int)$m['last_days_remaining'] : null;
    if ($days === null) {
        $unchecked[] = $m;
    } elseif ($days <= 0) {
        $expired[] = $m;
    } elseif ($days <= 7) {
        $thisWeek[] = $m;
    } elseif ($days <= 30) {
        $thisMonth[] = $m;
    } elseif ($days <= 90) {
        $thisQuarter[] = $m;
    } else {
        $healthy[] = $m;
    }
}

// Sort each group by days remaining ascending.
$sortByDays = fn($a, $b) => ((int)($a['last_days_remaining'] ?? 999)) - ((int)($b['last_days_remaining'] ?? 999));
usort($expired, $sortByDays);
usort($thisWeek, $sortByDays);
usort($thisMonth, $sortByDays);
usort($thisQuarter, $sortByDays);
usort($healthy, $sortByDays);

render_header('Expiry Timeline', $user);

echo render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => url_for('dashboard.php')],
    ['label' => 'Reports', 'href' => url_for('reports/index.php')],
    ['label' => 'Expiry Timeline'],
]);
?>

<div class="page-header">
  <div>
    <div class="page-title">Expiry Timeline</div>
    <div class="page-subtitle">Certificates grouped by time to expiration</div>
  </div>
  <div class="report-actions">
    <a class="btn btn-secondary btn-sm" href="<?php echo h(url_for('reports/export.php?type=expiry')); ?>">Export CSV</a>
  </div>
</div>

<!-- Report Nav Tabs -->
<div class="tabs">
  <a class="tab-link" href="<?php echo h(url_for('reports/index.php')); ?>">Overview</a>
  <a class="tab-link active" href="<?php echo h(url_for('reports/expiry.php')); ?>">Expiry Timeline <span class="tab-count"><?php echo count($expired) + count($thisWeek) + count($thisMonth); ?></span></a>
  <a class="tab-link" href="<?php echo h(url_for('reports/changes.php')); ?>">Changes & Events</a>
  <a class="tab-link" href="<?php echo h(url_for('reports/export.php')); ?>">Export</a>
</div>

<!-- Summary Row -->
<div class="summary-row mb-4">
  <div class="summary-row-item"><span class="summary-row-dot" style="background:var(--crit)"></span> <?php echo count($expired); ?> Expired</div>
  <div class="summary-row-item"><span class="summary-row-dot" style="background:var(--crit)"></span> <?php echo count($thisWeek); ?> This week</div>
  <div class="summary-row-item"><span class="summary-row-dot" style="background:var(--warn)"></span> <?php echo count($thisMonth); ?> This month</div>
  <div class="summary-row-item"><span class="summary-row-dot" style="background:var(--accent)"></span> <?php echo count($thisQuarter); ?> This quarter</div>
  <div class="summary-row-item"><span class="summary-row-dot" style="background:var(--ok)"></span> <?php echo count($healthy); ?> 90+ days</div>
</div>

<div class="card">
  <div class="card-body">

<?php
function render_expiry_group(string $title, array $monitors, string $variant, string $daysClass): void {
    if (empty($monitors)) return;
    ?>
    <div class="expiry-group expiry-group--<?php echo h($variant); ?>">
      <div class="expiry-group-header">
        <h3><?php echo h($title); ?></h3>
        <span class="expiry-group-count"><?php echo count($monitors); ?></span>
      </div>
      <?php foreach ($monitors as $m): ?>
        <?php $days = (int)($m['last_days_remaining'] ?? 0); ?>
        <div class="expiry-row">
          <?php echo badge_status($m['last_status'] ?? 'unknown'); ?>
          <div class="expiry-row-url"><a href="<?php echo h(url_for('monitor_view.php?id=' . (int)$m['id'])); ?>"><?php echo h($m['url']); ?></a></div>
          <div class="expiry-row-date"><?php echo h(ui_dt($m['last_valid_to'] ?? null) ?: '—'); ?> UTC</div>
          <div class="expiry-row-days <?php echo h($daysClass); ?>"><?php echo $days; ?>d</div>
          <div class="expiry-row-bar">
            <?php echo progress_bar($days, $m['last_valid_from'] ?? null, $m['last_valid_to'] ?? null); ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
}

render_expiry_group('Expired', $expired, 'crit', 'expiry-row-days--crit');
render_expiry_group('Expiring This Week (1-7 days)', $thisWeek, 'crit', 'expiry-row-days--crit');
render_expiry_group('Expiring This Month (8-30 days)', $thisMonth, 'warn', 'expiry-row-days--warn');
render_expiry_group('Expiring This Quarter (31-90 days)', $thisQuarter, 'ok', 'expiry-row-days--ok');
render_expiry_group('Healthy (90+ days)', $healthy, 'ok', 'expiry-row-days--ok');

if (!empty($unchecked)):
?>
    <div class="expiry-group">
      <div class="expiry-group-header">
        <h3>Not Yet Checked</h3>
        <span class="expiry-group-count" style="background:#f1f5f9;color:#64748b"><?php echo count($unchecked); ?></span>
      </div>
      <?php foreach ($unchecked as $m): ?>
        <div class="expiry-row">
          <?php echo badge_status('not_checked'); ?>
          <div class="expiry-row-url"><a href="<?php echo h(url_for('monitor_view.php?id=' . (int)$m['id'])); ?>"><?php echo h($m['url']); ?></a></div>
          <div class="expiry-row-date">—</div>
          <div class="expiry-row-days" style="color:#94a3b8">—</div>
          <div class="expiry-row-bar">
            <div class="progress"><div class="progress-bar" style="width:0%"></div></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (count($monitors) === 0): ?>
    <div class="empty-state">
      <div class="empty-state-title">No monitors</div>
      <div class="empty-state-desc">Add monitors to see the expiry timeline.</div>
    </div>
<?php endif; ?>

  </div>
</div>

<?php render_footer(); ?>
