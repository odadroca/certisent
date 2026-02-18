<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/ui.php';

$user = require_login();

// ── Time range filter ──
$range = (string)($_GET['range'] ?? '30');
if (!in_array($range, ['7','30','90'], true)) $range = '30';
$since = gmdate('Y-m-d H:i:s', time() - ((int)$range * 86400));

// ── Build WHERE clause ──
$where = ['e.created_at >= :since'];
$params = [':since' => $since];
if ($user['role'] !== 'admin' && $user['role'] !== 'auditor') {
    $where[] = '(m.user_id = :uid OR e.monitor_id IS NULL)';
    $params[':uid'] = (int)$user['id'];
}
$whereSql = implode(' AND ', $where);

// ── Severity breakdown ──
$sqlSev = "SELECT e.severity, COUNT(*) AS c FROM events e LEFT JOIN monitors m ON m.id = e.monitor_id WHERE $whereSql GROUP BY e.severity";
$stSev = db()->prepare($sqlSev);
$stSev->execute($params);
$sevCounts = ['info' => 0, 'warn' => 0, 'critical' => 0];
foreach ($stSev->fetchAll() as $r) {
    $s = (string)($r['severity'] ?? '');
    if (isset($sevCounts[$s])) $sevCounts[$s] = (int)$r['c'];
}
$totalEvents = array_sum($sevCounts);

// ── Event types ──
$sqlTypes = "SELECT e.type, COUNT(*) AS c FROM events e LEFT JOIN monitors m ON m.id = e.monitor_id WHERE $whereSql GROUP BY e.type ORDER BY c DESC LIMIT 15";
$stTypes = db()->prepare($sqlTypes);
$stTypes->execute($params);
$eventTypes = $stTypes->fetchAll();

// ── Certificate changes ──
$changeTypes = "'changed','renewed','changed_unstable'";
$sqlChanges = "SELECT e.*, m.url AS monitor_url FROM events e LEFT JOIN monitors m ON m.id = e.monitor_id WHERE $whereSql AND e.type IN ($changeTypes) ORDER BY e.created_at DESC LIMIT 50";
$stChanges = db()->prepare($sqlChanges);
$stChanges->execute($params);
$changes = $stChanges->fetchAll();

// ── TLS validation events ──
$tlsTypes = "'tls_wrong_host','tls_self_signed','tls_untrusted_root','tls_pin_mismatch'";
$sqlTls = "SELECT e.*, m.url AS monitor_url FROM events e LEFT JOIN monitors m ON m.id = e.monitor_id WHERE $whereSql AND e.type IN ($tlsTypes) ORDER BY e.created_at DESC LIMIT 30";
$stTls = db()->prepare($sqlTls);
$stTls->execute($params);
$tlsEvents = $stTls->fetchAll();

// ── Expiry warnings ──
$sqlExpiry = "SELECT e.*, m.url AS monitor_url FROM events e LEFT JOIN monitors m ON m.id = e.monitor_id WHERE $whereSql AND e.type IN ('expiry_warning','expired') ORDER BY e.created_at DESC LIMIT 30";
$stExpiry = db()->prepare($sqlExpiry);
$stExpiry->execute($params);
$expiryEvents = $stExpiry->fetchAll();

// ── Daily event volume (for bar chart) ──
$sqlDaily = "SELECT DATE(e.created_at) AS day, COUNT(*) AS c FROM events e LEFT JOIN monitors m ON m.id = e.monitor_id WHERE $whereSql GROUP BY DATE(e.created_at) ORDER BY day DESC LIMIT 30";
$stDaily = db()->prepare($sqlDaily);
$stDaily->execute($params);
$dailyVolume = array_reverse($stDaily->fetchAll());

render_header('Changes & Events', $user);

echo render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => url_for('dashboard.php')],
    ['label' => 'Reports', 'href' => url_for('reports/index.php')],
    ['label' => 'Changes & Events'],
]);
?>

<div class="page-header">
  <div>
    <div class="page-title">Changes & Events</div>
    <div class="page-subtitle">Certificate changes, renewals, and security events</div>
  </div>
  <div class="report-actions">
    <a class="btn btn-secondary btn-sm" href="<?php echo h(url_for('reports/export.php?type=events&range=' . h($range))); ?>">Export CSV</a>
  </div>
</div>

<!-- Report Nav Tabs -->
<div class="tabs">
  <a class="tab-link" href="<?php echo h(url_for('reports/index.php')); ?>">Overview</a>
  <a class="tab-link" href="<?php echo h(url_for('reports/expiry.php')); ?>">Expiry Timeline</a>
  <a class="tab-link active" href="<?php echo h(url_for('reports/changes.php')); ?>">Changes & Events <span class="tab-count"><?php echo $totalEvents; ?></span></a>
  <a class="tab-link" href="<?php echo h(url_for('reports/export.php')); ?>">Export</a>
</div>

<!-- Time Range Selector -->
<div class="flex items-center gap-2 mb-6">
  <span class="text-sm text-muted">Period:</span>
  <?php foreach ([['7','7 days'],['30','30 days'],['90','90 days']] as [$v,$lbl]): ?>
    <a class="btn <?php echo $range === $v ? 'btn-primary' : 'btn-secondary'; ?> btn-xs" href="?range=<?php echo $v; ?>"><?php echo $lbl; ?></a>
  <?php endforeach; ?>
</div>

<!-- Summary -->
<div class="stat-cards">
  <?php echo render_stat_card('&#9632;', (string)$totalEvents, 'Total Events', 'info'); ?>
  <?php echo render_stat_card('&#9432;', (string)$sevCounts['info'], 'Info', 'neutral'); ?>
  <?php echo render_stat_card('&#9888;', (string)$sevCounts['warn'], 'Warning', 'warn'); ?>
  <?php echo render_stat_card('&#10007;', (string)$sevCounts['critical'], 'Critical', 'crit'); ?>
</div>

<div class="grid-2">
  <!-- Event Type Distribution -->
  <div class="card">
    <div class="card-header">Event Types (Last <?php echo h($range); ?> days)</div>
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
        <div class="empty-state"><div class="empty-state-desc">No events in this period.</div></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Daily Event Volume -->
  <div class="card">
    <div class="card-header">Daily Event Volume</div>
    <div class="card-body">
      <?php if (!empty($dailyVolume)): ?>
        <?php
        $dayBars = [];
        foreach ($dailyVolume as $dv) {
            $dayBars[] = ['label' => (string)$dv['day'], 'value' => (int)$dv['c'], 'color' => 'accent'];
        }
        echo render_bar_chart($dayBars);
        ?>
      <?php else: ?>
        <div class="empty-state"><div class="empty-state-desc">No events in this period.</div></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Certificate Changes Timeline -->
<div class="mt-6 card">
  <div class="card-header flex-between">
    <span>Certificate Changes & Renewals (<?php echo count($changes); ?>)</span>
  </div>
  <div class="card-body">
    <?php if (empty($changes)): ?>
      <div class="empty-state">
        <div class="empty-state-title">No changes detected</div>
        <div class="empty-state-desc">No certificate changes or renewals in this period.</div>
      </div>
    <?php else: ?>
      <div class="timeline">
        <?php foreach ($changes as $e): ?>
          <?php
          $meta = event_meta_array((string)($e['meta_json'] ?? ''));
          $dotClass = 'timeline-dot--info';
          if ($e['type'] === 'renewed') $dotClass = 'timeline-dot--ok';
          elseif ($e['type'] === 'changed_unstable') $dotClass = 'timeline-dot--warn';
          else $dotClass = 'timeline-dot--warn';
          ?>
          <div class="timeline-item">
            <div class="timeline-dot <?php echo $dotClass; ?>"></div>
            <div class="timeline-date"><?php echo h((string)$e['created_at']); ?> UTC</div>
            <div class="timeline-content">
              <div class="flex-between">
                <div class="timeline-title"><?php echo h((string)$e['type']); ?></div>
                <?php if (!empty($e['monitor_url'])): ?>
                  <a class="font-mono text-xs" href="<?php echo h(url_for('monitor_view.php?id=' . (int)$e['monitor_id'])); ?>"><?php echo h((string)$e['monitor_url']); ?></a>
                <?php endif; ?>
              </div>
              <div class="timeline-meta"><?php echo h((string)$e['message']); ?></div>
              <?php if (!empty($meta['prev_fingerprint']) && !empty($meta['new_fingerprint'])): ?>
                <div class="mt-2 text-xs">
                  <span class="text-muted">Previous:</span> <span class="font-mono"><?php echo h(substr((string)$meta['prev_fingerprint'], 0, 16)); ?>...</span>
                  <span class="text-muted ml-auto">New:</span> <span class="font-mono"><?php echo h(substr((string)$meta['new_fingerprint'], 0, 16)); ?>...</span>
                </div>
              <?php endif; ?>
              <?php if (isset($meta['early_renewal_days'])): ?>
                <div class="mt-1 text-xs text-muted">Renewed <?php echo (int)$meta['early_renewal_days']; ?> days before previous expiry</div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- TLS Validation Events -->
<?php if (!empty($tlsEvents)): ?>
<div class="mt-6 card card-warn">
  <div class="card-header">TLS Validation Issues (<?php echo count($tlsEvents); ?>)</div>
  <div class="card-body">
    <div class="table-wrap">
      <table class="table table-compact table-striped">
        <thead>
          <tr>
            <th>Time (UTC)</th>
            <th>Type</th>
            <th>Target</th>
            <th>Details</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tlsEvents as $e): ?>
            <tr>
              <td class="font-mono text-xs td-nowrap"><?php echo h((string)$e['created_at']); ?></td>
              <td><?php echo badge_status($e['severity'] === 'critical' ? 'critical' : 'warn'); ?> <span class="font-mono text-xs"><?php echo h((string)$e['type']); ?></span></td>
              <td>
                <?php if (!empty($e['monitor_url'])): ?>
                  <a class="font-mono text-xs" href="<?php echo h(url_for('monitor_view.php?id=' . (int)$e['monitor_id'])); ?>"><?php echo h((string)$e['monitor_url']); ?></a>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td class="text-xs"><?php echo h((string)$e['message']); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Expiry Warnings -->
<?php if (!empty($expiryEvents)): ?>
<div class="mt-6 card card-crit">
  <div class="card-header">Expiry Warnings (<?php echo count($expiryEvents); ?>)</div>
  <div class="card-body">
    <div class="table-wrap">
      <table class="table table-compact table-striped">
        <thead>
          <tr>
            <th>Time (UTC)</th>
            <th>Severity</th>
            <th>Target</th>
            <th>Message</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($expiryEvents as $e): ?>
            <tr>
              <td class="font-mono text-xs td-nowrap"><?php echo h((string)$e['created_at']); ?></td>
              <td><?php echo badge_status($e['severity'] === 'critical' ? 'critical' : 'warn'); ?></td>
              <td>
                <?php if (!empty($e['monitor_url'])): ?>
                  <a class="font-mono text-xs" href="<?php echo h(url_for('monitor_view.php?id=' . (int)$e['monitor_id'])); ?>"><?php echo h((string)$e['monitor_url']); ?></a>
                <?php endif; ?>
              </td>
              <td class="text-xs"><?php echo h((string)$e['message']); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php render_footer(); ?>
