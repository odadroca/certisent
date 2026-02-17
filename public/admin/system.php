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

// v0.5.7: rate limit diagnostics (best-effort; if migration not applied, section hides).
$rateLimitSummary = null;
$rateLimitRecentBlocks = [];
try {
    $rateLimitSummary = [
        'login_ip' => ['blocks'=>0,'last_block_at'=>null],
        'api_ip' => ['blocks'=>0,'last_block_at'=>null],
        'api_token' => ['blocks'=>0,'last_block_at'=>null],
    ];
    $q1 = db()->prepare("SELECT SUM(blocked_count) AS blocks, MAX(last_block_at) AS last_block_at FROM rate_limits WHERE `key` LIKE :p");
    foreach (['login_ip'=>'login_ip:%','api_ip'=>'api_ip:%','api_token'=>'api_token:%'] as $k => $p) {
        $q1->execute([':p'=>$p]);
        $r = $q1->fetch();
        $rateLimitSummary[$k] = [
            'blocks' => (int)($r['blocks'] ?? 0),
            'last_block_at' => ($r && !empty($r['last_block_at'])) ? (string)$r['last_block_at'] : null,
        ];
    }

    $stRL = db()->prepare("SELECT `key`, blocked_count, last_block_at, blocked_until FROM rate_limits WHERE blocked_count > 0 ORDER BY last_block_at DESC LIMIT 20");
    $stRL->execute();
    $rateLimitRecentBlocks = $stRL->fetchAll();
} catch (Throwable $e) {
    $rateLimitSummary = null;
    $rateLimitRecentBlocks = [];
}

render_header(t('admin.system.page_title'), $user);

$schemaVersion = Worker::getSystemState('schema_version') ?? '';
$appVersion = app_version();
$envFrom = env_loaded_from();
$schemaOk = ($schemaVersion === '' || $schemaVersion === $appVersion);

?>

<div class="page-header">
  <div>
    <div class="page-title">System</div>
    <div class="page-subtitle">Operator diagnostics (UTC)</div>
  </div>
  <div class="text-sm">
    <a href="monitors.php"><?php echo h(t('admin.system.link_monitors')); ?></a>
    <span style="color:var(--text-muted);margin:0 0.5rem">&middot;</span>
    <a href="outbox.php"><?php echo h(t('admin.system.link_outbox')); ?></a>
    <span style="color:var(--text-muted);margin:0 0.5rem">&middot;</span>
    <a href="email.php"><?php echo h(t('admin.system.link_email')); ?></a>
    <span style="color:var(--text-muted);margin:0 0.5rem">&middot;</span>
    <a href="api_keys.php"><?php echo h(t('admin.system.link_api_keys')); ?></a>
    <span style="color:var(--text-muted);margin:0 0.5rem">&middot;</span>
    <a href="users.php"><?php echo h(t('admin.system.link_users')); ?></a>
    <span style="color:var(--text-muted);margin:0 0.5rem">&middot;</span>
    <a href="audit.php"><?php echo h(t('admin.system.link_audit')); ?></a>
  </div>
</div>

<div class="grid-2">
  <div class="card">
    <div class="card-body">
    <h2 class="section-title"><?php echo h(t('admin.system.h1_worker_heartbeat')); ?></h2>

    <?php if (!$lastCron): ?>
      <div class="text-sm text-sub"><?php echo h(t('admin.system.msg_no_heartbeat')); ?></div>
    <?php else: ?>
      <div class="text-sm text-sub space-y-2">
        <div><span class="detail-label"><?php echo h(t('admin.system.label_last_run')); ?>:</span> <span class="font-mono text-xs"><?php echo h(ui_dt($lastCron)); ?> UTC</span></div>
        <div><span class="detail-label">Last ok flag:</span> <?php echo h((string)($lastCronOk ?? '')); ?></div>
        <div>
          <span class="detail-label">Age:</span>
          <?php
            if ($ageSeconds === null) {
                echo '<span class="text-sub">unknown</span>';
            } else {
                $hours = (int)floor($ageSeconds / 3600);
                $mins = (int)floor(($ageSeconds % 3600) / 60);
                $ageStr = $hours . 'h ' . $mins . 'm';
                $stale = $ageSeconds > 12*3600;
                echo $stale
                  ? '<span style="color:var(--crit);font-weight:600">' . h($ageStr) . ' (stale)</span>'
                  : '<span style="color:var(--ok);font-weight:600">' . h($ageStr) . '</span>';
            }
          ?>
        </div>
      </div>

      <?php if ($ageSeconds !== null && $ageSeconds > 12*3600): ?>
        <div class="mt-4 alert alert-error">
          Heartbeat is older than 12h. If cron is configured, check cron logs and PHP runtime errors.
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

  <div class="card">
    <div class="card-body">
    <h2 class="section-title"><?php echo h(t('admin.system.h1_registration')); ?></h2>
    <div class="text-sm text-sub space-y-2">
      <div><span class="detail-label">REGISTRATION_MODE:</span> <span class="font-mono text-xs"><?php echo h($registrationMode); ?></span></div>
      <div><span class="detail-label">DB override disabled:</span> <?php echo $registrationsDisabled ? '<span style="color:var(--crit);font-weight:600">yes</span>' : '<span style="color:var(--ok);font-weight:600">no</span>'; ?></div>
      <div><span class="detail-label">SETUP_ADMIN_TOKEN set:</span> <?php echo $setupAdminTokenSet ? 'yes' : 'no'; ?></div>
      <div><span class="detail-label">ADMIN_EMAIL set:</span> <?php echo ($adminEmailBind !== '' ? 'yes' : 'no'); ?></div>
    </div>

    <div class="mt-4 flex gap-3">
      <?php if ($registrationsDisabled): ?>
        <form method="post" class="inline">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="registration_toggle" />
          <input type="hidden" name="desired" value="enable" />
          <button class="btn btn-primary btn-sm" type="submit"><?php echo h(t('admin.system.btn_enable_registrations')); ?></button>
        </form>
      <?php else: ?>
        <form method="post" class="inline" onsubmit="return confirm('Disable new registrations? Existing users can still sign in.');">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="registration_toggle" />
          <input type="hidden" name="desired" value="disable" />
          <button class="btn btn-secondary btn-sm" type="submit"><?php echo h(t('admin.system.btn_disable_registrations')); ?></button>
        </form>
      <?php endif; ?>
    </div>

    <div class="mt-3 text-xs text-muted">
      DB override only affects <span class="font-mono">/register.php</span>. If <span class="font-mono">REGISTRATION_MODE=closed</span>, registrations remain closed regardless.
    </div>
    </div>
  </div>


  <div class="card">
    <div class="card-body">
    <h2 class="section-title"><?php echo h(t('admin.system.h1_last_worker_run')); ?></h2>
    <?php if (!$lastRun): ?>
      <div class="text-sm text-sub">No worker_run event recorded yet.</div>
    <?php else: ?>
      <div class="text-sm text-sub">
        <div><span class="detail-label">Time:</span> <span class="font-mono text-xs"><?php echo h((string)$lastRun['created_at']); ?> UTC</span></div>
        <div class="mt-2 font-mono text-xs text-break"><?php echo h(format_event_meta((string)($lastRun['meta_json'] ?? ''))); ?></div>
        <?php if (!empty($lastRun['meta_json'])): ?>
          <details class="mt-2">
            <summary>raw</summary>
            <pre class="mt-2 code-block"><?php echo h((string)$lastRun['meta_json']); ?></pre>
          </details>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
    <h2 class="section-title"><?php echo h(t('admin.system.h1_events_last_24h')); ?></h2>
    <div class="text-sm text-sub space-y-2">
      <div><span class="detail-label">Critical:</span> <?php echo (int)$counts['critical']; ?></div>
      <div><span class="detail-label">Warn:</span> <?php echo (int)$counts['warn']; ?></div>
      <div><span class="detail-label">Info:</span> <?php echo (int)$counts['info']; ?></div>
    </div>
    <div class="mt-4 text-sm">
      <a href="<?php echo h(url_for('history.php?severity=critical')); ?>"><?php echo h(t('admin.system.link_view_critical_events')); ?></a>
    </div>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
    <h2 class="section-title"><?php echo h(t('admin.system.h1_outbox')); ?></h2>
    <div class="text-sm text-sub space-y-2">
      <div><span class="detail-label">Pending:</span> <?php echo (int)$outboxCounts['pending']; ?></div>
      <div><span class="detail-label">Failed:</span> <?php echo (int)$outboxCounts['failed']; ?></div>
      <div><span class="detail-label">Sent:</span> <?php echo (int)$outboxCounts['sent']; ?></div>
    </div>
    <div class="mt-4 text-sm">
      <a href="outbox.php"><?php echo h(t('admin.system.link_view_outbox')); ?></a>
      <form method="post" action="outbox_run.php" class="mt-3">
        <?php echo csrf_field(); ?>
        <button class="btn btn-primary btn-sm" type="submit"><?php echo h(t('admin.system.btn_run_outbox_now')); ?></button>
      </form>
    </div>
    </div>
  </div>

  <?php if ($rateLimitSummary !== null): ?>
  <div class="card">
    <div class="card-body">
    <h2 class="section-title"><?php echo h(t('admin.system.h1_rate_limiting')); ?></h2>
    <div class="text-sm text-sub space-y-2">
      <div><span class="detail-label">Login blocks:</span> <?php echo (int)$rateLimitSummary['login_ip']['blocks']; ?><?php echo $rateLimitSummary['login_ip']['last_block_at'] ? ' <span class="detail-label">(last:</span> <span class="font-mono text-xs">' . h((string)$rateLimitSummary['login_ip']['last_block_at']) . ' UTC</span><span class="detail-label">)</span>' : ''; ?></div>
      <div><span class="detail-label">API blocks (IP):</span> <?php echo (int)$rateLimitSummary['api_ip']['blocks']; ?><?php echo $rateLimitSummary['api_ip']['last_block_at'] ? ' <span class="detail-label">(last:</span> <span class="font-mono text-xs">' . h((string)$rateLimitSummary['api_ip']['last_block_at']) . ' UTC</span><span class="detail-label">)</span>' : ''; ?></div>
      <div><span class="detail-label">API blocks (token):</span> <?php echo (int)$rateLimitSummary['api_token']['blocks']; ?><?php echo $rateLimitSummary['api_token']['last_block_at'] ? ' <span class="detail-label">(last:</span> <span class="font-mono text-xs">' . h((string)$rateLimitSummary['api_token']['last_block_at']) . ' UTC</span><span class="detail-label">)</span>' : ''; ?></div>
    </div>

    <?php if (!empty($rateLimitRecentBlocks)): ?>
      <details class="mt-4">
        <summary>Recent blocks (20)</summary>
        <div class="mt-2 table-wrap">
          <table class="table table-compact">
            <thead>
              <tr>
                <th><?php echo h(t('admin.system.th_key')); ?></th>
                <th><?php echo h(t('admin.system.th_blocks')); ?></th>
                <th><?php echo h(t('admin.system.th_last_block')); ?></th>
                <th><?php echo h(t('admin.system.th_blocked_until')); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rateLimitRecentBlocks as $b): ?>
                <tr>
                  <td class="font-mono text-break"><?php echo h((string)$b['key']); ?></td>
                  <td><?php echo (int)($b['blocked_count'] ?? 0); ?></td>
                  <td class="font-mono"><?php echo h((string)($b['last_block_at'] ?? '')); ?><?php echo !empty($b['last_block_at']) ? ' UTC' : ''; ?></td>
                  <td class="font-mono"><?php echo h((string)($b['blocked_until'] ?? '')); ?><?php echo !empty($b['blocked_until']) ? ' UTC' : ''; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </details>
    <?php endif; ?>

    <div class="mt-4 text-xs text-muted">
      Limits are configured via <span class="font-mono">RATE_LIMIT_*</span> env vars. Defaults are high.
    </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<div class="mt-6 card">
  <div class="card-body">
  <h2 class="section-title"><?php echo h(t('admin.system.h1_worker_jobs')); ?></h2>
  <?php if (empty($jobs)): ?>
    <div class="text-sm text-sub">No jobs recorded.</div>
  <?php else: ?>
    <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th><?php echo h(t('admin.system.th_id')); ?></th>
          <th><?php echo h(t('admin.system.th_type')); ?></th>
          <th><?php echo h(t('admin.system.th_status')); ?></th>
          <th><?php echo h(t('admin.system.th_processed')); ?></th>
          <th><?php echo h(t('admin.system.th_requested_by')); ?></th>
          <th><?php echo h(t('admin.system.th_created')); ?></th>
          <th><?php echo h(t('admin.system.th_updated')); ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($jobs as $j): ?>
          <tr>
            <td class="font-mono text-xs">#<?php echo (int)$j['id']; ?></td>
            <td class="font-mono text-xs"><?php echo h((string)$j['type']); ?></td>
            <td><?php echo h((string)$j['status']); ?></td>
            <td><?php echo (int)$j['total_processed']; ?></td>
            <td class="text-xs"><?php echo h((string)($j['requested_by_email'] ?? '—')); ?></td>
            <td class="font-mono text-xs"><?php echo h((string)$j['created_at']); ?></td>
            <td class="font-mono text-xs"><?php echo h((string)$j['updated_at']); ?></td>
            <td>
              <?php if (in_array((string)$j['status'], ['pending','running'], true)): ?>
                <form method="post" action="job_cancel.php" class="inline">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="job_id" value="<?php echo (int)$j['id']; ?>">
                  <button class="btn btn-ghost btn-xs" style="color:var(--crit)" type="submit"><?php echo h(t('common.cancel')); ?></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
  </div>
</div>

<div class="mt-6 card">
  <div class="card-body">
  <h2 class="section-title"><?php echo h(t('admin.system.h1_system_events')); ?></h2>
  <?php if (!$sysEvents): ?>
    <div class="text-sm text-sub">No system events recorded.</div>
  <?php else: ?>
    <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th><?php echo h(t('admin.system.th_time_utc')); ?></th>
          <th><?php echo h(t('admin.system.th_severity')); ?></th>
          <th><?php echo h(t('admin.system.th_type')); ?></th>
          <th><?php echo h(t('admin.system.th_message')); ?></th>
          <th><?php echo h(t('admin.system.th_meta')); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($sysEvents as $e): ?>
          <tr>
            <td class="font-mono text-xs"><?php echo h((string)$e['created_at']); ?></td>
            <td><?php echo h((string)$e['severity']); ?></td>
            <td class="font-mono text-xs"><?php echo h((string)$e['type']); ?></td>
            <td><?php echo h((string)$e['message']); ?></td>
            <td class="font-mono text-xs text-break text-muted"><?php echo h(format_event_meta((string)($e['meta_json'] ?? ''))); ?></td>
          </tr>
          <?php if (!empty($e['meta_json'])): ?>
            <tr>
              <td></td><td></td><td></td><td></td>
              <td>
                <details>
                  <summary>raw</summary>
                  <pre class="mt-2 code-block"><?php echo h((string)$e['meta_json']); ?></pre>
                </details>
              </td>
            </tr>
          <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
  </div>
</div>

<?php render_footer(); ?>
