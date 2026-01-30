<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ui.php';

$user = current_user();
if ($user) { header('Location: dashboard.php'); exit; }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = trim((string)($_POST['email'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Invalid email.';
    } elseif (strlen($pass) < 10) {
        $err = 'Password must be at least 10 characters.';
    } else {
        $pdo = db();
        // first user becomes admin
        $count = (int)$pdo->query("SELECT COUNT(*) AS c FROM users")->fetch()['c'];
        $role = ($count === 0) ? 'admin' : 'viewer';

        $st = $pdo->prepare("SELECT id FROM users WHERE email=:e");
        $st->execute([':e'=>$email]);
        if ($st->fetch()) {
            $err = 'Email already registered.';
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

render_header('Register');
?>
<div class="bg-white text-black rounded-2xl p-6 shadow max-w-lg">
  <h1 class="text-xl font-semibold mb-4">Register</h1>
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
      <div class="text-xs text-gray-600 mt-1">Min 10 chars. Use a unique password.</div>
    </div>
    <button class="bg-green-700 text-white px-4 py-2 rounded">Create account</button>
    <div class="text-sm text-gray-700">Already have an account? <a class="text-green-700 hover:underline" href="login.php">Sign in</a></div>
  </form>
</div>
<?php render_footer(); ?>
