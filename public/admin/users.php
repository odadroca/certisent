<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/ui.php';

$user = require_role('admin');

$users = db()->query("SELECT id,email,role,created_at,last_login_at FROM users ORDER BY id ASC")->fetchAll();

render_header(t('admin.users.page_title'), $user);
?>
<div class="card">
  <div class="card-body">
  <div class="page-header">
    <div>
      <h1 class="page-title"><?php echo h(t('admin.users.h1')); ?></h1>
      <div class="text-xs text-muted"><?php echo h(t('admin.users.help')); ?></div>
    </div>
    <div class="flex gap-3">
      <a href="../dashboard.php"><?php echo h(t('common.back')); ?></a>
      <a href="audit.php"><?php echo h(t('admin.users.audit_log')); ?></a>
      <a href="../events.php"><?php echo h(t('admin.users.all_events')); ?></a>
    </div>
  </div>

  <div class="table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th><?php echo h(t('admin.users.th_id')); ?></th>
        <th><?php echo h(t('admin.users.th_email')); ?></th>
        <th><?php echo h(t('admin.users.th_role')); ?></th>
        <th><?php echo h(t('admin.users.th_created')); ?></th>
        <th><?php echo h(t('admin.users.th_last_login')); ?></th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $urow): ?>
        <tr>
          <td><?php echo (int)$urow['id']; ?></td>
          <td><?php echo h($urow['email']); ?></td>
          <td><?php echo h($urow['role']); ?></td>
          <td class="font-mono text-xs"><?php echo h($urow['created_at']); ?></td>
          <td class="font-mono text-xs"><?php echo h((string)$urow['last_login_at']); ?></td>
          <td><a href="user_edit.php?id=<?php echo (int)$urow['id']; ?>"><?php echo h(t('common.edit')); ?></a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  </div>
</div>
<?php render_footer(); ?>
