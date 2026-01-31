<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/ui.php';

$user = require_role('admin');

$transport = (string)cfg('MAIL_TRANSPORT', 'mail');
$from = (string)cfg('MAIL_FROM', '');
$fromName = (string)cfg('MAIL_FROM_NAME', '');
$adminEmail = (string)cfg('ADMIN_EMAIL', '');

$smtpHost = (string)cfg('SMTP_HOST', '');
$smtpPort = (int)cfg('SMTP_PORT', 587);
$smtpUser = (string)cfg('SMTP_USER', '');
$smtpEnc = (string)cfg('SMTP_ENCRYPTION', 'starttls');

$apiUrl = (string)cfg('MAIL_API_URL', '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $to = trim((string)($_POST['to'] ?? ''));
    if ($to === '') $to = $adminEmail;
    if ($to === '') {
        flash_set_key('error', 'admin.email.err.no_recipient');
    } else {
        $res = Emailer::sendText($to, '[Certinel] Test email', "This is a test email sent by Certinel v".app_version()." at ".db_now_utc()." UTC\n");
        if (($res['ok'] ?? false) === true) {
            flash_set_key('success', 'admin.email.ok.sent', ['to' => $to]);
            Audit::log((int)$user['id'], 'email.test_sent', 'system', null, ['to'=>$to,'transport'=>$transport]);
        } else {
            flash_set_key('error', 'admin.email.err.send_failed', ['error' => (string)($res['error'] ?? 'unknown_error')]);
            Audit::log((int)$user['id'], 'email.test_failed', 'system', null, ['to'=>$to,'transport'=>$transport,'error'=>$res['error'] ?? null]);
        }
    }
    header('Location: '.url_for('admin/email.php'));
    exit;
}

render_header(t('admin.email.page_title'), $user);
?>

<div class="flex items-start justify-between mb-4">
  <div>
    <div class="text-lg font-semibold">Email</div>
    <div class="text-sm text-gray-400">Outbound configuration (read-only) and test send</div>
  </div>
  <div class="text-sm">
    <a class="accent hover:underline" href="system.php">System</a>
    <span class="text-gray-600 mx-2">·</span>
    <a class="accent hover:underline" href="outbox.php">Outbox</a>
  </div>
</div>

<div class="grid md:grid-cols-2 gap-4">
  <div class="bg-white text-black rounded-2xl p-6 shadow">
    <h2 class="font-semibold mb-3">Active transport</h2>
    <div class="text-sm text-gray-700 space-y-2">
      <div><span class="text-gray-500">MAIL_TRANSPORT:</span> <span class="font-mono text-xs"><?php echo h($transport); ?></span></div>
      <div><span class="text-gray-500">MAIL_FROM:</span> <span class="font-mono text-xs"><?php echo h($from); ?></span></div>
      <div><span class="text-gray-500">MAIL_FROM_NAME:</span> <span class="font-mono text-xs"><?php echo h($fromName); ?></span></div>
      <div><span class="text-gray-500">ADMIN_EMAIL:</span> <span class="font-mono text-xs"><?php echo h($adminEmail); ?></span></div>
    </div>
    <div class="mt-4 text-xs text-gray-600">
      Secrets are read from <span class="font-mono">.env</span> and are not displayed.
    </div>
  </div>

  <div class="bg-white text-black rounded-2xl p-6 shadow">
    <h2 class="font-semibold mb-3"><?php echo h(t('admin.email.h2_smtp_api_details')); ?></h2>
    <?php if (strtolower($transport) === 'smtp'): ?>
      <div class="text-sm text-gray-700 space-y-2">
        <div><span class="text-gray-500">SMTP_HOST:</span> <span class="font-mono text-xs"><?php echo h($smtpHost); ?></span></div>
        <div><span class="text-gray-500">SMTP_PORT:</span> <?php echo (int)$smtpPort; ?></div>
        <div><span class="text-gray-500">SMTP_USER:</span> <span class="font-mono text-xs"><?php echo h($smtpUser); ?></span></div>
        <div><span class="text-gray-500">SMTP_ENCRYPTION:</span> <span class="font-mono text-xs"><?php echo h($smtpEnc); ?></span></div>
      </div>
    <?php elseif (strtolower($transport) === 'api'): ?>
      <div class="text-sm text-gray-700 space-y-2">
        <div><span class="text-gray-500">MAIL_API_URL:</span> <span class="font-mono text-xs break-all"><?php echo h($apiUrl); ?></span></div>
        <div class="text-xs text-gray-600">Expected: JSON POST to MAIL_API_URL with optional Authorization header.</div>
      </div>
    <?php else: ?>
      <div class="text-sm text-gray-700">Using PHP <span class="font-mono">mail()</span> function (shared-hosting default).</div>
    <?php endif; ?>
  </div>
</div>

<div class="mt-6 bg-white text-black rounded-2xl p-6 shadow">
  <h2 class="font-semibold mb-3"><?php echo h(t('admin.email.h2_send_test_email')); ?></h2>
  <form method="post" class="space-y-3">
    <?php echo csrf_field(); ?>
    <div>
      <label class="block text-sm text-gray-700 mb-1"><?php echo h(t('admin.email.label_recipient_optional')); ?></label>
      <input name="to" class="w-full border rounded px-3 py-2" placeholder="<?php echo h(t('admin.email.placeholder_recipient')); ?>" />
    </div>
    <button class="bg-green-700 text-white px-4 py-2 rounded" type="submit"><?php echo h(t('admin.email.btn_send_test')); ?></button>
  </form>
</div>

<?php render_footer(); ?>
