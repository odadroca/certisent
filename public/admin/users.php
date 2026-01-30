<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/ui.php';

$user = require_role('admin');

$users = db()->query("SELECT id,email,role,created_at,last_login_at FROM users ORDER BY id ASC")->fetchAll();

render_header('Admin · Users', $user);
?>
<div class="bg-white text-black rounded-2xl p-6 shadow">
  <div class="flex items-start justify-between mb-4">
    <div>
      <h1 class="text-xl font-semibold">Users</h1>
      <div class="text-xs text-gray-600">Manage roles. Admin can view global events and audit logs.</div>
    </div>
    <div class="flex gap-3">
      <a class="text-green-700 hover:underline" href="../dashboard.php">Back</a>
      <a class="text-green-700 hover:underline" href="audit.php">Audit log</a>
      <a class="text-green-700 hover:underline" href="../events.php">All events</a>
    </div>
  </div>

  <table class="min-w-full text-sm">
    <thead>
      <tr class="text-left border-b">
        <th class="py-2 pr-3">ID</th>
        <th class="py-2 pr-3">Email</th>
        <th class="py-2 pr-3">Role</th>
        <th class="py-2 pr-3">Created</th>
        <th class="py-2 pr-3">Last login</th>
        <th class="py-2 pr-3"></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $urow): ?>
        <tr class="border-b">
          <td class="py-2 pr-3"><?php echo (int)$urow['id']; ?></td>
          <td class="py-2 pr-3"><?php echo h($urow['email']); ?></td>
          <td class="py-2 pr-3"><?php echo h($urow['role']); ?></td>
          <td class="py-2 pr-3 font-mono text-xs"><?php echo h($urow['created_at']); ?></td>
          <td class="py-2 pr-3 font-mono text-xs"><?php echo h((string)$urow['last_login_at']); ?></td>
          <td class="py-2 pr-3"><a class="text-green-700 hover:underline" href="user_edit.php?id=<?php echo (int)$urow['id']; ?>">Edit</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php render_footer(); ?>
