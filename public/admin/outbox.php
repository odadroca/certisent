<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/ui.php';

$user = require_role('admin');

$status = trim((string)($_GET['status'] ?? ''));
$allowed = ['', 'pending', 'sent', 'failed'];
if (!in_array($status, $allowed, true)) $status = '';

$sql = "SELECT o.*, u.email AS user_email, m.url AS monitor_url, e.type AS event_type, e.severity AS event_severity, e.created_at AS event_created_at
        FROM notification_outbox o
        JOIN users u ON u.id=o.user_id
        LEFT JOIN monitors m ON m.id=o.monitor_id
        LEFT JOIN events e ON e.id=o.event_id";
$params = [];
if ($status !== '') {
    $sql .= " WHERE o.status = :st";
    $params[':st'] = $status;
}
$sql .= " ORDER BY o.created_at DESC LIMIT 300";

$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$counts = ['pending'=>0,'sent'=>0,'failed'=>0];
$st2 = db()->query("SELECT status, COUNT(*) c FROM notification_outbox GROUP BY status");
foreach ($st2->fetchAll() as $r) {
    $k = (string)($r['status'] ?? '');
    if (isset($counts[$k])) $counts[$k] = (int)$r['c'];
}

render_header(t('admin.outbox.page_title'), $user);
?>

<div class="page-header">
  <div>
    <div class="page-title"><?php echo h(t('admin.outbox.h1')); ?></div>
    <div class="page-subtitle"><?php echo h(t('admin.outbox.help_prefix')); ?> pending=<?php echo (int)$counts['pending']; ?>, sent=<?php echo (int)$counts['sent']; ?>, failed=<?php echo (int)$counts['failed']; ?>.</div>
  </div>
  <div class="text-sm flex items-center gap-3">
    <a href="system.php"><?php echo h(t('admin.outbox.link_system')); ?></a>
    <span style="color:var(--text-muted);margin:0 0.5rem">&middot;</span>
    <a href="api_keys.php"><?php echo h(t('admin.outbox.link_api_keys')); ?></a>
    <form method="post" action="outbox_run.php" class="inline ml-auto" onsubmit="return confirm('<?php echo h(t('admin.outbox.confirm_run')); ?>');">
      <?php echo csrf_field(); ?>
      <button class="btn btn-primary btn-sm" type="submit"><?php echo h(t('admin.outbox.btn_run_outbox_now')); ?></button>
    </form>
  </div>
</div>

<div class="card card-compact mb-4">
  <div class="card-body">
  <form method="get" class="flex flex-wrap gap-3 items-center">
    <div>
      <label class="form-label"><?php echo h(t('admin.outbox.label_status')); ?></label>
      <select name="status" class="form-select form-inline">
        <option value="" <?php echo $status===''?'selected':''; ?>><?php echo h(t('admin.outbox.opt_all')); ?></option>
        <option value="pending" <?php echo $status==='pending'?'selected':''; ?>><?php echo h(t('admin.outbox.opt_pending')); ?></option>
        <option value="sent" <?php echo $status==='sent'?'selected':''; ?>><?php echo h(t('admin.outbox.opt_sent')); ?></option>
        <option value="failed" <?php echo $status==='failed'?'selected':''; ?>><?php echo h(t('admin.outbox.opt_failed')); ?></option>
      </select>
    </div>
    <button class="btn btn-primary btn-sm">Apply</button>
    <a href="outbox.php">Reset</a>
  </form>
  </div>
</div>

<div class="card">
  <div class="card-body">
  <div class="table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th><?php echo h(t('admin.outbox.th_created')); ?></th>
        <th><?php echo h(t('admin.outbox.th_user')); ?></th>
        <th><?php echo h(t('admin.outbox.th_channel')); ?></th>
        <th><?php echo h(t('admin.outbox.th_status')); ?></th>
        <th><?php echo h(t('admin.outbox.th_attempts')); ?></th>
        <th><?php echo h(t('admin.outbox.th_next_retry')); ?></th>
        <th><?php echo h(t('admin.outbox.th_event')); ?></th>
        <th><?php echo h(t('admin.outbox.th_target')); ?></th>
        <th><?php echo h(t('admin.outbox.th_last_error')); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="font-mono text-xs"><?php echo h((string)$r['created_at']); ?></td>
          <td class="text-xs"><?php echo h((string)$r['user_email']); ?></td>
          <td class="font-mono text-xs"><?php echo h((string)$r['channel']); ?></td>
          <td><?php echo h((string)$r['status']); ?></td>
          <td><?php echo (int)$r['attempts']; ?></td>
          <td class="font-mono text-xs"><?php echo h((string)($r['next_retry_at'] ?? '')); ?></td>
          <td class="font-mono text-xs"><?php echo h((string)($r['event_type'] ?? '')); ?><?php echo $r['event_severity'] ? ' ('.h((string)$r['event_severity']).')' : ''; ?></td>
          <td><?php if (!empty($r['monitor_id'])): ?><a class="font-mono text-xs" href="../monitor_view.php?id=<?php echo (int)$r['monitor_id']; ?>"><?php echo h((string)($r['monitor_url'] ?? '')); ?></a><?php else: ?><span class="text-muted">(system)</span><?php endif; ?></td>
          <td class="font-mono text-xs text-break text-sub"><?php echo h((string)($r['last_error'] ?? '')); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  </div>
</div>

<?php render_footer(); ?>
