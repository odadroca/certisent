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

<div class="flex items-start justify-between mb-4">
  <div>
    <div class="text-lg font-semibold"><?php echo t('page.monitor_view.title'); ?></div>
    <div class="text-xs text-gray-400 font-mono break-all"><?php echo h((string)$m['url']); ?></div>
  </div>
  <div class="flex flex-wrap gap-3">
    <?php if (has_role($user,'viewer')): ?>
      <a class="text-green-400 hover:underline" href="monitor_edit.php?id=<?php echo (int)$m['id']; ?>"><?php echo t('common.edit'); ?></a>
    <?php endif; ?>
    <a class="text-green-400 hover:underline" href="events.php?monitor_id=<?php echo (int)$m['id']; ?>"><?php echo t('common.events'); ?></a>
    <a class="text-green-400 hover:underline" href="dashboard.php"><?php echo t('common.back'); ?></a>
  </div>
</div>

<div class="grid md:grid-cols-2 gap-4">
  <div class="bg-white text-black rounded-2xl p-6 shadow">
    <h2 class="font-semibold mb-3"><?php echo t('monitor_view.current_settings'); ?></h2>
    <div class="text-sm text-gray-700 space-y-1">
      <div><span class="text-gray-500"><?php echo t('common.enabled'); ?>:</span> <?php echo ((int)$m['enabled']===1 ? t('common.yes') : t('common.no')); ?></div>
      <div><span class="text-gray-500"><?php echo t('monitor_edit.check_frequency'); ?>:</span> <?php echo (int)$m['check_frequency_minutes']; ?> <?php echo t('common.minutes'); ?></div>
      <div><span class="text-gray-500"><?php echo t('dashboard.warn_threshold'); ?>:</span> <?php echo (int)$m['notify_days_before_expiry']; ?> <?php echo t('common.days'); ?></div>
      <div><span class="text-gray-500"><?php echo t('monitor_edit.notify_on_change'); ?>:</span> <?php echo ((int)$m['notify_on_change']===1 ? t('common.yes') : t('common.no')); ?></div>
      <div><span class="text-gray-500"><?php echo t('monitor_edit.notify_on_renewal'); ?>:</span> <?php echo ((int)$m['notify_on_renewal']===1 ? t('common.yes') : t('common.no')); ?></div>
    </div>

    <div class="mt-4 pt-4 border-t">
      <h3 class="font-semibold mb-2"><?php echo t('tls.label.validation'); ?></h3>
      <?php
        $mode = (string)($m['tls_validation_mode'] ?? 'off');
        $modeKey = 'tls.mode.' . ($mode ?: 'off');
        $hostnameOk = $m['hostname_ok'];
        $trustOk = $m['trust_ok'];
      ?>
      <div class="text-sm text-gray-700 space-y-1">
        <div>
          <span class="text-gray-500"><?php echo t('tls.label.mode'); ?>:</span>
          <?php echo t($modeKey); ?>
        </div>
        <div>
          <span class="text-gray-500"><?php echo t('tls.label.hostname'); ?>:</span>
          <?php if ($mode === 'off'): ?>
            <?php echo t('tls.value.off'); ?>
          <?php elseif ($hostnameOk === null): ?>
            <?php echo t('tls.value.unknown'); ?>
          <?php elseif ((int)$hostnameOk === 1): ?>
            <?php echo t('tls.value.ok'); ?>
          <?php else: ?>
            <?php echo t('tls.value.mismatch'); ?>
            <?php if (!empty($m['hostname_error'])): ?>
              <span class="text-xs text-gray-600">(<?php echo h((string)$m['hostname_error']); ?>)</span>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <div>
          <span class="text-gray-500"><?php echo t('tls.label.trust'); ?>:</span>
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
              <span class="text-xs text-gray-600">(<?php echo h((string)$m['trust_error']); ?>)</span>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if (has_role($user,'viewer')): ?>
      <form method="post" action="monitor_check.php" class="mt-4">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="id" value="<?php echo (int)$m['id']; ?>" />
        <button class="bg-green-700 text-white px-4 py-2 rounded"><?php echo t('monitor_view.check_now_store'); ?></button>
        <div class="text-xs text-gray-600 mt-2"><?php echo t('monitor_view.check_now_store_note'); ?></div>
      </form>
    <?php endif; ?>
  </div>

  <div class="bg-white text-black rounded-2xl p-6 shadow">
    <h2 class="font-semibold mb-3"><?php echo t('monitor_view.latest_snapshot'); ?></h2>
    <?php if (!$latest): ?>
      <div class="text-sm text-gray-700"><?php echo t('monitor_view.no_snapshots'); ?></div>
    <?php else: ?>
      <div class="text-sm text-gray-700 space-y-1">
        <div><span class="text-gray-500"><?php echo t('monitor_view.label.status'); ?>:</span> <?php echo badge_status((string)$latest['status']); ?></div>
        <div><span class="text-gray-500"><?php echo t('monitor_view.label.issuer_cn'); ?>:</span> <?php echo h((string)$latest['issuer_cn']); ?></div>
        <div><span class="text-gray-500"><?php echo t('monitor_view.label.subject_cn'); ?>:</span> <?php echo h((string)$latest['subject_cn']); ?></div>
        <div><span class="text-gray-500"><?php echo t('dashboard.valid_from'); ?>:</span> <span class="font-mono text-xs"><?php echo h((string)$latest['valid_from']); ?> UTC</span></div>
        <div><span class="text-gray-500"><?php echo t('dashboard.valid_to'); ?>:</span> <span class="font-mono text-xs"><?php echo h((string)$latest['valid_to']); ?> UTC</span></div>
        <div><span class="text-gray-500"><?php echo t('monitor_view.label.days_remaining'); ?>:</span> <?php echo h((string)$latest['days_remaining']); ?></div>
        <div><span class="text-gray-500"><?php echo t('monitor_view.label.fingerprint'); ?>:</span> <span class="font-mono text-xs break-all"><?php echo h((string)$latest['fingerprint_sha256']); ?></span></div>
        <?php if (!empty($latest['error'])): ?>
          <div><span class="text-gray-500"><?php echo t('monitor_view.label.error'); ?>:</span> <?php echo h((string)$latest['error']); ?></div>
        <?php endif; ?>
      </div>
      <div class="mt-3"><?php echo progress_bar((int)$latest['days_remaining'], (string)$latest['valid_from'], (string)$latest['valid_to']); ?></div>
      <details class="mt-4">
        <summary class="cursor-pointer text-sm text-green-700"><?php echo t('monitor_view.raw_pem'); ?></summary>
        <pre class="mt-2 p-3 bg-gray-100 rounded text-xs overflow-x-auto"><?php echo h((string)$latest['raw_pem']); ?></pre>
      </details>
    <?php endif; ?>
  </div>
</div>

<div class="mt-6 grid md:grid-cols-2 gap-4">
  <div class="bg-white text-black rounded-2xl p-6 shadow overflow-x-auto">
    <h2 class="font-semibold mb-3"><?php echo t('monitor_view.recent_snapshots'); ?></h2>
    <table class="min-w-full text-sm">
      <thead>
        <tr class="text-left border-b">
          <th class="py-2 pr-3"><?php echo t('common.time_utc'); ?></th>
          <th class="py-2 pr-3">Status</th>
          <th class="py-2 pr-3"><?php echo t('dashboard.table.valid_to'); ?></th>
          <th class="py-2 pr-3"><?php echo t('monitor_view.table.days'); ?></th>
          <th class="py-2 pr-3"><?php echo t('monitor_view.label.fingerprint'); ?></th>
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
    <h2 class="font-semibold mb-3"><?php echo t('monitor_view.recent_events'); ?></h2>
    <table class="min-w-full text-sm">
      <thead>
        <tr class="text-left border-b">
          <th class="py-2 pr-3"><?php echo t('common.time_utc'); ?></th>
          <th class="py-2 pr-3"><?php echo t('common.severity'); ?></th>
          <th class="py-2 pr-3"><?php echo t('common.type'); ?></th>
          <th class="py-2 pr-3"><?php echo t('common.message'); ?></th>
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
                  <summary class="cursor-pointer text-xs text-gray-500"><?php echo t('common.raw'); ?></summary>
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
