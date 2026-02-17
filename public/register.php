<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ui.php';

$user = current_user();
if ($user) { header('Location: dashboard.php'); exit; }

$pdo = db();

// Registration mode / bootstrap hardening (v0.5.3)
$regMode = strtolower(trim((string)cfg('REGISTRATION_MODE', 'open')));
if (!in_array($regMode, ['open','invite','closed'], true)) { $regMode = 'open'; }
$regDisabled = (Worker::getSystemState('registrations_disabled') === '1');
$setupToken = (string)cfg('SETUP_ADMIN_TOKEN', '');
$adminEmailBind = trim((string)cfg('ADMIN_EMAIL', ''));

// Determine if this is the first user (used for admin bootstrap gating and optional setup token prompt).
$existingUsers = (int)$pdo->query("SELECT COUNT(*) AS c FROM users")->fetch()['c'];
$isFirstUser = ($existingUsers === 0);

// Effective closed state (admin UI override or env closed).
if ($regDisabled || $regMode === 'closed') {
    $title = function_exists('t') ? t('auth.register') : 'Register';
    render_header($title);
    echo '<div class="auth-page"><div class="auth-card"><div class="card"><div class="card-body">';
    echo '<h1 class="page-title mb-2">' . h(function_exists('t') ? t('reg.disabled_title') : 'Registration disabled') . '</h1>';
    echo '<div class="text-sm text-sub">' . h(function_exists('t') ? t('reg.disabled_body') : 'New account registration is currently disabled.') . '</div>';
    echo '<div class="mt-4 text-sm text-sub"><a href="login.php">' . h(function_exists('t') ? t('nav.sign_in') : 'Sign in') . '</a></div>';
    echo '</div></div></div></div>';
    render_footer();
    exit;
}


// Invite mode requires a configured token.
if ($regMode === 'invite' && $setupToken === '') {
    $title = function_exists('t') ? t('auth.register') : 'Register';
    render_header($title);
    echo '<div class="auth-page"><div class="auth-card"><div class="card"><div class="card-body">';
    echo '<h1 class="page-title mb-2">' . h(function_exists('t') ? t('reg.unavailable_title') : 'Registration unavailable') . '</h1>';
    echo '<div class="text-sm text-sub">' . h(function_exists('t') ? t('reg.unavailable_body') : 'REGISTRATION_MODE is set to invite, but SETUP_ADMIN_TOKEN is not configured.') . '</div>';
    echo '<div class="mt-4 text-sm text-sub"><a href="login.php">' . h(function_exists('t') ? t('nav.sign_in') : 'Sign in') . '</a></div>';
    echo '</div></div></div></div>';
    render_footer();
    exit;
}


$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = trim((string)($_POST['email'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = function_exists('t') ? t('reg.err.invalid_email') : 'Invalid email.';
    } elseif (strlen($pass) < 10) {
        $err = function_exists('t') ? t('reg.err.password_short') : 'Password must be at least 10 characters.';
    } else {
        // first user becomes admin (bootstrap rules may restrict claiming)
        $providedToken = trim((string)($_POST['setup_admin_token'] ?? ''));

        // Token requirements:
        // - REGISTRATION_MODE=invite: token required for any registration
        // - first user and SETUP_ADMIN_TOKEN set: token required to claim first admin
        if ($regMode === 'invite') {
            if (!hash_equals($setupToken, $providedToken)) {
                $err = function_exists('t') ? t('reg.err.invalid_registration_token') : 'Invalid registration token.';
            }
        }

        if ($err === '' && $isFirstUser) {
            if ($setupToken !== '' && !hash_equals($setupToken, $providedToken)) {
                $err = function_exists('t') ? t('reg.err.setup_token_required') : 'Setup token required to claim first admin.';
            }
            if ($adminEmailBind !== '' && strcasecmp($adminEmailBind, $email) !== 0) {
                $err = function_exists('t') ? t('reg.err.admin_email_restricted') : 'First admin is restricted to ADMIN_EMAIL.';
            }
        }

        if ($err !== '') {
            // Registration blocked by policy.
        } else {
            $role = ($isFirstUser ? 'admin' : 'viewer');

            $st = $pdo->prepare("SELECT id FROM users WHERE email=:e");
        $st->execute([':e'=>$email]);
        if ($st->fetch()) {
            $err = function_exists('t') ? t('reg.err.email_exists') : 'Email already registered.';
        } else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $channels = json_encode(['email'=>true, 'slack_webhook'=>null, 'teams_webhook'=>null], JSON_UNESCAPED_SLASHES);
            $rssToken = bin2hex(random_bytes(16));

            $st2 = $pdo->prepare("INSERT INTO users (email,password_hash,role,created_at,notify_channels_json,rss_token,failed_login_count,locked_until) VALUES (:e,:p,:r,:c,:ch,:rt,0,NULL)");
            $st2->execute([':e'=>$email, ':p'=>$hash, ':r'=>$role, ':c'=>db_now_utc(), ':ch'=>$channels, ':rt'=>$rssToken]);

            $uid = (int)$pdo->lastInsertId();
            Audit::log($uid, 'user.register', 'user', $uid, ['role'=>$role]);

            login_user(['id'=>$uid,'email'=>$email,'role'=>$role]);
            header('Location: dashboard.php');
            exit;
        }
        }
    }
}

$title = function_exists('t') ? t('auth.register') : 'Register';
render_header($title);
?>
<div class="auth-page">
  <div class="auth-card">
    <div class="card">
      <div class="card-body">
        <h1 class="page-title mb-4"><?php echo h(function_exists('t') ? t('auth.register') : 'Register'); ?></h1>
        <?php if ($err): ?>
          <div class="form-error"><?php echo h($err); ?></div>
        <?php endif; ?>
        <form method="post" class="space-y-3">
          <?php echo csrf_field(); ?>
          <div class="form-group">
            <label class="form-label"><?php echo h(function_exists('t') ? t('auth.email') : 'Email'); ?></label>
            <input name="email" class="form-input" value="<?php echo h($_POST['email'] ?? ''); ?>" />
          </div>
          <?php if ($regMode === 'invite' || ($isFirstUser && $setupToken !== '')): ?>
          <div class="form-group">
            <label class="form-label"><?php echo h(($regMode === 'invite') ? (function_exists('t') ? t('reg.token_label.registration') : 'Registration token') : (function_exists('t') ? t('reg.token_label.setup') : 'Setup token')); ?></label>
            <input name="setup_admin_token" class="form-input font-mono text-sm" value="<?php echo h($_POST['setup_admin_token'] ?? ''); ?>" />
            <div class="form-help">
              <?php echo h(($regMode === 'invite') ? (function_exists('t') ? t('reg.token_help.registration') : 'Required to register.') : (function_exists('t') ? t('reg.token_help.setup') : 'Required to claim the first admin.')); ?>
            </div>
          </div>
          <?php endif; ?>

          <div class="form-group">
            <label class="form-label"><?php echo h(function_exists('t') ? t('auth.password') : 'Password'); ?></label>
            <input type="password" name="password" class="form-input" />
            <div class="form-help"><?php echo h(function_exists('t') ? t('reg.password_help') : 'Min 10 chars. Use a unique password.'); ?></div>
          </div>
          <button class="btn btn-primary"><?php echo h(function_exists('t') ? t('reg.create_account') : 'Create account'); ?></button>
          <div class="text-sm text-sub"><?php echo h(function_exists('t') ? t('reg.already_have') : 'Already have an account?'); ?> <a href="login.php"><?php echo h(function_exists('t') ? t('nav.sign_in') : 'Sign in'); ?></a></div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php render_footer(); ?>
