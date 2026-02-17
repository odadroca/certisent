<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ui.php';

$user = require_login();

$id = (int)($_GET['id'] ?? 0);
$m = MonitorService::getMonitorById($id);
if (!$m) { http_response_code(404); echo t('common.not_found'); exit; }

// Authorization: owner OR admin/auditor
if ($user['role'] !== 'admin' && $user['role'] !== 'auditor' && (int)$m['user_id'] !== (int)$user['id']) {
    http_response_code(403); echo t('common.forbidden'); exit;
}

$latest = MonitorService::getLatestSnapshot($id);

$stS = db()->prepare('SELECT id,fetched_at,status,days_remaining,issuer_cn,subject_cn,serial,fingerprint_sha256,valid_from,valid_to,error,raw_pem FROM cert_snapshots WHERE monitor_id=:id ORDER BY fetched_at DESC LIMIT 20');
$stS->execute([':id'=>$id]);
$snapshots = $stS->fetchAll();

$stE = db()->prepare('SELECT id,created_at,severity,type,message,meta_json FROM events WHERE monitor_id=:id ORDER BY created_at DESC LIMIT 50');
$stE->execute([':id'=>$id]);
$events = $stE->fetchAll();

render_header(t('page.monitor_view.title'), $user);
?>

<div class="page-header">
  <div>
    <div class="page-title"><?php echo t('page.monitor_view.title'); ?></div>
    <div class="text-xs font-mono text-break"><?php echo h((string)$m['url']); ?></div>
  </div>
  <div class="flex flex-wrap gap-3">
    <?php if (has_role($user,'viewer')): ?>
      <a href="monitor_edit.php?id=<?php echo (int)$m['id']; ?>"><?php echo t('common.edit'); ?></a>
    <?php endif; ?>
    <a href="events.php?monitor_id=<?php echo (int)$m['id']; ?>"><?php echo t('common.events'); ?></a>
    <a href="dashboard.php"><?php echo t('common.back'); ?></a>
  </div>
</div>

<div class="grid-2">
  <div class="card">
    <div class="card-body">
      <h2 class="section-title"><?php echo t('monitor_view.current_settings'); ?></h2>
      <div class="text-sm text-sub space-y-2">
        <div><span class="detail-label"><?php echo t('common.enabled'); ?>:</span> <?php echo ((int)$m['enabled']===1 ? t('common.yes') : t('common.no')); ?></div>
        <div><span class="detail-label"><?php echo t('monitor_edit.check_frequency'); ?>:</span> <?php echo (int)$m['check_frequency_minutes']; ?> <?php echo t('common.minutes'); ?></div>
        <div><span class="detail-label"><?php echo t('dashboard.warn_threshold'); ?>:</span> <?php echo (int)$m['notify_days_before_expiry']; ?> <?php echo t('common.days'); ?></div>
        <div><span class="detail-label"><?php echo t('monitor_edit.notify_on_change'); ?>:</span> <?php echo ((int)$m['notify_on_change']===1 ? t('common.yes') : t('common.no')); ?></div>
        <div><span class="detail-label"><?php echo t('monitor_edit.notify_on_renewal'); ?>:</span> <?php echo ((int)$m['notify_on_renewal']===1 ? t('common.yes') : t('common.no')); ?></div>
      </div>

      <div style="border-top:1px solid var(--border-light);padding-top:1rem;margin-top:1rem">
        <h3 class="form-section-title"><?php echo t('tls.label.validation'); ?></h3>
        <?php
          $mode = (string)($m['tls_validation_mode'] ?? 'off');
          $modeKey = 'tls.mode.' . ($mode ?: 'off');
          $hostnameOk = $m['hostname_ok'];
          $trustOk = $m['trust_ok'];
        ?>
        <div class="text-sm text-sub space-y-2">
          <div>
            <span class="detail-label"><?php echo t('tls.label.mode'); ?>:</span>
            <?php echo t($modeKey); ?>
          </div>
          <div>
            <span class="detail-label"><?php echo t('tls.label.hostname'); ?>:</span>
            <?php if ($mode === 'off'): ?>
              <?php echo t('tls.value.off'); ?>
            <?php elseif ($hostnameOk === null): ?>
              <?php echo t('tls.value.unknown'); ?>
            <?php elseif ((int)$hostnameOk === 1): ?>
              <?php echo t('tls.value.ok'); ?>
            <?php else: ?>
              <?php echo t('tls.value.mismatch'); ?>
              <?php if (!empty($m['hostname_error'])): ?>
                <span class="text-xs text-muted">(<?php echo h((string)$m['hostname_error']); ?>)</span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <div>
            <span class="detail-label"><?php echo t('tls.label.trust'); ?>:</span>
            <?php if ($mode === 'off'): ?>
              <?php echo t('tls.value.off'); ?>
            <?php elseif ($trustOk === null): ?>
              <?php echo t('tls.value.unknown'); ?>
            <?php elseif ((int)$trustOk === 1): ?>
              <?php echo t('tls.value.ok'); ?>
            <?php else: ?>
              <?php
                $cat = (string)($m['trust_category'] ?? 'tls_untrusted_unknown');
                if ($cat === 'tls_self_signed') {
                    echo t('tls.category.tls_self_signed');
                } elseif ($cat === 'tls_untrusted_root') {
                    echo t('tls.category.tls_untrusted_root');
                } else {
                    echo t('tls.category.tls_untrusted_unknown');
                }
              ?>
              <?php if (!empty($m['trust_error'])): ?>
                <span class="text-xs text-muted">(<?php echo h((string)$m['trust_error']); ?>)</span>
              <?php endif; ?>
            <?php endif; ?>
          </div>

          <?php
            $pinMode = (string)($m['pin_mode'] ?? 'off');
            $pinModeKey = 'tls.mode.' . ($pinMode ?: 'off');
            $pinVal = (string)($m['pin_spki_sha256'] ?? '');
          ?>
          <div>
            <span class="detail-label"><?php echo t('tls.label.pinning'); ?>:</span>
            <?php if ($pinMode === 'off'): ?>
              <?php echo t('tls.value.off'); ?>
            <?php else: ?>
              <?php echo t($pinModeKey); ?>
              <?php if ($pinVal !== ''): ?>
                <div class="text-xs text-muted mt-1">
                  <?php echo t('tls.label.pin_value'); ?>: <span class="font-mono text-break"><?php echo h('sha256/'.$pinVal); ?></span>
                </div>
              <?php else: ?>
                <span class="text-xs text-muted">(<?php echo t('tls.value.not_configured'); ?>)</span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <?php if (has_role($user,'viewer')): ?>
        <form method="post" action="monitor_check.php" class="mt-4">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="id" value="<?php echo (int)$m['id']; ?>" />
          <button class="btn btn-primary"><?php echo t('monitor_view.check_now_store'); ?></button>
          <div class="form-help mt-2"><?php echo t('monitor_view.check_now_store_note'); ?></div>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <h2 class="section-title"><?php echo t('monitor_view.latest_snapshot'); ?></h2>
      <?php if (!$latest): ?>
        <div class="text-sm text-sub"><?php echo t('monitor_view.no_snapshots'); ?></div>
      <?php else: ?>
        <div class="text-sm text-sub space-y-2">
          <div><span class="detail-label"><?php echo t('monitor_view.label.status'); ?>:</span> <?php echo badge_status((string)$latest['status']); ?></div>
          <div><span class="detail-label"><?php echo t('monitor_view.label.issuer_cn'); ?>:</span> <?php echo h((string)$latest['issuer_cn']); ?></div>
          <div><span class="detail-label"><?php echo t('monitor_view.label.subject_cn'); ?>:</span> <?php echo h((string)$latest['subject_cn']); ?></div>
          <div><span class="detail-label"><?php echo t('dashboard.valid_from'); ?>:</span> <span class="font-mono text-xs"><?php echo h((string)$latest['valid_from']); ?> UTC</span></div>
          <div><span class="detail-label"><?php echo t('dashboard.valid_to'); ?>:</span> <span class="font-mono text-xs"><?php echo h((string)$latest['valid_to']); ?> UTC</span></div>
          <div><span class="detail-label"><?php echo t('monitor_view.label.days_remaining'); ?>:</span> <?php echo h((string)$latest['days_remaining']); ?></div>
          <div><span class="detail-label"><?php echo t('monitor_view.label.fingerprint'); ?>:</span> <span class="font-mono text-xs text-break"><?php echo h((string)$latest['fingerprint_sha256']); ?></span></div>
          <?php if (!empty($latest['error'])): ?>
            <div><span class="detail-label"><?php echo t('monitor_view.label.error'); ?>:</span> <?php echo h((string)$latest['error']); ?></div>
          <?php endif; ?>
        </div>
        <div class="mt-3"><?php echo progress_bar((int)$latest['days_remaining'], (string)$latest['valid_from'], (string)$latest['valid_to']); ?></div>
        <details class="mt-4">
          <summary><?php echo t('monitor_view.raw_pem'); ?></summary>
          <pre class="code-block mt-2"><?php echo h((string)$latest['raw_pem']); ?></pre>
        </details>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="mt-6 grid-2">
  <div class="card">
    <div class="card-body">
      <h2 class="section-title"><?php echo t('monitor_view.recent_snapshots'); ?></h2>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th><?php echo t('common.time_utc'); ?></th>
              <th>Status</th>
              <th><?php echo t('dashboard.table.valid_to'); ?></th>
              <th><?php echo t('monitor_view.table.days'); ?></th>
              <th><?php echo t('monitor_view.label.fingerprint'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($snapshots as $s): ?>
              <tr>
                <td class="font-mono text-xs"><?php echo h((string)$s['fetched_at']); ?></td>
                <td><?php echo badge_status((string)$s['status']); ?></td>
                <td class="font-mono text-xs"><?php echo h((string)$s['valid_to']); ?></td>
                <td><?php echo h((string)$s['days_remaining']); ?></td>
                <td class="font-mono text-xs text-break"><?php echo h((string)$s['fingerprint_sha256']); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <h2 class="section-title"><?php echo t('monitor_view.recent_events'); ?></h2>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th><?php echo t('common.time_utc'); ?></th>
              <th><?php echo t('common.severity'); ?></th>
              <th><?php echo t('common.type'); ?></th>
              <th><?php echo t('common.message'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($events as $e): ?>
              <tr>
                <td class="font-mono text-xs"><?php echo h((string)$e['created_at']); ?></td>
                <td><?php echo h((string)$e['severity']); ?></td>
                <td class="font-mono text-xs"><?php echo h((string)$e['type']); ?></td>
                <td><?php echo h((string)$e['message']); ?></td>
              </tr>
              <?php if (!empty($e['meta_json'])): ?>
                <tr>
                  <td></td><td></td><td></td>
                  <td>
                    <div class="font-mono text-xs text-break text-muted"><?php echo h(format_event_meta((string)$e['meta_json'])); ?></div>
                    <details class="mt-1">
                      <summary><?php echo t('common.raw'); ?></summary>
                      <pre class="code-block mt-2"><?php echo h((string)$e['meta_json']); ?></pre>
                    </details>
                  </td>
                </tr>
              <?php endif; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php render_footer(); ?>
