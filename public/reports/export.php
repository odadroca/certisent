<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/ui.php';

$user = require_login();

$type = (string)($_GET['type'] ?? '');

// ── Handle CSV downloads ──
if ($type === 'monitors') {
    $monitors = MonitorService::getMonitorsForUser($user);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="certisent-monitors-' . gmdate('Ymd') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['URL', 'Host', 'Port', 'Status', 'Issuer', 'Days Remaining', 'Valid From', 'Valid To', 'Last Checked', 'Fingerprint SHA256', 'TLS Validation', 'Enabled']);

    foreach ($monitors as $m) {
        fputcsv($out, [
            $m['url'],
            $m['host'],
            (int)$m['port'],
            $m['last_status'] ?? 'not_checked',
            $m['last_issuer_cn'] ?? '',
            $m['last_days_remaining'] ?? '',
            $m['last_valid_from'] ?? '',
            $m['last_valid_to'] ?? '',
            $m['last_checked_at'] ?? '',
            $m['last_fingerprint_sha256'] ?? '',
            $m['tls_validation_mode'] ?? 'off',
            (int)$m['enabled'],
        ]);
    }
    fclose($out);
    exit;
}

if ($type === 'expiry') {
    $monitors = MonitorService::getMonitorsForUser($user);

    // Sort by days remaining ascending.
    usort($monitors, fn($a, $b) => ((int)($a['last_days_remaining'] ?? 9999)) - ((int)($b['last_days_remaining'] ?? 9999)));

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="certisent-expiry-' . gmdate('Ymd') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['URL', 'Status', 'Days Remaining', 'Valid To', 'Issuer', 'Fingerprint SHA256']);

    foreach ($monitors as $m) {
        fputcsv($out, [
            $m['url'],
            $m['last_status'] ?? 'not_checked',
            $m['last_days_remaining'] ?? '',
            $m['last_valid_to'] ?? '',
            $m['last_issuer_cn'] ?? '',
            $m['last_fingerprint_sha256'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

if ($type === 'events') {
    $range = (string)($_GET['range'] ?? '30');
    if (!in_array($range, ['7','30','90'], true)) $range = '30';
    $since = gmdate('Y-m-d H:i:s', time() - ((int)$range * 86400));

    $where = ['e.created_at >= :since'];
    $params = [':since' => $since];
    if ($user['role'] !== 'admin' && $user['role'] !== 'auditor') {
        $where[] = '(m.user_id = :uid OR e.monitor_id IS NULL)';
        $params[':uid'] = (int)$user['id'];
    }
    $whereSql = implode(' AND ', $where);

    $sql = "SELECT e.*, m.url AS monitor_url FROM events e LEFT JOIN monitors m ON m.id = e.monitor_id WHERE $whereSql ORDER BY e.created_at DESC LIMIT 5000";
    $st = db()->prepare($sql);
    $st->execute($params);
    $events = $st->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="certisent-events-' . $range . 'd-' . gmdate('Ymd') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Time (UTC)', 'Severity', 'Type', 'Monitor URL', 'Message', 'Meta JSON']);

    foreach ($events as $e) {
        fputcsv($out, [
            $e['created_at'],
            $e['severity'],
            $e['type'],
            $e['monitor_url'] ?? '',
            $e['message'],
            $e['meta_json'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// ── Export landing page ──
render_header('Export Data', $user);

echo render_breadcrumbs([
    ['label' => 'Dashboard', 'href' => url_for('dashboard.php')],
    ['label' => 'Reports', 'href' => url_for('reports/index.php')],
    ['label' => 'Export'],
]);
?>

<div class="page-header">
  <div>
    <div class="page-title">Export Data</div>
    <div class="page-subtitle">Download your certificate monitoring data as CSV</div>
  </div>
</div>

<!-- Report Nav Tabs -->
<div class="tabs">
  <a class="tab-link" href="<?php echo h(url_for('reports/index.php')); ?>">Overview</a>
  <a class="tab-link" href="<?php echo h(url_for('reports/expiry.php')); ?>">Expiry Timeline</a>
  <a class="tab-link" href="<?php echo h(url_for('reports/changes.php')); ?>">Changes & Events</a>
  <a class="tab-link active" href="<?php echo h(url_for('reports/export.php')); ?>">Export</a>
</div>

<div class="grid-3">
  <div class="card card-accent">
    <div class="card-body">
      <h3 class="section-title">Monitors</h3>
      <p class="text-sm text-sub mb-4">Export all your monitored certificates with their current status, days remaining, issuer, and fingerprint data.</p>
      <a class="btn btn-primary btn-sm" href="?type=monitors">Download CSV</a>
    </div>
  </div>

  <div class="card card-accent">
    <div class="card-body">
      <h3 class="section-title">Expiry Report</h3>
      <p class="text-sm text-sub mb-4">Export certificates sorted by expiration date. Useful for planning renewals and compliance reporting.</p>
      <a class="btn btn-primary btn-sm" href="?type=expiry">Download CSV</a>
    </div>
  </div>

  <div class="card card-accent">
    <div class="card-body">
      <h3 class="section-title">Events</h3>
      <p class="text-sm text-sub mb-4">Export certificate events (changes, renewals, warnings, errors) for audit and analysis.</p>
      <div class="flex gap-2">
        <a class="btn btn-secondary btn-xs" href="?type=events&range=7">7 days</a>
        <a class="btn btn-primary btn-xs" href="?type=events&range=30">30 days</a>
        <a class="btn btn-secondary btn-xs" href="?type=events&range=90">90 days</a>
      </div>
    </div>
  </div>
</div>

<?php render_footer(); ?>
