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
        $err = 'Too many login attempts. Try again later.';
    }

    if (!$err) {
        $email = trim((string)($_POST['email'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');

    $st = db()->prepare("SELECT * FROM users WHERE email=:e");
    $st->execute([':e'=>$email]);
    $u = $st->fetch();

    if (!$u) {
        $err = 'Invalid credentials.';
    } else {
        $lockedUntil = $u['locked_until'] ? strtotime($u['locked_until'].' UTC') : null;
        if ($lockedUntil && time() < $lockedUntil) {
            $err = 'Account locked. Try again later.';
        } elseif (!password_verify($pass, (string)$u['password_hash'])) {
            $fail = (int)$u['failed_login_count'] + 1;
            $locked = null;
            if ($fail >= 7) {
                $locked = gmdate('Y-m-d H:i:s', time() + 15*60);
                $fail = 0; // reset count after lock to simplify
            }
            $st2 = db()->prepare("UPDATE users SET failed_login_count=:f, locked_until=:l WHERE id=:id");
            $st2->execute([':f'=>$fail, ':l'=>$locked, ':id'=>$u['id']]);
            $err = 'Invalid credentials.';
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

render_header('Sign in');
?>
<div class="bg-white text-black rounded-2xl p-6 shadow max-w-lg">
  <h1 class="text-xl font-semibold mb-4">Sign in</h1>
  <?php if ($err): ?>
    <div class="mb-3 p-3 rounded bg-red-100 text-red-800 text-sm"><?php echo h($err); ?></div>
  <?php endif; ?>
  <form method="post" class="space-y-3">
    <?php echo csrf_field(); ?>
    <div>
      <label class="text-sm">Email</label>
      <input name="email" class="w-full border rounded px-3 py-2" value="<?php echo h($_POST['email'] ?? ''); ?>" />
    </div>
    <div>
      <label class="text-sm">Password</label>
      <input type="password" name="password" class="w-full border rounded px-3 py-2" />
    </div>
    <button class="bg-green-700 text-white px-4 py-2 rounded">Sign in</button>
    <div class="text-sm text-gray-700">No account? <a class="text-green-700 hover:underline" href="register.php">Register</a></div>
  </form>
</div>
<?php render_footer(); ?>
