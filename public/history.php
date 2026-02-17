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

render_header(t('page.history.title'), $user);
?>

<div class="card">
  <div class="card-body">
    <div class="flex-between items-start mb-4">
      <div>
        <h1 class="page-title"><?php echo t('page.history.title'); ?></h1>
        <div class="text-xs text-muted"><?php echo t('history.last_n', ['n' => '200']); ?><?php echo ($user['role']==='admin'||$user['role']==='auditor') ? t('history.scope_global') : t('history.scope_yours'); ?>.</div>
      </div>
      <form method="get" class="flex flex-wrap gap-2 items-end">
        <div>
          <label class="form-help"><?php echo t('common.severity'); ?></label>
          <select name="severity" class="form-select form-inline">
            <option value="" <?php echo $severity===''?'selected':''; ?>><?php echo t('common.all'); ?></option>
            <option value="info" <?php echo $severity==='info'?'selected':''; ?>>info</option>
            <option value="warn" <?php echo $severity==='warn'?'selected':''; ?>>warn</option>
            <option value="critical" <?php echo $severity==='critical'?'selected':''; ?>>critical</option>
          </select>
        </div>
        <div>
          <label class="form-help"><?php echo t('common.type'); ?></label>
          <input name="type" value="<?php echo h($type); ?>" placeholder="<?php echo h(t('history.type_placeholder')); ?>" class="form-input form-inline form-input-sm" />
        </div>
        <button class="btn btn-secondary btn-sm"><?php echo t('common.filter'); ?></button>
        <a class="text-sm" href="history.php"><?php echo t('common.reset'); ?></a>
      </form>
    </div>

    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?php echo t('common.time_utc'); ?></th>
            <th><?php echo t('common.severity'); ?></th>
            <th><?php echo t('common.type'); ?></th>
            <th><?php echo t('common.target'); ?></th>
            <th><?php echo t('common.message'); ?></th>
            <th><?php echo t('common.meta'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $e): ?>
            <tr>
              <td class="font-mono text-xs"><?php echo h((string)$e['created_at']); ?></td>
              <td><?php echo h((string)$e['severity']); ?></td>
              <td class="font-mono text-xs"><?php echo h((string)$e['type']); ?></td>
              <td>
                <?php if (!empty($e['monitor_id'])): ?>
                  <a class="font-mono text-xs" href="monitor_view.php?id=<?php echo (int)$e['monitor_id']; ?>"><?php echo h((string)($e['monitor_url'] ?? '')); ?></a>
                <?php else: ?>
                  <span class="text-muted"><?php echo t('common.system'); ?></span>
                <?php endif; ?>
              </td>
              <td><?php echo h((string)$e['message']); ?></td>
              <td>
                <div class="font-mono text-xs text-break text-muted"><?php echo h(format_event_meta((string)($e['meta_json'] ?? ''))); ?></div>
                <?php if (!empty($e['meta_json'])): ?>
                  <details class="mt-1">
                    <summary class="text-xs"><?php echo t('common.raw'); ?></summary>
                    <pre class="code-block mt-2"><?php echo h((string)$e['meta_json']); ?></pre>
                  </details>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php render_footer(); ?>
