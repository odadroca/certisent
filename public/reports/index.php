<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/ui.php';

$user = require_login();

$monitors = MonitorService::getMonitorsForUser($user);

// ── Summary stats ──
$countOk = 0; $countWarn = 0; $countCrit = 0; $countUnknown = 0;
$issuers = [];
$avgDays = 0;
$daysSum = 0;
$daysCount = 0;

foreach ($monitors as $m) {
    $st = $m['last_checked_at'] ? (string)($m['last_status'] ?? 'unknown') : 'not_checked';
    if ($st === 'ok') $countOk++;
    elseif ($st === 'warn') $countWarn++;
    elseif ($st === 'critical') $countCrit++;
    else $countUnknown++;

    $iss = trim((string)($m['last_issuer_cn'] ?? ''));
    if ($iss !== '' && $iss !== '—') {
        $issuers[$iss] = ($issuers[$iss] ?? 0) + 1;
    }

    if ($m['last_days_remaining'] !== null && $m['last_checked_at']) {
        $daysSum += (int)$m['last_days_remaining'];
        $daysCount++;
    }
}
$avgDays = $daysCount > 0 ? (int)round($daysSum / $daysCount) : 0;
$totalMonitors = count($monitors);

arsort($issuers);

// ── Recent critical events (last 7 days) ──
$since7d = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
$critWhere = "e.severity IN ('critical','warn') AND e.created_at >= :since";
if ($user['role'] !== 'admin' && $user['role'] !== 'auditor') {
    $critWhere .= ' AND m.user_id = :uid';
}
$sqlCrit = "SELECT e.*, m.url AS monitor_url FROM events e LEFT JOIN monitors m ON m.id = e.monitor_id WHERE $critWhere ORDER BY e.created_at DESC LIMIT 10";
$stCrit = db()->prepare($sqlCrit);
$stCrit->bindValue(':since', $since7d);
if ($user['role'] !== 'admin' && $user['role'] !== 'auditor') {
    $stCrit->bindValue(':uid', (int)$user['id'], PDO::PARAM_INT);
}
$stCrit->execute();
$recentCritEvents = $stCrit->fetchAll();

// ── Event counts by type (last 30 days) ──
$since30d = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
$typeWhere = "e.created_at >= :since";
if ($user['role'] !== 'admin' && $user['role'] !== 'auditor') {
    $typeWhere .= ' AND m.user_id = :uid';
}
$sqlTypes = "SELECT e.type, COUNT(*) AS c FROM events e LEFT JOIN monitors m ON m.id = e.monitor_id WHERE $typeWhere GROUP BY e.type ORDER BY c DESC LIMIT 10";
$stTypes = db()->prepare($sqlTypes);
$stTypes->bindValue(':since', $since30d);
if ($user['role'] !== 'admin' && $user['role'] !== 'auditor') {
    $stTypes->bindValue(':uid', (int)$user['id'], PDO::PARAM_INT);
}
$stTypes->execute();
$eventTypes = $stTypes->fetchAll();

render_header('Reports', $user);

echo render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => url_for('dashboard.php')],
    ['label' => 'Reports'],
]);
?>

<div class="page-header">
  <div>
    <div class="page-title">Certificate Reports</div>
    <div class="page-subtitle">Consolidated overview of your TLS certificate health</div>
  </div>
  <div class="report-actions">
    <a class="btn btn-secondary btn-sm" href="<?php echo h(url_for('reports/export.php?type=monitors')); ?>">Export CSV</a>
  </div>
</div>

<!-- Summary Stat Cards -->
<div class="stat-cards">
  <?php echo render_stat_card('&#9632;', (string)$totalMonitors, 'Total Monitors', 'info'); ?>
  <?php echo render_stat_card('&#10003;', (string)$countOk, 'Healthy', 'ok'); ?>
  <?php echo render_stat_card('&#9888;', (string)$countWarn, 'Warning', 'warn'); ?>
  <?php echo render_stat_card('&#10007;', (string)$countCrit, 'Critical', 'crit'); ?>
</div>

<!-- Report Nav Tabs -->
<div class="tabs">
  <a class="tab-link active" href="<?php echo h(url_for('reports/index.php')); ?>">Overview</a>
  <a class="tab-link" href="<?php echo h(url_for('reports/expiry.php')); ?>">Expiry Timeline <span class="tab-count"><?php echo $countWarn + $countCrit; ?></span></a>
  <a class="tab-link" href="<?php echo h(url_for('reports/changes.php')); ?>">Changes & Events</a>
  <a class="tab-link" href="<?php echo h(url_for('reports/export.php')); ?>">Export</a>
</div>

<div class="grid-2">
  <!-- Health Ring -->
  <div class="card card-accent">
    <div class="card-header">Certificate Health</div>
    <div class="card-body">
      <?php if ($totalMonitors > 0): ?>
        <?php echo render_health_ring(['ok' => $countOk, 'warn' => $countWarn, 'crit' => $countCrit, 'unknown' => $countUnknown]); ?>
        <hr class="divider">
        <?php echo render_stacked_bar(['ok' => $countOk, 'warn' => $countWarn, 'crit' => $countCrit, 'unknown' => $countUnknown]); ?>
        <div class="mt-4 stat-grid">
          <div class="stat-item">
            <div class="stat-value"><?php echo $avgDays; ?></div>
            <div class="stat-label">Avg Days Left</div>
          </div>
          <div class="stat-item">
            <div class="stat-value"><?php echo count($issuers); ?></div>
            <div class="stat-label">Unique Issuers</div>
          </div>
        </div>
      <?php else: ?>
        <div class="empty-state">
          <div class="empty-state-title">No monitors yet</div>
          <div class="empty-state-desc">Add monitors to see certificate health data.</div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Issuer Distribution -->
  <div class="card">
    <div class="card-header">Certificate Issuers</div>
    <div class="card-body">
      <?php if (!empty($issuers)): ?>
        <?php
        $issuerBars = [];
        $colors = ['accent','ok','warn','muted','crit'];
        $ci = 0;
        foreach (array_slice($issuers, 0, 8, true) as $name => $count) {
            $issuerBars[] = ['label' => $name, 'value' => $count, 'color' => $colors[$ci % count($colors)]];
            $ci++;
        }
        echo render_bar_chart($issuerBars);
        ?>
      <?php else: ?>
        <div class="empty-state">
          <div class="empty-state-desc">No issuer data available yet.</div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Recent Critical/Warning Events -->
<div class="mt-6 card">
  <div class="card-header flex-between">
    <span>Recent Warnings & Critical Events (7 days)</span>
    <a class="text-xs" href="<?php echo h(url_for('reports/changes.php')); ?>">View all changes</a>
  </div>
  <div class="card-body">
    <?php if (empty($recentCritEvents)): ?>
      <div class="empty-state">
        <div class="empty-state-title">All clear</div>
        <div class="empty-state-desc">No warning or critical events in the past 7 days.</div>
      </div>
    <?php else: ?>
      <div class="timeline">
        <?php foreach ($recentCritEvents as $e): ?>
          <?php
          $dotClass = 'timeline-dot--info';
          if ($e['severity'] === 'critical') $dotClass = 'timeline-dot--crit';
          elseif ($e['severity'] === 'warn') $dotClass = 'timeline-dot--warn';
          ?>
          <div class="timeline-item">
            <div class="timeline-dot <?php echo $dotClass; ?>"></div>
            <div class="timeline-date"><?php echo h((string)$e['created_at']); ?> UTC</div>
            <div class="timeline-content">
              <div class="timeline-title">
                <?php echo badge_status($e['severity'] === 'critical' ? 'critical' : ($e['severity'] === 'warn' ? 'warn' : 'ok')); ?>
                <span class="font-mono text-xs ml-auto"><?php echo h((string)$e['type']); ?></span>
              </div>
              <div class="timeline-meta">
                <?php echo h((string)$e['message']); ?>
                <?php if (!empty($e['monitor_url'])): ?>
                  &middot; <a class="font-mono text-xs" href="<?php echo h(url_for('monitor_view.php?id=' . (int)$e['monitor_id'])); ?>"><?php echo h((string)$e['monitor_url']); ?></a>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Event Type Distribution (Last 30 days) -->
<div class="mt-6 card">
  <div class="card-header">Event Distribution (Last 30 Days)</div>
  <div class="card-body">
    <?php if (!empty($eventTypes)): ?>
      <?php
      $typeBars = [];
      foreach ($eventTypes as $et) {
          $type = (string)$et['type'];
          $color = 'muted';
          if (str_contains($type, 'expired') || str_contains($type, 'failed') || str_contains($type, 'cron_failed')) $color = 'crit';
          elseif (str_contains($type, 'warn') || str_contains($type, 'changed') || str_contains($type, 'wrong') || str_contains($type, 'untrusted') || str_contains($type, 'self_signed') || str_contains($type, 'pin_mismatch')) $color = 'warn';
          elseif (str_contains($type, 'renewed') || str_contains($type, 'completed') || str_contains($type, 'reconciled') || str_contains($type, 'pruned')) $color = 'ok';
          elseif (str_contains($type, 'worker_run') || str_contains($type, 'job')) $color = 'accent';
          $typeBars[] = ['label' => $type, 'value' => (int)$et['c'], 'color' => $color];
      }
      echo render_bar_chart($typeBars);
      ?>
    <?php else: ?>
      <div class="empty-state">
        <div class="empty-state-desc">No events recorded in the past 30 days.</div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php render_footer(); ?>
