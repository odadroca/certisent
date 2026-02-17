<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/ui.php';

$admin = require_role('admin');

$rows = db()->query("SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 200")->fetchAll();

render_header(t('admin.audit.page_title'), $admin);
?>
<div class="card">
  <div class="card-body">
  <div class="page-header">
    <div>
      <h1 class="page-title"><?php echo h(t('admin.audit.h1')); ?></h1>
      <div class="text-xs text-muted"><?php echo h(t('admin.audit.help')); ?></div>
    </div>
    <a href="users.php"><?php echo h(t('common.back')); ?></a>
  </div>

  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th><?php echo h(t('admin.audit.th_time_utc')); ?></th>
          <th><?php echo h(t('admin.audit.th_actor')); ?></th>
          <th><?php echo h(t('admin.audit.th_action')); ?></th>
          <th><?php echo h(t('admin.audit.th_entity')); ?></th>
          <th><?php echo h(t('admin.audit.th_ip')); ?></th>
          <th><?php echo h(t('admin.audit.th_meta')); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="font-mono text-xs"><?php echo h(ui_dt($r['created_at'])); ?></td>
            <td><?php echo h((string)$r['actor_user_id']); ?></td>
            <td class="font-mono text-xs"><?php echo h($r['action']); ?></td>
            <td><?php echo h($r['entity_type']); ?>#<?php echo h((string)$r['entity_id']); ?></td>
            <td class="font-mono text-xs"><?php echo h($r['ip']); ?></td>
            <td class="font-mono text-xs text-break"><?php echo h((string)$r['meta_json']); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  </div>
</div>
<?php render_footer(); ?>
