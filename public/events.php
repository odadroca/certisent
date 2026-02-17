<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ui.php';

$user = require_login();

$monitorId = isset($_GET['monitor_id']) ? (int)$_GET['monitor_id'] : null;
$m = null;
if ($monitorId) {
    $m = MonitorService::getMonitorById($monitorId);
    if (!$m) { http_response_code(404); echo t('common.not_found'); exit; }
    if ($user['role'] !== 'admin' && $user['role'] !== 'auditor' && (int)$m['user_id'] !== (int)$user['id']) {
        http_response_code(403); echo t('common.forbidden'); exit;
    }
}

if ($monitorId) {
    $st = db()->prepare("SELECT * FROM events WHERE monitor_id=:mid ORDER BY created_at DESC LIMIT 200");
    $st->execute([':mid'=>$monitorId]);
    $events = $st->fetchAll();
} else {
    if ($user['role'] !== 'admin' && $user['role'] !== 'auditor') {
        http_response_code(403); echo t('common.forbidden'); exit;
    }
    $events = db()->query("SELECT * FROM events ORDER BY created_at DESC LIMIT 200")->fetchAll();
}

render_header(t('page.events.title'), $user);
?>
<div class="card">
  <div class="card-body">
    <div class="flex-between items-start mb-4">
      <div>
        <h1 class="page-title"><?php echo t('events.heading'); ?></h1>
        <?php if ($m): ?>
          <div class="text-sm text-sub font-mono mt-1"><?php echo h($m['url']); ?></div>
        <?php endif; ?>
      </div>
      <a href="dashboard.php"><?php echo t('common.back'); ?></a>
    </div>

    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?php echo t('common.time_utc'); ?></th>
            <th><?php echo t('common.severity'); ?></th>
            <th><?php echo t('common.type'); ?></th>
            <th><?php echo t('common.message'); ?></th>
            <th><?php echo t('common.meta'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($events as $e): ?>
            <tr>
              <td class="font-mono text-xs"><?php echo h(ui_dt($e['created_at'])); ?></td>
              <td><?php echo h($e['severity']); ?></td>
              <td class="font-mono text-xs"><?php echo h($e['type']); ?></td>
              <td><?php echo h($e['message']); ?></td>
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
