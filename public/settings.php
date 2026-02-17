<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ui.php';

$user = require_login();

$hasLocale = db_has_column('users', 'locale');
$hasRepeat = db_has_column('users', 'notify_repeat_count');
$select = 'SELECT id,email,role,notify_channels_json,rss_token' . ($hasLocale ? ',locale' : '') . ($hasRepeat ? ',notify_repeat_count' : '') . ' FROM users WHERE id=:id';
$st = db()->prepare($select);
$st->execute([':id'=>(int)$user['id']]);
$u = $st->fetch();
if (!$u) { http_response_code(404); echo 'Not found.'; exit; }

$channels = json_decode($u['notify_channels_json'] ?? '{}', true);
if (!is_array($channels)) $channels = [];
$emailEnabled = (bool)($channels['email'] ?? true);
$slackWebhook = (string)($channels['slack_webhook'] ?? '');
$teamsWebhook = (string)($channels['teams_webhook'] ?? '');

$locale = $hasLocale ? (string)($u['locale'] ?? 'en') : 'en';
$locale = function_exists('normalize_locale') ? normalize_locale($locale) : 'en';

$repeatCount = $hasRepeat ? (int)($u['notify_repeat_count'] ?? 1) : 1;
$repeatCount = max(1, min(5, $repeatCount));

$oldSlackWebhook = $slackWebhook;
$oldTeamsWebhook = $teamsWebhook;

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
    $postedLocale = trim((string)($_POST['locale'] ?? $locale));
    $postedLocale = function_exists('normalize_locale') ? normalize_locale($postedLocale) : 'en';
    if ($hasRepeat) {
        $postedRepeat = (string)($_POST['notify_repeat_count'] ?? (string)$repeatCount);
        $postedRepeat = trim($postedRepeat);
        if ($postedRepeat === '' || !ctype_digit($postedRepeat)) {
            $err = 'Notification repeats must be an integer (1-5).';
        } else {
            $repeatCount = (int)$postedRepeat;
            $repeatCount = max(1, min(5, $repeatCount));
        }
    } else {
        $repeatCount = 1;
    }

    // Basic validation: allow empty or plausible URL.
    if ($err === '') {
        foreach (['Slack'=>$slackWebhook, 'Teams'=>$teamsWebhook] as $name=>$val) {
        if ($val !== '' && !filter_var($val, FILTER_VALIDATE_URL)) {
            $err = $name.' webhook must be a valid URL (or empty).';
            break;
        }
        }
    }

    if ($err === '') {
        if ($hasLocale) {
            $stL = db()->prepare('UPDATE users SET locale=:l WHERE id=:id');
            $stL->execute([':l'=>$postedLocale, ':id'=>(int)$user['id']]);
            $_SESSION['locale'] = $postedLocale;
        }

        if ($hasRepeat) {
            $stR = db()->prepare('UPDATE users SET notify_repeat_count=:n WHERE id=:id');
            $stR->execute([':n'=>$repeatCount, ':id'=>(int)$user['id']]);
        }


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

        // Audit webhook URL changes without storing URL values.
        $auditWebhook = function(string $channel, string $old, string $new) use ($user): void {
            $old = trim($old);
            $new = trim($new);
            if ($old === $new) return;

            $class = ($old === '' && $new !== '') ? 'set' : (($old !== '' && $new === '') ? 'cleared' : 'changed');

            $oldHost = '';
            $newHost = '';
            if ($old !== '') {
                $p = @parse_url($old);
                if (is_array($p) && isset($p['host'])) $oldHost = (string)$p['host'];
            }
            if ($new !== '') {
                $p = @parse_url($new);
                if (is_array($p) && isset($p['host'])) $newHost = (string)$p['host'];
            }

            Audit::log((int)$user['id'], 'user.webhook.update', 'user', (int)$user['id'], [
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

        $auditWebhook('slack', $oldSlackWebhook, $slackWebhook);
        $auditWebhook('teams', $oldTeamsWebhook, $teamsWebhook);

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

<div class="card max-w-2xl">
  <div class="card-body">
    <div class="flex-between items-start mb-4">
      <div>
        <h1 class="page-title">Settings</h1>
        <div class="text-xs text-muted">Notification channels and RSS feed.</div>
      </div>
    </div>

    <?php if ($err): ?>
      <div class="form-error"><?php echo h($err); ?></div>
    <?php endif; ?>

    <form method="post" class="space-y-4">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="save" />

      <div class="form-section">
        <div class="form-section-title">Language</div>
        <select name="locale" class="form-select form-inline" <?php echo $hasLocale ? '' : 'disabled'; ?>>
          <option value="en" <?php echo ($locale === 'en') ? 'selected' : ''; ?>>English</option>
          <option value="pt" <?php echo ($locale === 'pt') ? 'selected' : ''; ?>>Português</option>
        </select>
        <?php if (!$hasLocale): ?>
          <div class="form-help mt-1">DB migration required (adds users.locale).</div>
        <?php endif; ?>
      </div>

      <div class="form-section">
        <div class="form-section-title">Email</div>
        <label class="form-checkbox">
          <input type="checkbox" name="email_enabled" <?php echo $emailEnabled ? 'checked' : ''; ?> />
          Enable email notifications
        </label>
        <div class="mt-3">
          <label class="form-help" style="display:block">Send each notification this many times (1-5)</label>
          <input type="number" min="1" max="5" name="notify_repeat_count" class="form-input form-narrow" value="<?php echo (int)$repeatCount; ?>" <?php echo $hasRepeat ? '' : 'disabled'; ?> />
          <?php if (!$hasRepeat): ?>
            <div class="form-help mt-1">DB migration required (adds users.notify_repeat_count).</div>
          <?php endif; ?>
        </div>
        <div class="form-help mt-1">Email is sent to your account email address.</div>
      </div>

      <div class="form-section">
        <div class="form-section-title">Slack webhook</div>
        <input name="slack_webhook" class="form-input" placeholder="https://hooks.slack.com/services/..." value="<?php echo h($slackWebhook); ?>" />
        <div class="form-help mt-1">Optional. Best-effort delivery (no retries).</div>
      </div>

      <div class="form-section">
        <div class="form-section-title">MS Teams webhook</div>
        <input name="teams_webhook" class="form-input" placeholder="https://..." value="<?php echo h($teamsWebhook); ?>" />
        <div class="form-help mt-1">Optional. Best-effort delivery (no retries).</div>
      </div>

      <button class="btn btn-primary">Save settings</button>
    </form>

    <div class="mt-8" style="border-top: 1px solid var(--border-light); padding-top: 1.5rem;">
      <div class="flex-between items-start gap-4">
        <div>
          <div class="font-semibold">RSS feed</div>
          <div class="text-sm text-sub mt-1">Use this feed in your aggregator for event notifications.</div>
          <div class="mt-3 text-xs text-sub">
            <div class="font-mono text-break"><?php echo h($rssPath); ?></div>
            <?php if ($rssAbsolute): ?>
              <div class="font-mono text-break mt-1"><?php echo h($rssAbsolute); ?></div>
            <?php else: ?>
              <div class="text-muted mt-1">Set APP_URL in .env to show absolute URL.</div>
            <?php endif; ?>
          </div>
        </div>
        <form method="post" onsubmit="return confirm('Rotate RSS token? Old feed URL will stop working.');">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="rotate_rss" />
          <button class="btn btn-secondary">Rotate token</button>
        </form>
      </div>
    </div>

    <div class="mt-8" style="border-top: 1px solid var(--border-light); padding-top: 1.5rem;">
      <div class="font-semibold">Worker API</div>
      <div class="form-help mt-1">External workers can call the API with Bearer API_WORKER_KEY.</div>
      <div class="code-block mt-2 text-break">
        POST <?php echo h(url_for('api/v1/index.php')); ?>
      </div>
    </div>
  </div>
</div>

<?php render_footer(); ?>
