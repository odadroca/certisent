<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$token = (string)($_GET['token'] ?? '');
if ($token === '') {
    http_response_code(400);
    echo "token required";
    exit;
}

$st = db()->prepare("SELECT id,email FROM users WHERE rss_token=:t");
$st->execute([':t'=>$token]);
$u = $st->fetch();
if (!$u) {
    http_response_code(404);
    echo "not found";
    exit;
}

$st2 = db()->prepare("
    SELECT e.*, m.url, m.host
    FROM events e
    LEFT JOIN monitors m ON m.id=e.monitor_id
    WHERE (m.user_id=:uid OR e.monitor_id IS NULL)
    ORDER BY e.created_at DESC
    LIMIT 50
");
$st2->execute([':uid'=>$u['id']]);
$events = $st2->fetchAll();

header('Content-Type: application/rss+xml; charset=utf-8');

$appUrl = app_base_url();
$feedTitle = "Certinel events for " . $u['email'];
$feedLink = $appUrl ?: '';
$feedDesc = "Certificate monitoring events (UTC).";

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
?>
<rss version="2.0">
  <channel>
    <title><?php echo htmlspecialchars($feedTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <link><?php echo htmlspecialchars($feedLink, ENT_QUOTES, 'UTF-8'); ?></link>
    <description><?php echo htmlspecialchars($feedDesc, ENT_QUOTES, 'UTF-8'); ?></description>
    <lastBuildDate><?php echo gmdate(DATE_RSS); ?></lastBuildDate>
    <?php foreach ($events as $e): ?>
      <?php
        $title = ($e['severity'] ?? '').' '.($e['type'] ?? '').' — '.(($e['host'] ?? '') ?: 'system');
        $guid = 'certinel-event-' . (int)$e['id'];
        $pub = gmdate(DATE_RSS, strtotime($e['created_at'].' UTC'));
        $desc = ($e['message'] ?? '') . " | url=" . ($e['url'] ?? '');
      ?>
      <item>
        <title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
        <guid><?php echo htmlspecialchars($guid, ENT_QUOTES, 'UTF-8'); ?></guid>
        <pubDate><?php echo htmlspecialchars($pub, ENT_QUOTES, 'UTF-8'); ?></pubDate>
        <description><?php echo htmlspecialchars($desc, ENT_QUOTES, 'UTF-8'); ?></description>
      </item>
    <?php endforeach; ?>
  </channel>
</rss>
