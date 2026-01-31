<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/ui.php';

$admin = require_role('admin');

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare("SELECT id,email,role,notify_channels_json FROM users WHERE id=:id");
$st->execute([':id'=>$id]);
$u = $st->fetch();
if (!$u) { http_response_code(404); echo h(t('errors.not_found')); exit; }

$oldChannels = json_decode((string)($u['notify_channels_json'] ?? '{}'), true);
if (!is_array($oldChannels)) $oldChannels = [];
$oldSlackWebhook = (string)($oldChannels['slack_webhook'] ?? '');
$oldTeamsWebhook = (string)($oldChannels['teams_webhook'] ?? '');

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $role = (string)($_POST['role'] ?? 'viewer');
    if (!in_array($role, ['admin','viewer','auditor'], true)) $role = 'viewer';

    $channelsRaw = (string)($_POST['channels_json'] ?? '{}');
    $channels = json_decode($channelsRaw, true);
    if (!is_array($channels)) {
        $err = 'channels_json must be valid JSON.';
    } else {
        $st2 = db()->prepare("UPDATE users SET role=:r, notify_channels_json=:c WHERE id=:id");
        $st2->execute([':r'=>$role, ':c'=>json_encode($channels, JSON_UNESCAPED_SLASHES), ':id'=>$id]);

        // Audit webhook changes without logging URL values.
        $auditWebhook = function(string $channel, string $old, string $new) use ($admin, $id): void {
            $old = trim((string)$old);
            $new = trim((string)$new);
            if ($old === $new) return;

            $class = ($old === '' && $new !== '') ? 'set' : (($old !== '' && $new === '') ? 'cleared' : 'changed');
            $oldHost = $old !== '' ? ((string)(parse_url($old, PHP_URL_HOST) ?? '')) : '';
            $newHost = $new !== '' ? ((string)(parse_url($new, PHP_URL_HOST) ?? '')) : '';
            Audit::log((int)$admin['id'], 'user.webhook.update', 'user', $id, [
                'channel' => $channel,
                'change' => $class,
                'old_set' => ($old !== '' ? 1 : 0),
                'new_set' => ($new !== '' ? 1 : 0),
                'old_host' => $oldHost,
                'new_host' => $newHost,
                'old_hash' => ($old !== '' ? hash('sha256', $old) : ''),
                'new_hash' => ($new !== '' ? hash('sha256', $new) : ''),
            ]);
        };

        $newSlackWebhook = (string)($channels['slack_webhook'] ?? '');
        $newTeamsWebhook = (string)($channels['teams_webhook'] ?? '');
        $auditWebhook('slack', $oldSlackWebhook, $newSlackWebhook);
        $auditWebhook('teams', $oldTeamsWebhook, $newTeamsWebhook);

        Audit::log((int)$admin['id'], 'user.update', 'user', $id, ['role'=>$role]);
        header('Location: users.php');
        exit;
    }
}

render_header(t('admin.user_edit.page_title'), $admin);
?>
<div class="bg-white text-black rounded-2xl p-6 shadow max-w-2xl">
  <div class="flex items-start justify-between mb-4">
    <div>
      <h1 class="text-xl font-semibold"><?php echo h(t('admin.user_edit.h1')); ?></h1>
      <div class="text-sm text-gray-700"><?php echo h($u['email']); ?></div>
    </div>
    <a class="text-green-700 hover:underline" href="users.php"><?php echo h(t('common.back')); ?></a>
  </div>

  <?php if ($err): ?>
    <div class="mb-3 p-3 rounded bg-red-100 text-red-800 text-sm"><?php echo h($err); ?></div>
  <?php endif; ?>

  <form method="post" class="space-y-3">
    <?php echo csrf_field(); ?>
    <div>
      <label class="text-sm"><?php echo h(t('admin.user_edit.label_role')); ?></label>
      <select name="role" class="w-full border rounded px-3 py-2">
        <?php foreach (['admin','viewer','auditor'] as $r): ?>
          <option value="<?php echo h($r); ?>" <?php echo (($u['role']===$r)?'selected':''); ?>><?php echo h($r); ?></option>
        <?php endforeach; ?>
      </select>
      <div class="text-xs text-gray-600 mt-1"><?php echo h(t('admin.user_edit.help_roles')); ?></div>
    </div>
    <div>
      <label class="text-sm"><?php echo h(t('admin.user_edit.label_notify_channels_json')); ?></label>
      <textarea name="channels_json" class="w-full border rounded px-3 py-2 font-mono text-xs" rows="6"><?php echo h($_POST['channels_json'] ?? (string)$u['notify_channels_json']); ?></textarea>
      <div class="text-xs text-gray-600 mt-1">Example: {"email":true,"slack_webhook":"https://hooks.slack.com/...","teams_webhook":null}</div>
    </div>
    <button class="bg-green-700 text-white px-4 py-2 rounded"><?php echo h(t('common.save')); ?></button>
  </form>
</div>
<?php render_footer(); ?>
