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
        $res = Emailer::sendText($to, '[Certisent] Test email', "This is a test email sent by Certisent v".app_version()." at ".db_now_utc()." UTC\n");
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

<div class="page-header">
  <div>
    <div class="page-title">Email</div>
    <div class="page-subtitle">Outbound configuration (read-only) and test send</div>
  </div>
  <div class="text-sm">
    <a href="system.php">System</a>
    <span style="color:var(--text-muted);margin:0 0.5rem">&middot;</span>
    <a href="outbox.php">Outbox</a>
  </div>
</div>

<div class="grid-2">
  <div class="card">
    <div class="card-body">
    <h2 class="section-title">Active transport</h2>
    <div class="text-sm text-sub space-y-2">
      <div><span class="detail-label">MAIL_TRANSPORT:</span> <span class="font-mono text-xs"><?php echo h($transport); ?></span></div>
      <div><span class="detail-label">MAIL_FROM:</span> <span class="font-mono text-xs"><?php echo h($from); ?></span></div>
      <div><span class="detail-label">MAIL_FROM_NAME:</span> <span class="font-mono text-xs"><?php echo h($fromName); ?></span></div>
      <div><span class="detail-label">ADMIN_EMAIL:</span> <span class="font-mono text-xs"><?php echo h($adminEmail); ?></span></div>
    </div>
    <div class="mt-4 text-xs text-muted">
      Secrets are read from <span class="font-mono">.env</span> and are not displayed.
    </div>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
    <h2 class="section-title"><?php echo h(t('admin.email.h2_smtp_api_details')); ?></h2>
    <?php if (strtolower($transport) === 'smtp'): ?>
      <div class="text-sm text-sub space-y-2">
        <div><span class="detail-label">SMTP_HOST:</span> <span class="font-mono text-xs"><?php echo h($smtpHost); ?></span></div>
        <div><span class="detail-label">SMTP_PORT:</span> <?php echo (int)$smtpPort; ?></div>
        <div><span class="detail-label">SMTP_USER:</span> <span class="font-mono text-xs"><?php echo h($smtpUser); ?></span></div>
        <div><span class="detail-label">SMTP_ENCRYPTION:</span> <span class="font-mono text-xs"><?php echo h($smtpEnc); ?></span></div>
      </div>
    <?php elseif (strtolower($transport) === 'api'): ?>
      <div class="text-sm text-sub space-y-2">
        <div><span class="detail-label">MAIL_API_URL:</span> <span class="font-mono text-xs text-break"><?php echo h($apiUrl); ?></span></div>
        <div class="text-xs text-muted">Expected: JSON POST to MAIL_API_URL with optional Authorization header.</div>
      </div>
    <?php else: ?>
      <div class="text-sm text-sub">Using PHP <span class="font-mono">mail()</span> function (shared-hosting default).</div>
    <?php endif; ?>
    </div>
  </div>
</div>

<div class="mt-6 card">
  <div class="card-body">
  <h2 class="section-title"><?php echo h(t('admin.email.h2_send_test_email')); ?></h2>
  <form method="post" class="space-y-3">
    <?php echo csrf_field(); ?>
    <div>
      <label class="form-label"><?php echo h(t('admin.email.label_recipient_optional')); ?></label>
      <input name="to" class="form-input" placeholder="<?php echo h(t('admin.email.placeholder_recipient')); ?>" />
    </div>
    <button class="btn btn-primary" type="submit"><?php echo h(t('admin.email.btn_send_test')); ?></button>
  </form>
  </div>
</div>

<?php render_footer(); ?>
