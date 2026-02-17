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
<div class="card max-w-2xl">
  <div class="card-body">
  <div class="page-header">
    <div>
      <h1 class="page-title"><?php echo h(t('admin.user_edit.h1')); ?></h1>
      <div class="text-sm text-sub"><?php echo h($u['email']); ?></div>
    </div>
    <a href="users.php"><?php echo h(t('common.back')); ?></a>
  </div>

  <?php if ($err): ?>
    <div class="mb-3 alert alert-error"><?php echo h($err); ?></div>
  <?php endif; ?>

  <form method="post" class="space-y-3">
    <?php echo csrf_field(); ?>
    <div>
      <label class="form-label"><?php echo h(t('admin.user_edit.label_role')); ?></label>
      <select name="role" class="form-select">
        <?php foreach (['admin','viewer','auditor'] as $r): ?>
          <option value="<?php echo h($r); ?>" <?php echo (($u['role']===$r)?'selected':''); ?>><?php echo h($r); ?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-help"><?php echo h(t('admin.user_edit.help_roles')); ?></div>
    </div>
    <div>
      <label class="form-label"><?php echo h(t('admin.user_edit.label_notify_channels_json')); ?></label>
      <textarea name="channels_json" class="form-textarea font-mono text-xs" rows="6"><?php echo h($_POST['channels_json'] ?? (string)$u['notify_channels_json']); ?></textarea>
      <div class="form-help">Example: {"email":true,"slack_webhook":"https://hooks.slack.com/...","teams_webhook":null}</div>
    </div>
    <button class="btn btn-primary"><?php echo h(t('common.save')); ?></button>
  </form>
  </div>
</div>
<?php render_footer(); ?>
