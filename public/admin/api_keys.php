<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/ui.php';

$user = require_role('admin');

/**
 * v0.3: scoped API keys for worker calls.
 * Token is shown once on creation; only sha256 hash is stored.
 */

$createdToken = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') $name = 'worker';

        $scopes = (array)($_POST['scopes'] ?? []);
        $allowed = ['run_worker','check_monitor','read_health'];
        $scopes = array_values(array_unique(array_filter(array_map('strval', $scopes), function(string $s) use ($allowed) {
            return in_array($s, $allowed, true);
        })));
        if (!$scopes) $scopes = ['run_worker'];

        // Generate token: base64url
        $raw = random_bytes(24);
        $token = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $hash = hash('sha256', $token);

        $now = db_now_utc();
        $st = db()->prepare('INSERT INTO api_keys (name, token_hash_sha256, scopes_json, is_active, created_at, updated_at) VALUES (:n,:h,:s,1,:c,:u)');
        $st->execute([
            ':n' => $name,
            ':h' => $hash,
            ':s' => json_encode($scopes, JSON_UNESCAPED_SLASHES),
            ':c' => $now,
            ':u' => $now,
        ]);

        // Store token for one-time display after redirect (do NOT store in DB).
        $_SESSION['created_token_once'] = $token;
        Audit::log((int)$user['id'], 'api_key.create', 'api_key', (int)db()->lastInsertId(), ['name'=>$name,'scopes'=>$scopes]);
        flash_set('success', 'API key created. Copy the token now; it will not be shown again.');
    }

    if ($action === 'revoke') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            db()->prepare('UPDATE api_keys SET is_active=0, updated_at=:u WHERE id=:id')->execute([':u'=>db_now_utc(), ':id'=>$id]);
            Audit::log((int)$user['id'], 'api_key.revoke', 'api_key', $id, []);
            flash_set('success', 'API key revoked.');
        }
    }

    header('Location: api_keys.php');
    exit;
}

// One-time token display
if (isset($_SESSION['created_token_once']) && is_string($_SESSION['created_token_once'])) {
    $createdToken = $_SESSION['created_token_once'];
    unset($_SESSION['created_token_once']);
}

$keys = db()->query('SELECT id,name,scopes_json,is_active,created_at,last_used_at FROM api_keys ORDER BY id DESC')->fetchAll();

render_header('Admin · API Keys', $user);
?>

<div class="flex items-start justify-between mb-4">
  <div>
    <div class="text-lg font-semibold">API Keys</div>
    <div class="text-sm text-gray-400">Scoped Bearer tokens for worker/API calls.</div>
  </div>
  <div class="text-sm">
    <a class="text-green-400 hover:underline" href="system.php">System</a>
    <span class="text-gray-600 mx-2">·</span>
    <a class="text-green-400 hover:underline" href="monitors.php">Monitors</a>
  </div>
</div>

<?php
if ($createdToken !== null) {
    echo '<div class="bg-yellow-100 text-black rounded-2xl p-5 shadow mb-4">';
    echo '<div class="font-semibold mb-2">New token (copy now)</div>';
    echo '<div class="font-mono text-xs break-all">'.h($createdToken).'</div>';
    echo '<div class="text-xs text-gray-700 mt-2">Use as: Authorization: Bearer &lt;token&gt;</div>';
    echo '</div>';
}
?>

<div class="bg-white text-black rounded-2xl p-6 shadow mb-6">
  <h2 class="font-semibold mb-3">Create API key</h2>
  <form method="post" class="space-y-4">
    <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>" />
    <input type="hidden" name="action" value="create" />

    <div>
      <label class="block text-sm text-gray-700">Name</label>
      <input name="name" class="w-full border rounded px-3 py-2" placeholder="worker" />
    </div>

    <div>
      <div class="text-sm text-gray-700 mb-2">Scopes</div>
      <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="scopes[]" value="run_worker" checked /> run_worker</label>
      <label class="flex items-center gap-2 text-sm mt-1"><input type="checkbox" name="scopes[]" value="check_monitor" /> check_monitor</label>
      <div class="text-xs text-gray-600 mt-2">Recommended: run_worker only.</div>
    </div>

    <button class="bg-green-700 text-white px-4 py-2 rounded">Create</button>
  </form>
</div>

<div class="bg-white text-black rounded-2xl p-6 shadow overflow-x-auto">
  <h2 class="font-semibold mb-3">Existing keys</h2>
  <table class="min-w-full text-sm">
    <thead>
      <tr class="text-left border-b">
        <th class="py-2 pr-3">ID</th>
        <th class="py-2 pr-3">Name</th>
        <th class="py-2 pr-3">Scopes</th>
        <th class="py-2 pr-3">Active</th>
        <th class="py-2 pr-3">Created (UTC)</th>
        <th class="py-2 pr-3">Last used (UTC)</th>
        <th class="py-2 pr-3"></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($keys as $k): ?>
        <?php $sc = json_decode((string)($k['scopes_json'] ?? '[]'), true); if (!is_array($sc)) $sc = []; ?>
        <tr class="border-b align-top">
          <td class="py-2 pr-3 font-mono text-xs"><?php echo (int)$k['id']; ?></td>
          <td class="py-2 pr-3"><?php echo h((string)$k['name']); ?></td>
          <td class="py-2 pr-3 font-mono text-xs"><?php echo h(implode(',', array_map('strval',$sc))); ?></td>
          <td class="py-2 pr-3"><?php echo ((int)$k['is_active']===1) ? 'Yes' : 'No'; ?></td>
          <td class="py-2 pr-3 font-mono text-xs"><?php echo h((string)$k['created_at']); ?></td>
          <td class="py-2 pr-3 font-mono text-xs"><?php echo h((string)($k['last_used_at'] ?? '')); ?></td>
          <td class="py-2 pr-3 whitespace-nowrap">
            <?php if ((int)$k['is_active']===1): ?>
              <form method="post" class="inline" onsubmit="return confirm('Revoke this key?');">
                <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>" />
                <input type="hidden" name="action" value="revoke" />
                <input type="hidden" name="id" value="<?php echo (int)$k['id']; ?>" />
                <button class="text-red-700 hover:underline">Revoke</button>
              </form>
            <?php else: ?>
              <span class="text-gray-500">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="mt-4 text-xs text-gray-700">
    Legacy fallback: if <span class="font-mono">API_WORKER_KEY</span> is set in <span class="font-mono">.env</span>, it remains valid with wildcard scope for upgrade safety.
  </div>
</div>

<?php render_footer(); ?>
