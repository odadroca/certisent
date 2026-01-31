<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$u = current_user();
if (!$u) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    Audit::log((int)$u['id'], 'user.logout', 'user', (int)$u['id'], []);
    logout_user();
    header('Location: index.php');
    exit;
}

// Transitional GET path: render confirmation requiring POST+CSRF.
// Keeps existing /logout.php links working without performing a side-effect on GET.
http_response_code(200);
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign out</title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body class="bg-gray-900 text-gray-100">
  <div class="max-w-lg mx-auto p-6">
    <h1 class="text-xl font-semibold mb-3">Sign out</h1>
    <p class="text-gray-300 mb-4">
      For security, sign-out requires a POST request.
    </p>

    <form method="post" action="logout.php" class="inline">
      <?php echo csrf_field(); ?>
      <button class="accent px-4 py-2 rounded" type="submit">Sign out</button>
      <a class="ml-3 text-gray-300 hover:underline" href="index.php">Cancel</a>
    </form>

    <p class="text-xs text-gray-500 mt-6">
      Note: the GET logout path is transitional and will be removed in a future release.
    </p>
  </div>
</body>
</html>
