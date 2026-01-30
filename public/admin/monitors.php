<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/ui.php';

$user = require_login();
require_role($user, 'admin');

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

render_header('Admin · Monitors', $user);
?>

<div class="flex items-start justify-between mb-4">
  <div>
    <div class="text-lg font-semibold">Monitors</div>
    <div class="text-sm text-gray-400">Inventory (latest snapshot per monitor). Max 500 rows.</div>
  </div>
  <div class="text-sm">
    <a class="text-green-400 hover:underline" href="users.php">Users</a>
    <span class="text-gray-600 mx-2">·</span>
    <a class="text-green-400 hover:underline" href="audit.php">Audit</a>
  </div>
</div>

<div class="bg-white text-black rounded-2xl p-4 shadow mb-4">
  <form method="get" class="flex flex-wrap gap-3 items-end">
    <div>
      <label class="block text-xs text-gray-600">Status filter</label>
      <select name="status" class="border rounded px-2 py-1">
        <option value="" <?php echo $statusFilter===''?'selected':''; ?>>All</option>
        <option value="ok" <?php echo $statusFilter==='ok'?'selected':''; ?>>ok</option>
        <option value="warn" <?php echo $statusFilter==='warn'?'selected':''; ?>>warn</option>
        <option value="critical" <?php echo $statusFilter==='critical'?'selected':''; ?>>critical</option>
        <option value="unknown" <?php echo $statusFilter==='unknown'?'selected':''; ?>>unknown</option>
      </select>
    </div>
    <button class="bg-green-700 text-white px-3 py-2 rounded">Apply</button>
  </form>
</div>

<div class="bg-white text-black rounded-2xl p-6 shadow overflow-x-auto">
  <table class="min-w-full text-sm">
    <thead>
      <tr class="text-left border-b">
        <th class="py-2 pr-3">ID</th>
        <th class="py-2 pr-3">Owner</th>
        <th class="py-2 pr-3">URL</th>
        <th class="py-2 pr-3">Enabled</th>
        <th class="py-2 pr-3">Freq (min)</th>
        <th class="py-2 pr-3">Warn (days)</th>
        <th class="py-2 pr-3">Status</th>
        <th class="py-2 pr-3">Valid to</th>
        <th class="py-2 pr-3">Days</th>
        <th class="py-2 pr-3">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <?php $displayStatus = !empty($r['fetched_at']) ? (string)($r['status'] ?? 'unknown') : 'not_checked'; ?>
        <tr class="border-b align-top">
          <td class="py-2 pr-3 font-mono text-xs"><?php echo (int)$r['id']; ?></td>
          <td class="py-2 pr-3 text-xs"><?php echo h((string)$r['owner_email']); ?></td>
          <td class="py-2 pr-3 font-mono text-xs break-all"><?php echo h((string)$r['url']); ?></td>
          <td class="py-2 pr-3"><?php echo ((int)$r['enabled']===1?'Yes':'No'); ?></td>
          <td class="py-2 pr-3"><?php echo (int)$r['check_frequency_minutes']; ?></td>
          <td class="py-2 pr-3"><?php echo (int)$r['notify_days_before_expiry']; ?></td>
          <td class="py-2 pr-3"><?php echo badge_status($displayStatus); ?></td>
          <td class="py-2 pr-3 font-mono text-xs"><?php echo h((string)($r['valid_to'] ?? '')); ?></td>
          <td class="py-2 pr-3"><?php echo h((string)($r['days_remaining'] ?? '')); ?></td>
          <td class="py-2 pr-3 whitespace-nowrap">
            <a class="text-green-700 hover:underline" href="../monitor_view.php?id=<?php echo (int)$r['id']; ?>">View</a>
            <span class="text-gray-400 mx-2">·</span>
            <a class="text-green-700 hover:underline" href="../monitor_edit.php?id=<?php echo (int)$r['id']; ?>">Edit</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php render_footer(); ?>
