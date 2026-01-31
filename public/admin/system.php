<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/ui.php';

$user = require_role('admin');

// Registration controls (v0.5.3): admin can disable registrations post-setup (DB flag).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'registration_toggle') {
        $desired = (string)($_POST['desired'] ?? '');
        $prev = Worker::getSystemState('registrations_disabled') === '1' ? '1' : '0';
        $next = ($desired === 'disable') ? '1' : '0';
        Worker::setSystemState('registrations_disabled', $next);
        Audit::log((int)$user['id'], 'admin.registration.toggle', 'system', null, [
            'prev' => $prev,
            'next' => $next,
        ]);
        flash_set('success', ($next === '1') ? 'Registrations disabled.' : 'Registrations enabled.');
        header('Location: system.php');
        exit;
    }
}

$registrationMode = strtolower(trim((string)cfg('REGISTRATION_MODE', 'open')));
if (!in_array($registrationMode, ['open','invite','closed'], true)) { $registrationMode = 'open'; }
$registrationsDisabled = (Worker::getSystemState('registrations_disabled') === '1');
$setupAdminTokenSet = ((string)cfg('SETUP_ADMIN_TOKEN', '') !== '');
$adminEmailBind = trim((string)cfg('ADMIN_EMAIL', ''));


$now = time();
$lastCron = Worker::getSystemState('last_cron_run_at');
$lastCronOk = Worker::getSystemState('last_cron_ok');
$lastCronTs = $lastCron ? strtotime($lastCron . ' UTC') : false;
$ageSeconds = ($lastCronTs !== false) ? max(0, $now - $lastCronTs) : null;

$since24 = gmdate('Y-m-d H:i:s', $now - 24*3600);

// Latest worker_run system event
$stLastRun = db()->prepare("SELECT created_at, meta_json FROM events WHERE monitor_id IS NULL AND type='worker_run' ORDER BY created_at DESC LIMIT 1");
$stLastRun->execute();
$lastRun = $stLastRun->fetch();

// Outbox counts
$outboxCounts = ['pending'=>0,'sent'=>0,'failed'=>0];
$stOut = db()->query("SELECT status, COUNT(*) c FROM notification_outbox GROUP BY status");
foreach ($stOut->fetchAll() as $r) {
    $k = (string)($r['status'] ?? '');
    if (isset($outboxCounts[$k])) $outboxCounts[$k] = (int)$r['c'];
}

// Event counts by severity (last 24h)
$stCounts = db()->prepare("SELECT severity, COUNT(*) AS c FROM events WHERE created_at >= :since GROUP BY severity");
$stCounts->execute([':since' => $since24]);
$counts = ['info'=>0,'warn'=>0,'critical'=>0];
foreach ($stCounts->fetchAll() as $r) {
    $sev = (string)($r['severity'] ?? '');
    if (isset($counts[$sev])) $counts[$sev] = (int)$r['c'];
}

// Recent system events (monitor_id is NULL)
$stSys = db()->prepare("SELECT id, created_at, severity, type, message, meta_json FROM events WHERE monitor_id IS NULL ORDER BY created_at DESC LIMIT 50");
$stSys->execute();
$sysEvents = $stSys->fetchAll();

// Recent worker jobs
$stJobs = db()->prepare("SELECT j.*, u.email AS requested_by_email FROM worker_jobs j LEFT JOIN users u ON u.id=j.requested_by_user_id ORDER BY j.created_at DESC LIMIT 25");
$stJobs->execute();
$jobs = $stJobs->fetchAll();

render_header('Admin · System', $user);

$schemaVersion = Worker::getSystemState('schema_version') ?? '';
$appVersion = app_version();
$envFrom = env_loaded_from();
$schemaOk = ($schemaVersion === '' || $schemaVersion === $appVersion);

?>

<div class="flex items-start justify-between mb-4">
  <div>
    <div class="text-lg font-semibold">System</div>
    <div class="text-sm text-gray-400">Operator diagnostics (UTC)</div>
  </div>
  <div class="text-sm">
    <a class="text-green-400 hover:underline" href="monitors.php">Monitors</a>
    <span class="text-gray-600 mx-2">·</span>
    <a class="text-green-400 hover:underline" href="outbox.php">Outbox</a>
    <span class="text-gray-600 mx-2">·</span>
    <a class="text-green-400 hover:underline" href="email.php">Email</a>
    <span class="text-gray-600 mx-2">·</span>
    <a class="text-green-400 hover:underline" href="api_keys.php">API Keys</a>
    <span class="text-gray-600 mx-2">·</span>
    <a class="text-green-400 hover:underline" href="users.php">Users</a>
    <span class="text-gray-600 mx-2">·</span>
    <a class="text-green-400 hover:underline" href="audit.php">Audit</a>
  </div>
</div>

<div class="grid md:grid-cols-2 gap-4">
  <div class="bg-white text-black rounded-2xl p-6 shadow">
    <h2 class="font-semibold mb-3">Worker heartbeat</h2>

    <?php if (!$lastCron): ?>
      <div class="text-sm text-gray-700">No heartbeat recorded yet.</div>
    <?php else: ?>
      <div class="text-sm text-gray-700 space-y-2">
        <div><span class="text-gray-500">Last run:</span> <span class="font-mono text-xs"><?php echo h($lastCron); ?> UTC</span></div>
        <div><span class="text-gray-500">Last ok flag:</span> <?php echo h((string)($lastCronOk ?? '')); ?></div>
        <div>
          <span class="text-gray-500">Age:</span>
          <?php
            if ($ageSeconds === null) {
                echo '<span class="text-gray-700">unknown</span>';
            } else {
                $hours = (int)floor($ageSeconds / 3600);
                $mins = (int)floor(($ageSeconds % 3600) / 60);
                $ageStr = $hours . 'h ' . $mins . 'm';
                $stale = $ageSeconds > 12*3600;
                echo $stale
                  ? '<span class="font-semibold text-red-700">' . h($ageStr) . ' (stale)</span>'
                  : '<span class="font-semibold text-green-700">' . h($ageStr) . '</span>';
            }
          ?>
        </div>
      </div>

      <?php if ($ageSeconds !== null && $ageSeconds > 12*3600): ?>
        <div class="mt-4 p-3 rounded bg-red-100 text-red-800 text-sm">
          Heartbeat is older than 12h. If cron is configured, check cron logs and PHP runtime errors.
        </div>
      <?php endif; ?>
    <?php endif; ?>
  
</div>

  <div class="bg-white text-black rounded-2xl p-6 shadow">
    <h2 class="font-semibold mb-3">Registration</h2>
    <div class="text-sm text-gray-700 space-y-2">
      <div><span class="text-gray-500">REGISTRATION_MODE:</span> <span class="font-mono text-xs"><?php echo h($registrationMode); ?></span></div>
      <div><span class="text-gray-500">DB override disabled:</span> <?php echo $registrationsDisabled ? '<span class="font-semibold text-red-700">yes</span>' : '<span class="font-semibold text-green-700">no</span>'; ?></div>
      <div><span class="text-gray-500">SETUP_ADMIN_TOKEN set:</span> <?php echo $setupAdminTokenSet ? 'yes' : 'no'; ?></div>
      <div><span class="text-gray-500">ADMIN_EMAIL set:</span> <?php echo ($adminEmailBind !== '' ? 'yes' : 'no'); ?></div>
    </div>

    <div class="mt-4 flex gap-3">
      <?php if ($registrationsDisabled): ?>
        <form method="post" class="inline">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="registration_toggle" />
          <input type="hidden" name="desired" value="enable" />
          <button class="bg-green-700 text-white px-3 py-2 rounded" type="submit">Enable registrations</button>
        </form>
      <?php else: ?>
        <form method="post" class="inline" onsubmit="return confirm('Disable new registrations? Existing users can still sign in.');">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="registration_toggle" />
          <input type="hidden" name="desired" value="disable" />
          <button class="bg-black text-white px-3 py-2 rounded" type="submit">Disable registrations</button>
        </form>
      <?php endif; ?>
    </div>

    <div class="mt-3 text-xs text-gray-600">
      DB override only affects <span class="font-mono">/register.php</span>. If <span class="font-mono">REGISTRATION_MODE=closed</span>, registrations remain closed regardless.
    </div>
  </div>

</div>

  <div class="bg-white text-black rounded-2xl p-6 shadow">
    <h2 class="font-semibold mb-3">Last worker run</h2>
    <?php if (!$lastRun): ?>
      <div class="text-sm text-gray-700">No worker_run event recorded yet.</div>
    <?php else: ?>
      <div class="text-sm text-gray-700">
        <div><span class="text-gray-500">Time:</span> <span class="font-mono text-xs"><?php echo h((string)$lastRun['created_at']); ?> UTC</span></div>
        <div class="mt-2 font-mono text-xs text-gray-700 break-all"><?php echo h(format_event_meta((string)($lastRun['meta_json'] ?? ''))); ?></div>
        <?php if (!empty($lastRun['meta_json'])): ?>
          <details class="mt-2">
            <summary class="cursor-pointer text-xs text-gray-600">raw</summary>
            <pre class="mt-2 p-2 bg-gray-100 rounded text-xs overflow-x-auto"><?php echo h((string)$lastRun['meta_json']); ?></pre>
          </details>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="bg-white text-black rounded-2xl p-6 shadow">
    <h2 class="font-semibold mb-3">Events (last 24h)</h2>
    <div class="text-sm text-gray-700 space-y-2">
      <div><span class="text-gray-500">Critical:</span> <?php echo (int)$counts['critical']; ?></div>
      <div><span class="text-gray-500">Warn:</span> <?php echo (int)$counts['warn']; ?></div>
      <div><span class="text-gray-500">Info:</span> <?php echo (int)$counts['info']; ?></div>
    </div>
    <div class="mt-4 text-sm">
      <a class="text-green-700 hover:underline" href="<?php echo h(url_for('history.php?severity=critical')); ?>">View critical events</a>
    </div>
  </div>

  <div class="bg-white text-black rounded-2xl p-6 shadow">
    <h2 class="font-semibold mb-3">Outbox</h2>
    <div class="text-sm text-gray-700 space-y-2">
      <div><span class="text-gray-500">Pending:</span> <?php echo (int)$outboxCounts['pending']; ?></div>
      <div><span class="text-gray-500">Failed:</span> <?php echo (int)$outboxCounts['failed']; ?></div>
      <div><span class="text-gray-500">Sent:</span> <?php echo (int)$outboxCounts['sent']; ?></div>
    </div>
    <div class="mt-4 text-sm">
      <a class="text-green-700 hover:underline" href="outbox.php">View outbox</a>
      <form method="post" action="outbox_run.php" class="mt-3">
        <?php echo csrf_field(); ?>
        <button class="bg-green-700 text-white px-3 py-2 rounded" type="submit">Run outbox now</button>
      </form>
    </div>
  </div>
</div>

<div class="mt-6 bg-white text-black rounded-2xl p-6 shadow overflow-x-auto">
  <h2 class="font-semibold mb-3">Worker jobs (25)</h2>
  <?php if (empty($jobs)): ?>
    <div class="text-sm text-gray-700">No jobs recorded.</div>
  <?php else: ?>
    <table class="min-w-full text-sm">
      <thead>
        <tr class="text-left border-b">
          <th class="py-2 pr-3">ID</th>
          <th class="py-2 pr-3">Type</th>
          <th class="py-2 pr-3">Status</th>
          <th class="py-2 pr-3">Processed</th>
          <th class="py-2 pr-3">Requested by</th>
          <th class="py-2 pr-3">Created</th>
          <th class="py-2 pr-3">Updated</th>
          <th class="py-2 pr-3"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($jobs as $j): ?>
          <tr class="border-b">
            <td class="py-2 pr-3 font-mono text-xs">#<?php echo (int)$j['id']; ?></td>
            <td class="py-2 pr-3 font-mono text-xs"><?php echo h((string)$j['type']); ?></td>
            <td class="py-2 pr-3"><?php echo h((string)$j['status']); ?></td>
            <td class="py-2 pr-3"><?php echo (int)$j['total_processed']; ?></td>
            <td class="py-2 pr-3 text-xs"><?php echo h((string)($j['requested_by_email'] ?? '—')); ?></td>
            <td class="py-2 pr-3 font-mono text-xs"><?php echo h((string)$j['created_at']); ?></td>
            <td class="py-2 pr-3 font-mono text-xs"><?php echo h((string)$j['updated_at']); ?></td>
            <td class="py-2 pr-3">
              <?php if (in_array((string)$j['status'], ['pending','running'], true)): ?>
                <form method="post" action="job_cancel.php" class="inline">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="job_id" value="<?php echo (int)$j['id']; ?>">
                  <button class="text-red-700 hover:underline" type="submit">Cancel</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="mt-6 bg-white text-black rounded-2xl p-6 shadow overflow-x-auto">
  <h2 class="font-semibold mb-3">System events (50)</h2>
  <?php if (!$sysEvents): ?>
    <div class="text-sm text-gray-700">No system events recorded.</div>
  <?php else: ?>
    <table class="min-w-full text-sm">
      <thead>
        <tr class="text-left border-b">
          <th class="py-2 pr-3">Time (UTC)</th>
          <th class="py-2 pr-3">Severity</th>
          <th class="py-2 pr-3">Type</th>
          <th class="py-2 pr-3">Message</th>
          <th class="py-2 pr-3">Meta</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($sysEvents as $e): ?>
          <tr class="border-b align-top">
            <td class="py-2 pr-3 font-mono text-xs"><?php echo h((string)$e['created_at']); ?></td>
            <td class="py-2 pr-3"><?php echo h((string)$e['severity']); ?></td>
            <td class="py-2 pr-3 font-mono text-xs"><?php echo h((string)$e['type']); ?></td>
            <td class="py-2 pr-3"><?php echo h((string)$e['message']); ?></td>
            <td class="py-2 pr-3 font-mono text-xs break-all text-gray-600"><?php echo h(format_event_meta((string)($e['meta_json'] ?? ''))); ?></td>
          </tr>
          <?php if (!empty($e['meta_json'])): ?>
            <tr class="border-b">
              <td></td><td></td><td></td><td></td>
              <td>
                <details>
                  <summary class="cursor-pointer text-xs text-gray-600">raw</summary>
                  <pre class="mt-2 p-2 bg-gray-100 rounded text-xs overflow-x-auto"><?php echo h((string)$e['meta_json']); ?></pre>
                </details>
              </td>
            </tr>
          <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
