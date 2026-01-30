<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ui.php';

$user = require_login();

$severity = isset($_GET['severity']) ? (string)$_GET['severity'] : '';
$type = isset($_GET['type']) ? (string)$_GET['type'] : '';

$where = [];
$params = [];

if ($severity !== '') {
    if (!in_array($severity, ['info','warn','critical'], true)) {
        $severity = '';
    } else {
        $where[] = 'e.severity = :sev';
        $params[':sev'] = $severity;
    }
}

if ($type !== '') {
    if (!preg_match('/^[a-zA-Z0-9_\-\.]{1,64}$/', $type)) {
        $type = '';
    } else {
        $where[] = 'e.type = :type';
        $params[':type'] = $type;
    }
}

if ($user['role'] !== 'admin' && $user['role'] !== 'auditor') {
    $where[] = 'm.user_id = :uid';
    $params[':uid'] = (int)$user['id'];
}

$sql = "SELECT e.*, m.url AS monitor_url
        FROM events e
        LEFT JOIN monitors m ON m.id = e.monitor_id
        ";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY e.created_at DESC LIMIT 200';

$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

render_header('History', $user);
?>

<div class="bg-white text-black rounded-2xl p-6 shadow">
  <div class="flex items-start justify-between mb-4">
    <div>
      <h1 class="text-xl font-semibold">History</h1>
      <div class="text-xs text-gray-600">Last 200 events<?php echo ($user['role']==='admin'||$user['role']==='auditor') ? ' (global)' : ' (your monitors)'; ?>.</div>
    </div>
    <form method="get" class="flex flex-wrap gap-2 items-end">
      <div>
        <label class="text-xs text-gray-600">Severity</label>
        <select name="severity" class="border rounded px-2 py-1 text-sm">
          <option value="" <?php echo $severity===''?'selected':''; ?>>All</option>
          <option value="info" <?php echo $severity==='info'?'selected':''; ?>>info</option>
          <option value="warn" <?php echo $severity==='warn'?'selected':''; ?>>warn</option>
          <option value="critical" <?php echo $severity==='critical'?'selected':''; ?>>critical</option>
        </select>
      </div>
      <div>
        <label class="text-xs text-gray-600">Type</label>
        <input name="type" value="<?php echo h($type); ?>" placeholder="expiry_warning" class="border rounded px-2 py-1 text-sm" />
      </div>
      <button class="bg-black text-white px-3 py-2 rounded text-sm">Filter</button>
      <a class="text-green-700 hover:underline text-sm" href="history.php">Reset</a>
    </form>
  </div>

  <div class="overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead>
        <tr class="text-left border-b">
          <th class="py-2 pr-3">Time (UTC)</th>
          <th class="py-2 pr-3">Severity</th>
          <th class="py-2 pr-3">Type</th>
          <th class="py-2 pr-3">Target</th>
          <th class="py-2 pr-3">Message</th>
          <th class="py-2 pr-3">Meta</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $e): ?>
          <tr class="border-b align-top">
            <td class="py-2 pr-3 font-mono text-xs"><?php echo h((string)$e['created_at']); ?></td>
            <td class="py-2 pr-3"><?php echo h((string)$e['severity']); ?></td>
            <td class="py-2 pr-3 font-mono text-xs"><?php echo h((string)$e['type']); ?></td>
            <td class="py-2 pr-3">
              <?php if (!empty($e['monitor_id'])): ?>
                <a class="text-green-700 hover:underline font-mono text-xs" href="monitor_view.php?id=<?php echo (int)$e['monitor_id']; ?>"><?php echo h((string)($e['monitor_url'] ?? '')); ?></a>
              <?php else: ?>
                <span class="text-gray-600">(system)</span>
              <?php endif; ?>
            </td>
            <td class="py-2 pr-3"><?php echo h((string)$e['message']); ?></td>
            <td class="py-2 pr-3">
              <div class="font-mono text-xs break-all text-gray-600"><?php echo h(format_event_meta((string)($e['meta_json'] ?? ''))); ?></div>
              <?php if (!empty($e['meta_json'])): ?>
                <details class="mt-1">
                  <summary class="cursor-pointer text-xs text-gray-500">raw</summary>
                  <pre class="mt-2 p-2 bg-gray-100 rounded text-xs overflow-x-auto"><?php echo h((string)$e['meta_json']); ?></pre>
                </details>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php render_footer(); ?>
