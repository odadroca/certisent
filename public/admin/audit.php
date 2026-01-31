<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/ui.php';

$admin = require_role('admin');

$rows = db()->query("SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 200")->fetchAll();

render_header(t('admin.audit.page_title'), $admin);
?>
<div class="bg-white text-black rounded-2xl p-6 shadow">
  <div class="flex items-start justify-between mb-4">
    <div>
      <h1 class="text-xl font-semibold"><?php echo h(t('admin.audit.h1')); ?></h1>
      <div class="text-xs text-gray-600"><?php echo h(t('admin.audit.help')); ?></div>
    </div>
    <a class="text-green-700 hover:underline" href="users.php"><?php echo h(t('common.back')); ?></a>
  </div>

  <div class="overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead>
        <tr class="text-left border-b">
          <th class="py-2 pr-3"><?php echo h(t('admin.audit.th_time_utc')); ?></th>
          <th class="py-2 pr-3"><?php echo h(t('admin.audit.th_actor')); ?></th>
          <th class="py-2 pr-3"><?php echo h(t('admin.audit.th_action')); ?></th>
          <th class="py-2 pr-3"><?php echo h(t('admin.audit.th_entity')); ?></th>
          <th class="py-2 pr-3"><?php echo h(t('admin.audit.th_ip')); ?></th>
          <th class="py-2 pr-3"><?php echo h(t('admin.audit.th_meta')); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr class="border-b align-top">
            <td class="py-2 pr-3 font-mono text-xs"><?php echo h($r['created_at']); ?></td>
            <td class="py-2 pr-3"><?php echo h((string)$r['actor_user_id']); ?></td>
            <td class="py-2 pr-3 font-mono text-xs"><?php echo h($r['action']); ?></td>
            <td class="py-2 pr-3"><?php echo h($r['entity_type']); ?>#<?php echo h((string)$r['entity_id']); ?></td>
            <td class="py-2 pr-3 font-mono text-xs"><?php echo h($r['ip']); ?></td>
            <td class="py-2 pr-3 font-mono text-xs break-all"><?php echo h((string)$r['meta_json']); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php render_footer(); ?>
