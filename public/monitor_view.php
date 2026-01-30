<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ui.php';

$user = require_login();

$id = (int)($_GET['id'] ?? 0);
$m = MonitorService::getMonitorById($id);
if (!$m) { http_response_code(404); echo 'Not found.'; exit; }

// Authorization: owner OR admin/auditor
if ($user['role'] !== 'admin' && $user['role'] !== 'auditor' && (int)$m['user_id'] !== (int)$user['id']) {
    http_response_code(403); echo 'Forbidden.'; exit;
}

$latest = MonitorService::getLatestSnapshot($id);

$stS = db()->prepare('SELECT id,fetched_at,status,days_remaining,issuer_cn,subject_cn,serial,fingerprint_sha256,valid_from,valid_to,error,raw_pem FROM cert_snapshots WHERE monitor_id=:id ORDER BY fetched_at DESC LIMIT 20');
$stS->execute([':id'=>$id]);
$snapshots = $stS->fetchAll();

$stE = db()->prepare('SELECT id,created_at,severity,type,message,meta_json FROM events WHERE monitor_id=:id ORDER BY created_at DESC LIMIT 50');
$stE->execute([':id'=>$id]);
$events = $stE->fetchAll();

render_header('Monitor', $user);
?>

<div class="flex items-start justify-between mb-4">
  <div>
    <div class="text-lg font-semibold">Monitor</div>
    <div class="text-xs text-gray-400 font-mono break-all"><?php echo h((string)$m['url']); ?></div>
  </div>
  <div class="flex flex-wrap gap-3">
    <?php if (has_role($user,'viewer')): ?>
      <a class="text-green-400 hover:underline" href="monitor_edit.php?id=<?php echo (int)$m['id']; ?>">Edit</a>
    <?php endif; ?>
    <a class="text-green-400 hover:underline" href="events.php?monitor_id=<?php echo (int)$m['id']; ?>">Events</a>
    <a class="text-green-400 hover:underline" href="dashboard.php">Back</a>
  </div>
</div>

<div class="grid md:grid-cols-2 gap-4">
  <div class="bg-white text-black rounded-2xl p-6 shadow">
    <h2 class="font-semibold mb-3">Current settings</h2>
    <div class="text-sm text-gray-700 space-y-1">
      <div><span class="text-gray-500">Enabled:</span> <?php echo ((int)$m['enabled']===1 ? 'Yes' : 'No'); ?></div>
      <div><span class="text-gray-500">Check frequency:</span> <?php echo (int)$m['check_frequency_minutes']; ?> minutes</div>
      <div><span class="text-gray-500">Warn threshold:</span> <?php echo (int)$m['notify_days_before_expiry']; ?> days</div>
      <div><span class="text-gray-500">Notify on change:</span> <?php echo ((int)$m['notify_on_change']===1 ? 'Yes' : 'No'); ?></div>
      <div><span class="text-gray-500">Notify on renewal:</span> <?php echo ((int)$m['notify_on_renewal']===1 ? 'Yes' : 'No'); ?></div>
    </div>

    <?php if (has_role($user,'viewer')): ?>
      <form method="post" action="monitor_check.php" class="mt-4">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="id" value="<?php echo (int)$m['id']; ?>" />
        <button class="bg-green-700 text-white px-4 py-2 rounded">Check now (store)</button>
        <div class="text-xs text-gray-600 mt-2">Runs one TLS fetch now, stores a snapshot, and creates events/notifications.</div>
      </form>
    <?php endif; ?>
  </div>

  <div class="bg-white text-black rounded-2xl p-6 shadow">
    <h2 class="font-semibold mb-3">Latest certificate snapshot</h2>
    <?php if (!$latest): ?>
      <div class="text-sm text-gray-700">No snapshots yet. Run a check.</div>
    <?php else: ?>
      <div class="text-sm text-gray-700 space-y-1">
        <div><span class="text-gray-500">Status:</span> <?php echo badge_status((string)$latest['status']); ?></div>
        <div><span class="text-gray-500">Issuer CN:</span> <?php echo h((string)$latest['issuer_cn']); ?></div>
        <div><span class="text-gray-500">Subject CN:</span> <?php echo h((string)$latest['subject_cn']); ?></div>
        <div><span class="text-gray-500">Valid from:</span> <span class="font-mono text-xs"><?php echo h((string)$latest['valid_from']); ?> UTC</span></div>
        <div><span class="text-gray-500">Valid to:</span> <span class="font-mono text-xs"><?php echo h((string)$latest['valid_to']); ?> UTC</span></div>
        <div><span class="text-gray-500">Days remaining:</span> <?php echo h((string)$latest['days_remaining']); ?></div>
        <div><span class="text-gray-500">Fingerprint:</span> <span class="font-mono text-xs break-all"><?php echo h((string)$latest['fingerprint_sha256']); ?></span></div>
        <?php if (!empty($latest['error'])): ?>
          <div><span class="text-gray-500">Error:</span> <?php echo h((string)$latest['error']); ?></div>
        <?php endif; ?>
      </div>
      <div class="mt-3"><?php echo progress_bar((int)$latest['days_remaining'], (string)$latest['valid_from'], (string)$latest['valid_to']); ?></div>
      <details class="mt-4">
        <summary class="cursor-pointer text-sm text-green-700">Raw PEM</summary>
        <pre class="mt-2 p-3 bg-gray-100 rounded text-xs overflow-x-auto"><?php echo h((string)$latest['raw_pem']); ?></pre>
      </details>
    <?php endif; ?>
  </div>
</div>

<div class="mt-6 grid md:grid-cols-2 gap-4">
  <div class="bg-white text-black rounded-2xl p-6 shadow overflow-x-auto">
    <h2 class="font-semibold mb-3">Recent snapshots (20)</h2>
    <table class="min-w-full text-sm">
      <thead>
        <tr class="text-left border-b">
          <th class="py-2 pr-3">Time (UTC)</th>
          <th class="py-2 pr-3">Status</th>
          <th class="py-2 pr-3">Valid to</th>
          <th class="py-2 pr-3">Days</th>
          <th class="py-2 pr-3">Fingerprint</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($snapshots as $s): ?>
          <tr class="border-b align-top">
            <td class="py-2 pr-3 font-mono text-xs"><?php echo h((string)$s['fetched_at']); ?></td>
            <td class="py-2 pr-3"><?php echo badge_status((string)$s['status']); ?></td>
            <td class="py-2 pr-3 font-mono text-xs"><?php echo h((string)$s['valid_to']); ?></td>
            <td class="py-2 pr-3"><?php echo h((string)$s['days_remaining']); ?></td>
            <td class="py-2 pr-3 font-mono text-xs break-all"><?php echo h((string)$s['fingerprint_sha256']); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="bg-white text-black rounded-2xl p-6 shadow overflow-x-auto">
    <h2 class="font-semibold mb-3">Recent events (50)</h2>
    <table class="min-w-full text-sm">
      <thead>
        <tr class="text-left border-b">
          <th class="py-2 pr-3">Time (UTC)</th>
          <th class="py-2 pr-3">Severity</th>
          <th class="py-2 pr-3">Type</th>
          <th class="py-2 pr-3">Message</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($events as $e): ?>
          <tr class="border-b align-top">
            <td class="py-2 pr-3 font-mono text-xs"><?php echo h((string)$e['created_at']); ?></td>
            <td class="py-2 pr-3"><?php echo h((string)$e['severity']); ?></td>
            <td class="py-2 pr-3 font-mono text-xs"><?php echo h((string)$e['type']); ?></td>
            <td class="py-2 pr-3"><?php echo h((string)$e['message']); ?></td>
          </tr>
          <?php if (!empty($e['meta_json'])): ?>
            <tr class="border-b">
              <td></td><td></td><td></td>
              <td class="py-2 pr-3">
                <div class="font-mono text-xs break-all text-gray-600"><?php echo h(format_event_meta((string)$e['meta_json'])); ?></div>
                <details class="mt-1">
                  <summary class="cursor-pointer text-xs text-gray-500">raw</summary>
                  <pre class="mt-2 p-2 bg-gray-100 rounded text-xs overflow-x-auto"><?php echo h((string)$e['meta_json']); ?></pre>
                </details>
              </td>
            </tr>
          <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php render_footer(); ?>
