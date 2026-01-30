<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ui.php';

$user = current_user();
render_header('Home', $user);

?>
<div class="bg-white text-black rounded-2xl p-6 shadow">
  <h1 class="text-xl font-semibold mb-2">Detect silent TLS certificate changes before they break integrations.</h1>
  <p class="text-sm text-gray-700 mb-4">
    Certinel records the certificate an endpoint serves <span class="font-medium">right now</span>, detects renewals/replacements before expiry, and alerts.
  </p>

  <div class="grid md:grid-cols-2 gap-6">
    <div>
      <h2 class="font-semibold mb-2">Quick check</h2>
      <form method="post" action="check_now.php" class="space-y-2">
        <?php echo csrf_field(); ?>
        <input name="url" placeholder="https://api.example.com" class="w-full border rounded px-3 py-2" />
        <button class="bg-green-700 text-white px-4 py-2 rounded">Check now</button>
      </form>
      <p class="text-xs text-gray-600 mt-2">No data is stored for quick checks.</p>
    </div>
    <div>
      <h2 class="font-semibold mb-2">Start monitoring</h2>
      <ul class="text-sm text-gray-700 list-disc pl-5 space-y-1">
        <li>Create an account</li>
        <li>Add one or more URLs</li>
        <li>Set “warn me N days before expiry”</li>
        <li>Run the worker via cron (or call the API worker endpoint)</li>
      </ul>
      <div class="mt-4 flex flex-wrap gap-3">
        <?php if ($user): ?>
          <a class="bg-black text-white px-4 py-2 rounded" href="dashboard.php">Go to dashboard</a>
          <?php if (has_role($user,'viewer')): ?>
            <a class="bg-white border border-black text-black px-4 py-2 rounded" href="monitor_add.php">Add monitor</a>
          <?php endif; ?>
        <?php else: ?>
          <a class="bg-black text-white px-4 py-2 rounded" href="register.php">Register</a>
          <a class="bg-white border border-black text-black px-4 py-2 rounded" href="login.php">Sign in</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php render_footer(); ?>
