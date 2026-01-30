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

render_header('Admin · Outbox', $user);
?>

<div class="flex items-start justify-between mb-4">
  <div>
    <div class="text-lg font-semibold">Notification outbox</div>
    <div class="text-sm text-gray-400">Reliable delivery queue (last 300 rows). Counts: pending=<?php echo (int)$counts['pending']; ?>, sent=<?php echo (int)$counts['sent']; ?>, failed=<?php echo (int)$counts['failed']; ?>.</div>
  </div>
  <div class="text-sm">
    <a class="text-green-400 hover:underline" href="system.php">System</a>
    <span class="text-gray-600 mx-2">·</span>
    <a class="text-green-400 hover:underline" href="api_keys.php">API Keys</a>
  </div>
</div>

<div class="bg-white text-black rounded-2xl p-4 shadow mb-4">
  <form method="get" class="flex flex-wrap gap-3 items-end">
    <div>
      <label class="block text-xs text-gray-600">Status</label>
      <select name="status" class="border rounded px-2 py-1">
        <option value="" <?php echo $status===''?'selected':''; ?>>All</option>
        <option value="pending" <?php echo $status==='pending'?'selected':''; ?>>pending</option>
        <option value="sent" <?php echo $status==='sent'?'selected':''; ?>>sent</option>
        <option value="failed" <?php echo $status==='failed'?'selected':''; ?>>failed</option>
      </select>
    </div>
    <button class="bg-green-700 text-white px-3 py-2 rounded">Apply</button>
    <a class="text-green-700 hover:underline" href="outbox.php">Reset</a>
  </form>
</div>

<div class="bg-white text-black rounded-2xl p-6 shadow overflow-x-auto">
  <table class="min-w-full text-sm">
    <thead>
      <tr class="text-left border-b">
        <th class="py-2 pr-3">Created</th>
        <th class="py-2 pr-3">User</th>
        <th class="py-2 pr-3">Channel</th>
        <th class="py-2 pr-3">Status</th>
        <th class="py-2 pr-3">Attempts</th>
        <th class="py-2 pr-3">Next retry</th>
        <th class="py-2 pr-3">Event</th>
        <th class="py-2 pr-3">Target</th>
        <th class="py-2 pr-3">Last error</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="border-b align-top">
          <td class="py-2 pr-3 font-mono text-xs"><?php echo h((string)$r['created_at']); ?></td>
          <td class="py-2 pr-3 text-xs"><?php echo h((string)$r['user_email']); ?></td>
          <td class="py-2 pr-3 font-mono text-xs"><?php echo h((string)$r['channel']); ?></td>
          <td class="py-2 pr-3"><?php echo h((string)$r['status']); ?></td>
          <td class="py-2 pr-3"><?php echo (int)$r['attempts']; ?></td>
          <td class="py-2 pr-3 font-mono text-xs"><?php echo h((string)($r['next_retry_at'] ?? '')); ?></td>
          <td class="py-2 pr-3 font-mono text-xs"><?php echo h((string)($r['event_type'] ?? '')); ?><?php echo $r['event_severity'] ? ' ('.h((string)$r['event_severity']).')' : ''; ?></td>
          <td class="py-2 pr-3"><?php if (!empty($r['monitor_id'])): ?><a class="text-green-700 hover:underline font-mono text-xs" href="../monitor_view.php?id=<?php echo (int)$r['monitor_id']; ?>"><?php echo h((string)($r['monitor_url'] ?? '')); ?></a><?php else: ?><span class="text-gray-600">(system)</span><?php endif; ?></td>
          <td class="py-2 pr-3 font-mono text-xs break-all text-gray-700"><?php echo h((string)($r['last_error'] ?? '')); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php render_footer(); ?>
