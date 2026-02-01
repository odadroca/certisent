<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ui.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
csrf_verify();

$url = (string)($_POST['url'] ?? '');
$err = '';
$res = null;

try {
    $p = MonitorService::parseUrl($url);
    $f = CertFetcher::fetch($p['host'], $p['port']);
    if (!$f['ok']) {
        $err = (string)$f['error'];
    } else {
        $parsed = $f['parsed'] ?? [];
        $vf = (int)($parsed['validFrom_time_t'] ?? 0);
        $vt = (int)($parsed['validTo_time_t'] ?? 0);
        $days = (int)floor(($vt - time()) / 86400);

        // v0.7.2: hostname validation ("wrong.host" style) for immediate user feedback.
        $hv = null;
        if (is_array($parsed) && !empty($p['host'])) {
            $hv = TlsValidator::validateHostname((string)$p['host'], $parsed);
        }
        $res = [
            'url'=>$p['url'],
            'host'=>$p['host'],
            'port'=>$p['port'],
            'fingerprint'=>$f['fingerprint_sha256'] ?? '',
            'issuer'=>$parsed['issuer']['CN'] ?? ($parsed['issuer']['O'] ?? ''),
            'subject'=>$parsed['subject']['CN'] ?? ($parsed['subject']['O'] ?? ''),
            'valid_from'=> $vf ? gmdate('Y-m-d H:i:s', $vf) : null,
            'valid_to'=> $vt ? gmdate('Y-m-d H:i:s', $vt) : null,
            'days_remaining'=>$days,
            'hostname_validation'=>$hv,
        ];
    }
} catch (Throwable $e) {
    $err = $e->getMessage();
}

render_header('Quick check', current_user());
?>
<div class="bg-white text-black rounded-2xl p-6 shadow">
  <h1 class="text-xl font-semibold mb-3">Quick check result</h1>

  <?php if ($err): ?>
    <div class="p-3 rounded bg-red-100 text-red-800 text-sm mb-4">
      Error: <?php echo h($err); ?>
    </div>
  <?php elseif ($res): ?>
    <?php $hv = $res['hostname_validation'] ?? null; ?>
    <?php if (is_array($hv) && (($hv['ok'] ?? true) === false) && (($hv['error'] ?? '') === 'hostname_mismatch')): ?>
      <div class="p-3 rounded bg-yellow-100 text-yellow-900 text-sm mb-4 border border-yellow-200">
        <div class="font-semibold mb-1">Warning: hostname mismatch</div>
        <div>The certificate presented by this endpoint does <span class="font-semibold">not</span> match the requested host <span class="font-mono"><?php echo h((string)$res['host']); ?></span>.</div>
        <?php if (!empty($hv['candidates']) && is_array($hv['candidates'])): ?>
          <?php $c = array_slice(array_values(array_map('strval', $hv['candidates'])), 0, 8); ?>
          <?php if (count($c) > 0): ?>
            <div class="mt-1 text-xs">Names on cert: <span class="font-mono break-all"><?php echo h(implode(', ', $c)); ?></span></div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="grid md:grid-cols-2 gap-4 text-sm">
      <div><span class="text-gray-600">URL</span><div class="font-mono"><?php echo h($res['url']); ?></div></div>
      <div><span class="text-gray-600">Fingerprint (SHA-256)</span><div class="font-mono break-all"><?php echo h($res['fingerprint']); ?></div></div>
      <div><span class="text-gray-600">Issuer</span><div><?php echo h((string)$res['issuer']); ?></div></div>
      <div><span class="text-gray-600">Subject</span><div><?php echo h((string)$res['subject']); ?></div></div>
      <div><span class="text-gray-600">Valid from</span><div><?php echo h((string)$res['valid_from']); ?> UTC</div></div>
      <div><span class="text-gray-600">Valid to</span><div><?php echo h((string)$res['valid_to']); ?> UTC</div></div>
      <div><span class="text-gray-600">Days remaining</span><div class="font-semibold"><?php echo h((string)$res['days_remaining']); ?></div></div>
    </div>
  <?php endif; ?>

  <div class="mt-6">
    <a class="text-green-400 hover:underline" href="<?php echo current_user() ? 'dashboard.php' : 'index.php'; ?>">Back</a>
  </div>
</div>
<?php render_footer(); ?>
