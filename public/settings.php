<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ui.php';

$user = require_login();

$st = db()->prepare('SELECT id,email,role,notify_channels_json,rss_token FROM users WHERE id=:id');
$st->execute([':id'=>(int)$user['id']]);
$u = $st->fetch();
if (!$u) { http_response_code(404); echo 'Not found.'; exit; }

$channels = json_decode($u['notify_channels_json'] ?? '{}', true);
if (!is_array($channels)) $channels = [];
$emailEnabled = (bool)($channels['email'] ?? true);
$slackWebhook = (string)($channels['slack_webhook'] ?? '');
$teamsWebhook = (string)($channels['teams_webhook'] ?? '');

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? 'save');

    if ($action === 'rotate_rss') {
        $newToken = bin2hex(random_bytes(16));
        $st2 = db()->prepare('UPDATE users SET rss_token=:t WHERE id=:id');
        $st2->execute([':t'=>$newToken, ':id'=>(int)$user['id']]);
        Audit::log((int)$user['id'], 'user.rss.rotate', 'user', (int)$user['id'], []);
        flash_set('success', 'RSS token rotated. Old feed URL is now invalid.');
        header('Location: settings.php');
        exit;
    }

    $emailEnabled = isset($_POST['email_enabled']);
    $slackWebhook = trim((string)($_POST['slack_webhook'] ?? ''));
    $teamsWebhook = trim((string)($_POST['teams_webhook'] ?? ''));

    // Basic validation: allow empty or plausible URL.
    foreach (['Slack'=>$slackWebhook, 'Teams'=>$teamsWebhook] as $name=>$val) {
        if ($val !== '' && !filter_var($val, FILTER_VALIDATE_URL)) {
            $err = $name.' webhook must be a valid URL (or empty).';
            break;
        }
    }

    if ($err === '') {
        $new = [
            'email' => $emailEnabled,
            'slack_webhook' => ($slackWebhook === '' ? null : $slackWebhook),
            'teams_webhook' => ($teamsWebhook === '' ? null : $teamsWebhook),
        ];
        $st3 = db()->prepare('UPDATE users SET notify_channels_json=:c WHERE id=:id');
        $st3->execute([
            ':c'=>json_encode($new, JSON_UNESCAPED_SLASHES),
            ':id'=>(int)$user['id'],
        ]);

        // Do NOT log webhook URL values.
        Audit::log((int)$user['id'], 'user.settings.update', 'user', (int)$user['id'], [
            'email' => $emailEnabled ? 1 : 0,
            'slack_webhook_set' => ($slackWebhook !== '' ? 1 : 0),
            'teams_webhook_set' => ($teamsWebhook !== '' ? 1 : 0),
        ]);

        flash_set('success', 'Settings saved.');
        header('Location: settings.php');
        exit;
    }
}

$rssToken = (string)$u['rss_token'];
$rssPath = 'rss.php?token=' . rawurlencode($rssToken);
$appUrl = (string)cfg('APP_URL', '');
$rssAbsolute = $appUrl ? app_url($rssPath) : '';

render_header('Settings', $user);
?>

<div class="bg-white text-black rounded-2xl p-6 shadow max-w-2xl">
  <div class="flex items-start justify-between mb-4">
    <div>
      <h1 class="text-xl font-semibold">Settings</h1>
      <div class="text-xs text-gray-600">Notification channels and RSS feed.</div>
    </div>
  </div>

  <?php if ($err): ?>
    <div class="mb-3 p-3 rounded bg-red-100 text-red-800 text-sm"><?php echo h($err); ?></div>
  <?php endif; ?>

  <form method="post" class="space-y-4">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="save" />

    <div class="border rounded-xl p-4">
      <div class="font-semibold mb-2">Email</div>
      <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" name="email_enabled" <?php echo $emailEnabled ? 'checked' : ''; ?> />
        Enable email notifications
      </label>
      <div class="text-xs text-gray-600 mt-1">Email is sent to your account email address.</div>
    </div>

    <div class="border rounded-xl p-4">
      <div class="font-semibold mb-2">Slack webhook</div>
      <input name="slack_webhook" class="w-full border rounded px-3 py-2 text-sm" placeholder="https://hooks.slack.com/services/..." value="<?php echo h($slackWebhook); ?>" />
      <div class="text-xs text-gray-600 mt-1">Optional. Best-effort delivery (no retries).</div>
    </div>

    <div class="border rounded-xl p-4">
      <div class="font-semibold mb-2">MS Teams webhook</div>
      <input name="teams_webhook" class="w-full border rounded px-3 py-2 text-sm" placeholder="https://..." value="<?php echo h($teamsWebhook); ?>" />
      <div class="text-xs text-gray-600 mt-1">Optional. Best-effort delivery (no retries).</div>
    </div>

    <button class="bg-green-700 text-white px-4 py-2 rounded">Save settings</button>
  </form>

  <div class="mt-8 border-t pt-6">
    <div class="flex items-start justify-between gap-4">
      <div>
        <div class="font-semibold">RSS feed</div>
        <div class="text-sm text-gray-700 mt-1">Use this feed in your aggregator for event notifications.</div>
        <div class="mt-3 text-xs text-gray-700">
          <div class="font-mono break-all"><?php echo h($rssPath); ?></div>
          <?php if ($rssAbsolute): ?>
            <div class="font-mono break-all mt-1"><?php echo h($rssAbsolute); ?></div>
          <?php else: ?>
            <div class="text-gray-500 mt-1">Set APP_URL in .env to show absolute URL.</div>
          <?php endif; ?>
        </div>
      </div>
      <form method="post" onsubmit="return confirm('Rotate RSS token? Old feed URL will stop working.');">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="rotate_rss" />
        <button class="bg-black text-white px-4 py-2 rounded">Rotate token</button>
      </form>
    </div>
  </div>

  <div class="mt-8 border-t pt-6">
    <div class="font-semibold">Worker API</div>
    <div class="text-xs text-gray-600 mt-1">External workers can call the API with Bearer API_WORKER_KEY.</div>
    <div class="mt-2 text-xs font-mono text-gray-800 bg-gray-100 rounded p-3 break-all">
      POST <?php echo h(url_for('api/v1/index.php')); ?>
    </div>
  </div>
</div>

<?php render_footer(); ?>
