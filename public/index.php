<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ui.php';

$user = current_user();
$title = function_exists('t') ? t('page.home') : 'Home';
render_header($title, $user);

?>
<div class="card">
  <div class="card-body">
    <h1 class="page-title mb-2"><?php echo h(function_exists('t') ? t('landing.hero') : 'Detect silent TLS certificate changes before they break integrations.'); ?></h1>
    <p class="text-sm text-sub mb-4">
      <?php echo h(function_exists('t') ? t('landing.lead') : 'Certisent records the certificate an endpoint serves right now, detects renewals/replacements before expiry, and alerts.'); ?>
    </p>

    <div class="grid-2">
      <div>
        <h2 class="font-semibold mb-2"><?php echo h(function_exists('t') ? t('landing.quick_check') : 'Quick check'); ?></h2>
        <form method="post" action="check_now.php" class="space-y-2">
          <?php echo csrf_field(); ?>
          <input name="url" placeholder="<?php echo h(function_exists('t') ? t('landing.url_placeholder') : 'https://api.example.com'); ?>" class="form-input" />
          <button class="btn btn-primary"><?php echo h(function_exists('t') ? t('btn.check_now') : 'Check now'); ?></button>
        </form>
        <p class="text-xs text-muted mt-2"><?php echo h(function_exists('t') ? t('landing.quick_check_note') : 'No data is stored for quick checks.'); ?></p>
      </div>
      <div>
        <h2 class="font-semibold mb-2"><?php echo h(function_exists('t') ? t('landing.start_monitoring') : 'Start monitoring'); ?></h2>
        <ul class="text-sm text-sub space-y-1" style="list-style:disc;padding-left:1.25rem">
          <li><?php echo h(function_exists('t') ? t('landing.step.create_account') : 'Create an account'); ?></li>
          <li><?php echo h(function_exists('t') ? t('landing.step.add_urls') : 'Add one or more URLs'); ?></li>
          <li><?php echo h(function_exists('t') ? t('landing.step.set_warn') : 'Set "warn me N days before expiry"'); ?></li>
          <li><?php echo h(function_exists('t') ? t('landing.step.run_worker') : 'Run the worker via cron (or call the API worker endpoint)'); ?></li>
        </ul>
        <div class="mt-4 flex flex-wrap gap-3">
          <?php if ($user): ?>
            <a class="btn btn-secondary" href="dashboard.php"><?php echo h(function_exists('t') ? t('landing.go_dashboard') : 'Go to dashboard'); ?></a>
            <?php if (has_role($user,'viewer')): ?>
              <a class="btn btn-secondary" href="monitor_add.php"><?php echo h(function_exists('t') ? t('landing.add_monitor') : 'Add monitor'); ?></a>
            <?php endif; ?>
          <?php else: ?>
            <a class="btn btn-primary" href="register.php"><?php echo h(function_exists('t') ? t('nav.register') : 'Register'); ?></a>
            <a class="btn btn-secondary" href="login.php"><?php echo h(function_exists('t') ? t('nav.sign_in') : 'Sign in'); ?></a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php render_footer(); ?>
