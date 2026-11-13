<?php
/**
 * mbc.mw/go/<name>  ->  wherever marketing has pointed <name> in the admin tool.
 * Uses 302 + no-store so a destination can be changed at any time without reprinting the QR.
 */
define('MBC_GO', 1);
require __DIR__ . '/inc/bootstrap.php';

$cfg = go_config();
$slug = go_normalize_slug((string) ($_GET['s'] ?? ''));

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

if ($slug === '') {
    header('Location: ' . $cfg['fallback_url'], true, 302);
    exit;
}

$links = go_read_links();
$link = $links[$slug] ?? null;

if ($link && !empty($link['active'])) {
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $device = go_device($ua);
    $to = $link['url'];
    if ($device === 'i' && !empty($link['ios'])) $to = $link['ios'];
    if ($device === 'a' && !empty($link['android'])) $to = $link['android'];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !go_is_bot($ua)) go_log_scan($slug, $device);
    header('Location: ' . $to, true, 302);
    exit;
}

// Unknown or paused: a friendly page instead of an error, with a way onward.
http_response_code($link ? 410 : 404);
$fallback = $cfg['fallback_url'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>MBC</title>
<link rel="icon" href="/go/favicon.png" type="image/png">
<style>
  body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; box-sizing: border-box;
         font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #1a1214; background: #fbf8f7; }
  main { max-width: 380px; width: 100%; background: #fff; border: 1px solid #eadfe0; border-radius: 20px; overflow: hidden; text-align: center; }
  .stripe { display: grid; grid-template-columns: 1fr 1fr 1fr; height: 6px; }
  .stripe i:nth-child(1) { background: #111; } .stripe i:nth-child(2) { background: #d82028; } .stripe i:nth-child(3) { background: #009048; }
  .body { padding: 28px; }
  img { width: 64px; height: auto; }
  h1 { font-size: 22px; margin: 16px 0 8px; }
  p { color: #6b5f61; line-height: 1.5; margin: 0 0 20px; }
  a { display: inline-block; background: #901820; color: #fff; text-decoration: none; padding: 12px 20px; border-radius: 12px; font-weight: 600; }
</style>
</head>
<body>
<main>
  <div class="stripe" aria-hidden="true"><i></i><i></i><i></i></div>
  <div class="body">
    <img src="/go/mbc-mark.png" alt="MBC" width="240" height="225">
    <h1><?= $link ? 'This link has ended' : 'Link not found' ?></h1>
    <p><?= $link ? 'This QR code was for something that is no longer running.' : 'We couldn\'t find that link. It may have been mistyped.' ?></p>
    <a href="<?= h($fallback) ?>">Go to MBC</a>
  </div>
</main>
</body>
</html>
