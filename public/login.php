<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ui.php';

$user = current_user();
if ($user) { header('Location: dashboard.php'); exit; }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // v0.5.7: coarse per-IP login throttling (defaults high).
    $rl = RateLimiter::checkLoginIp(client_ip());
    if (!$rl['allowed']) {
        $err = function_exists('t') ? t('auth.err.too_many_login_attempts') : 'Too many login attempts. Try again later.';
    }

    if (!$err) {
        $email = trim((string)($_POST['email'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');

    $st = db()->prepare("SELECT * FROM users WHERE email=:e");
    $st->execute([':e'=>$email]);
    $u = $st->fetch();

    if (!$u) {
        $err = function_exists('t') ? t('auth.err.invalid_credentials') : 'Invalid credentials.';
    } else {
        $lockedUntil = $u['locked_until'] ? strtotime($u['locked_until'].' UTC') : null;
        if ($lockedUntil && time() < $lockedUntil) {
            $err = function_exists('t') ? t('auth.err.account_locked') : 'Account locked. Try again later.';
        } elseif (!password_verify($pass, (string)$u['password_hash'])) {
            $fail = (int)$u['failed_login_count'] + 1;
            $locked = null;
            if ($fail >= 7) {
                $locked = gmdate('Y-m-d H:i:s', time() + 15*60);
                $fail = 0; // reset count after lock to simplify
            }
            $st2 = db()->prepare("UPDATE users SET failed_login_count=:f, locked_until=:l WHERE id=:id");
            $st2->execute([':f'=>$fail, ':l'=>$locked, ':id'=>$u['id']]);
            $err = function_exists('t') ? t('auth.err.invalid_credentials') : 'Invalid credentials.';
            Audit::log(null, 'user.login_failed', 'user', (int)$u['id'], []);
        } else {
            $st2 = db()->prepare("UPDATE users SET failed_login_count=0, locked_until=NULL, last_login_at=:t WHERE id=:id");
            $st2->execute([':t'=>db_now_utc(), ':id'=>$u['id']]);
            login_user($u);
            Audit::log((int)$u['id'], 'user.login', 'user', (int)$u['id'], []);
            header('Location: dashboard.php');
            exit;
        }
    }

    }
}

$title = function_exists('t') ? t('auth.sign_in') : 'Sign in';
render_header($title);
?>
<div class="auth-page">
  <div class="auth-card">
    <div class="card">
      <div class="card-body">
        <h1 class="page-title mb-4"><?php echo h(function_exists('t') ? t('auth.sign_in') : 'Sign in'); ?></h1>
        <?php if ($err): ?>
          <div class="form-error"><?php echo h($err); ?></div>
        <?php endif; ?>
        <form method="post" class="space-y-3">
          <?php echo csrf_field(); ?>
          <div class="form-group">
            <label class="form-label"><?php echo h(function_exists('t') ? t('auth.email') : 'Email'); ?></label>
            <input name="email" class="form-input" value="<?php echo h($_POST['email'] ?? ''); ?>" />
          </div>
          <div class="form-group">
            <label class="form-label"><?php echo h(function_exists('t') ? t('auth.password') : 'Password'); ?></label>
            <input type="password" name="password" class="form-input" />
          </div>
          <button class="btn btn-primary"><?php echo h(function_exists('t') ? t('auth.sign_in') : 'Sign in'); ?></button>
          <div class="text-sm text-sub"><?php echo h(function_exists('t') ? t('auth.no_account') : 'No account?'); ?> <a href="register.php"><?php echo h(function_exists('t') ? t('nav.register') : 'Register'); ?></a></div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php render_footer(); ?>
