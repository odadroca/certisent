<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/ui.php';

$user = require_role('admin');

$statusFilter = (string)($_GET['status'] ?? '');
$statusFilter = trim($statusFilter);
$allowed = ['', 'ok', 'warn', 'critical', 'unknown'];
if (!in_array($statusFilter, $allowed, true)) {
    $statusFilter = '';
}

// Latest snapshot per monitor
$sql = "
SELECT
  m.id, m.user_id, u.email as owner_email,
  m.url, m.enabled,
  ms.check_frequency_minutes, ms.notify_days_before_expiry,
  ms.notify_on_change, ms.notify_on_renewal,
  s.fetched_at, s.status, s.valid_to, s.days_remaining, s.fingerprint_sha256
FROM monitors m
JOIN users u ON u.id = m.user_id
JOIN monitor_settings ms ON ms.monitor_id = m.id
LEFT JOIN (
  SELECT cs.*
  FROM cert_snapshots cs
  JOIN (
    SELECT monitor_id, MAX(fetched_at) AS max_fetched
    FROM cert_snapshots
    GROUP BY monitor_id
  ) x ON x.monitor_id = cs.monitor_id AND x.max_fetched = cs.fetched_at
) s ON s.monitor_id = m.id
";
$params = [];
if ($statusFilter !== '') {
    if ($statusFilter === 'unknown') {
        $sql .= " WHERE (s.status IS NULL OR s.status = 'unknown') ";
    } else {
        $sql .= " WHERE s.status = :st ";
        $params[':st'] = $statusFilter;
    }
}
$sql .= " ORDER BY (s.days_remaining IS NULL) DESC, s.days_remaining ASC, m.id DESC LIMIT 500";

$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

render_header(t('admin.monitors.page_title'), $user);
?>

<div class="page-header">
  <div>
    <div class="page-title"><?php echo h(t('admin.monitors.h1')); ?></div>
    <div class="page-subtitle"><?php echo h(t('admin.monitors.help_inventory')); ?></div>
  </div>
  <div class="text-sm">
    <a href="users.php"><?php echo h(t('admin.monitors.link_users')); ?></a>
    <span style="color:var(--text-muted);margin:0 0.5rem">&middot;</span>
    <a href="audit.php"><?php echo h(t('admin.monitors.link_audit')); ?></a>
  </div>
</div>

<div class="card card-compact mb-4">
  <div class="card-body">
  <form method="get" class="flex flex-wrap gap-3 items-center">
    <div>
      <label class="form-label"><?php echo h(t('admin.monitors.label_status_filter')); ?></label>
      <select name="status" class="form-select form-inline">
        <option value="" <?php echo $statusFilter===''?'selected':''; ?>><?php echo h(t('admin.monitors.opt_all')); ?></option>
        <option value="ok" <?php echo $statusFilter==='ok'?'selected':''; ?>><?php echo h(t('admin.monitors.opt_ok')); ?></option>
        <option value="warn" <?php echo $statusFilter==='warn'?'selected':''; ?>><?php echo h(t('admin.monitors.opt_warn')); ?></option>
        <option value="critical" <?php echo $statusFilter==='critical'?'selected':''; ?>><?php echo h(t('admin.monitors.opt_critical')); ?></option>
        <option value="unknown" <?php echo $statusFilter==='unknown'?'selected':''; ?>><?php echo h(t('admin.monitors.opt_unknown')); ?></option>
      </select>
    </div>
    <button class="btn btn-primary btn-sm">Apply</button>
  </form>
  </div>
</div>

<div class="card">
  <div class="card-body">
  <div class="table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th><?php echo h(t('admin.monitors.th_id')); ?></th>
        <th><?php echo h(t('admin.monitors.th_owner')); ?></th>
        <th><?php echo h(t('admin.monitors.th_url')); ?></th>
        <th><?php echo h(t('admin.monitors.th_enabled')); ?></th>
        <th><?php echo h(t('admin.monitors.th_freq_min')); ?></th>
        <th><?php echo h(t('admin.monitors.th_warn_days')); ?></th>
        <th><?php echo h(t('admin.monitors.th_status')); ?></th>
        <th><?php echo h(t('admin.monitors.th_valid_to')); ?></th>
        <th><?php echo h(t('admin.monitors.th_days')); ?></th>
        <th><?php echo h(t('admin.monitors.th_actions')); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <?php $displayStatus = !empty($r['fetched_at']) ? (string)($r['status'] ?? 'unknown') : 'not_checked'; ?>
        <tr>
          <td class="font-mono text-xs"><?php echo (int)$r['id']; ?></td>
          <td class="text-xs"><?php echo h((string)$r['owner_email']); ?></td>
          <td class="font-mono text-xs text-break"><?php echo h((string)$r['url']); ?></td>
          <td><?php echo ((int)$r['enabled']===1?'Yes':'No'); ?></td>
          <td><?php echo (int)$r['check_frequency_minutes']; ?></td>
          <td><?php echo (int)$r['notify_days_before_expiry']; ?></td>
          <td><?php echo badge_status($displayStatus); ?></td>
          <td class="font-mono text-xs"><?php echo h((string)($r['valid_to'] ?? '')); ?></td>
          <td><?php echo h((string)($r['days_remaining'] ?? '')); ?></td>
          <td style="white-space:nowrap">
            <a href="../monitor_view.php?id=<?php echo (int)$r['id']; ?>"><?php echo h(t('common.view')); ?></a>
            <span style="color:var(--text-muted);margin:0 0.5rem">&middot;</span>
            <a href="../monitor_edit.php?id=<?php echo (int)$r['id']; ?>"><?php echo h(t('common.edit')); ?></a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  </div>
</div>

<?php render_footer(); ?>
