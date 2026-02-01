<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ui.php';

$user = require_login();
if (!has_role($user,'viewer')) { http_response_code(403); echo t('common.forbidden'); exit; }

$id = (int)($_GET['id'] ?? 0);
$m = MonitorService::getMonitorById($id);
if (!$m) { http_response_code(404); echo t('common.not_found'); exit; }

// Authorization: owner or admin
if ($user['role'] !== 'admin' && (int)$m['user_id'] !== (int)$user['id']) {
    http_response_code(403); echo t('common.forbidden'); exit;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $url = (string)($_POST['url'] ?? '');
    $days = max(1, min(365, (int)($_POST['notify_days'] ?? 30)));
    $freq = max(5, min(1440, (int)($_POST['freq_min'] ?? 60)));
    $enabled = isset($_POST['enabled']) ? 1 : 0;
    $noc = isset($_POST['notify_on_change']) ? 1 : 0;
    $nor = isset($_POST['notify_on_renewal']) ? 1 : 0;
    $tvm = (string)($_POST['tls_validation_mode'] ?? ($m['tls_validation_mode'] ?? 'off'));
    if (!in_array($tvm, ['off','observe','enforce'], true)) {
        $tvm = 'off';
    }

    // v0.7.6: Certinel-defined pinning (SPKI sha256) (opt-in).
    $pm = (string)($_POST['pin_mode'] ?? ($m['pin_mode'] ?? 'off'));
    if (!in_array($pm, ['off','observe','enforce'], true)) {
        $pm = 'off';
    }
    $ps = (string)($_POST['pin_spki_sha256'] ?? ($m['pin_spki_sha256'] ?? ''));

    try {
        MonitorService::updateMonitor((int)$user['id'], $id, $url, $days, $freq, $enabled, $noc, $nor, $tvm, $pm, $ps);
        header('Location: dashboard.php');
        exit;
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

render_header(t('page.monitor_edit.title'), $user);
?>
<div class="bg-white text-black rounded-2xl p-6 shadow max-w-xl">
  <h1 class="text-xl font-semibold mb-4"><?php echo t('monitor_edit.heading'); ?></h1>
  <?php if ($err): ?>
    <div class="mb-3 p-3 rounded bg-red-100 text-red-800 text-sm"><?php echo h($err); ?></div>
  <?php endif; ?>
  <form method="post" class="space-y-3">
    <?php echo csrf_field(); ?>
    <div>
      <label class="text-sm"><?php echo t('common.url'); ?></label>
      <input name="url" class="w-full border rounded px-3 py-2" value="<?php echo h($_POST['url'] ?? $m['url']); ?>" />
    </div>
    <div class="grid md:grid-cols-2 gap-3">
      <div>
        <label class="text-sm"><?php echo t('monitor.common.notify_days'); ?></label>
        <input type="number" name="notify_days" class="w-full border rounded px-3 py-2" value="<?php echo h((string)($_POST['notify_days'] ?? $m['notify_days_before_expiry'])); ?>" />
      </div>
      <div>
        <label class="text-sm"><?php echo t('monitor_edit.check_frequency'); ?></label>
        <input type="number" name="freq_min" class="w-full border rounded px-3 py-2" value="<?php echo h((string)($_POST['freq_min'] ?? $m['check_frequency_minutes'])); ?>" />
      </div>
    </div>

    <div>
      <label class="text-sm"><?php echo t('monitor_edit.tls_validation_mode'); ?></label>
      <?php $curTvm = (string)($_POST['tls_validation_mode'] ?? ($m['tls_validation_mode'] ?? 'off')); ?>
      <select name="tls_validation_mode" class="w-full border rounded px-3 py-2">
        <option value="off" <?php echo $curTvm==='off' ? 'selected' : ''; ?>><?php echo t('monitor_edit.tls_mode.off'); ?></option>
        <option value="observe" <?php echo $curTvm==='observe' ? 'selected' : ''; ?>><?php echo t('monitor_edit.tls_mode.observe'); ?></option>
        <option value="enforce" <?php echo $curTvm==='enforce' ? 'selected' : ''; ?>><?php echo t('monitor_edit.tls_mode.enforce'); ?></option>
      </select>
      <div class="text-xs text-gray-600 mt-1"><?php echo t('monitor_edit.tls_validation_note'); ?></div>
    </div>

    <div class="mt-1">
      <label class="text-sm"><?php echo t('monitor_edit.pin_mode'); ?></label>
      <?php $curPm = (string)($_POST['pin_mode'] ?? ($m['pin_mode'] ?? 'off')); ?>
      <select name="pin_mode" class="w-full border rounded px-3 py-2">
        <option value="off" <?php echo $curPm==='off' ? 'selected' : ''; ?>><?php echo t('monitor_edit.pin_mode.off'); ?></option>
        <option value="observe" <?php echo $curPm==='observe' ? 'selected' : ''; ?>><?php echo t('monitor_edit.pin_mode.observe'); ?></option>
        <option value="enforce" <?php echo $curPm==='enforce' ? 'selected' : ''; ?>><?php echo t('monitor_edit.pin_mode.enforce'); ?></option>
      </select>
      <div class="text-xs text-gray-600 mt-1"><?php echo t('monitor_edit.pin_note'); ?></div>
    </div>

    <div>
      <label class="text-sm"><?php echo t('monitor_edit.pin_value'); ?></label>
      <input name="pin_spki_sha256" class="w-full border rounded px-3 py-2 font-mono text-xs" placeholder="sha256/&lt;base64&gt;" value="<?php echo h((string)($_POST['pin_spki_sha256'] ?? ($m['pin_spki_sha256'] ?? ''))); ?>" />
      <div class="text-xs text-gray-600 mt-1"><?php echo t('monitor_edit.pin_value_note'); ?></div>
    </div>
    <div class="flex items-center gap-2">
      <input type="checkbox" name="enabled" <?php echo ((int)($m['enabled'])===1 ? 'checked' : ''); ?> />
      <label class="text-sm"><?php echo t('common.enabled'); ?></label>
    </div>

    <div class="grid md:grid-cols-2 gap-3">
      <div class="flex items-center gap-2">
        <input type="checkbox" name="notify_on_change" <?php echo ((int)($m['notify_on_change'])===1 ? 'checked' : ''); ?> />
        <label class="text-sm"><?php echo t('monitor_edit.notify_on_change'); ?></label>
      </div>
      <div class="flex items-center gap-2">
        <input type="checkbox" name="notify_on_renewal" <?php echo ((int)($m['notify_on_renewal'])===1 ? 'checked' : ''); ?> />
        <label class="text-sm"><?php echo t('monitor_edit.notify_on_renewal'); ?></label>
      </div>
    </div>
    <button class="bg-green-700 text-white px-4 py-2 rounded"><?php echo t('common.save'); ?></button>
    <a class="ml-3 text-green-400 hover:underline" href="dashboard.php"><?php echo t('common.cancel'); ?></a>
  </form>

  <div class="mt-6 text-xs text-gray-600">
    <div><?php echo t('monitor_edit.tip_worker'); ?></div>
  </div>
</div>
<?php render_footer(); ?>
