<?php
define('MBC_GO', 1);
require dirname(__DIR__) . '/inc/bootstrap.php';
go_session_start();
header('Cache-Control: no-store');

$cfg = go_config();
$hasUsers = !empty($cfg['users']);
$error = '';
$hashLine = '';
$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$csrfOk = $post && hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''));

// Password-hash helper: open to anyone until the first user exists, then only to signed-in users.
$mode = !$hasUsers ? 'setup' : (go_user() ? (isset($_GET['teammate']) ? 'setup' : 'app') : 'login');

if ($post && !$csrfOk) {
    $error = 'The form expired. Please try again.';
} elseif ($post && $mode === 'setup') {
    $u = go_normalize_slug((string) ($_POST['user'] ?? ''));
    $p = (string) ($_POST['pass'] ?? '');
    if ($u === '') $error = 'Choose a username (letters and numbers).';
    elseif (strlen($p) < 10) $error = 'Use a password of at least 10 characters.';
    elseif ($p !== (string) ($_POST['pass2'] ?? '')) $error = 'The two passwords don\'t match.';
    else $hashLine = "'" . $u . "' => '" . password_hash($p, PASSWORD_DEFAULT) . "',";
} elseif ($post && $mode === 'login') {
    if (go_login_blocked()) {
        $error = 'Too many attempts. Wait 15 minutes and try again.';
    } elseif (go_try_login(trim((string) ($_POST['user'] ?? '')), (string) ($_POST['pass'] ?? ''))) {
        header('Location: ./', true, 303);
        exit;
    } else {
        $error = 'Wrong username or password.';
    }
}
$csrf = $_SESSION['csrf'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf" content="<?= h($csrf) ?>">
<title>MBC QR Codes</title>
<link rel="icon" href="favicon.png" type="image/png">
<link rel="stylesheet" href="app.css?v=1">
</head>
<body>
<?php if ($mode !== 'app'): ?>
<main class="card narrow">
  <div class="stripe" aria-hidden="true"><i></i><i></i><i></i></div>
  <div class="pad">
    <img class="logo" src="mbc-logo.png" alt="MBC" width="320" height="137">
    <?php if ($mode === 'login'): ?>
      <h1>QR codes</h1>
      <p class="muted">Sign in to create and manage MBC QR codes.</p>
      <?php if ($error): ?><p class="alert" role="alert"><?= h($error) ?></p><?php endif; ?>
      <form method="post" class="stack">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <label>Username <input name="user" autocomplete="username" required autofocus></label>
        <label>Password <input name="pass" type="password" autocomplete="current-password" required></label>
        <button class="btn primary">Sign in</button>
      </form>
    <?php elseif ($hashLine): ?>
      <h1>Almost done</h1>
      <p class="muted">Ask whoever manages the mbc.mw server (cPanel) to add this line to <code>go/data/config.php</code>, inside <code>'users'</code>:</p>
      <textarea class="code" readonly rows="4" onclick="this.select()"><?= h($hashLine) ?></textarea>
      <p class="muted small">This is a scrambled version of the password, not the password itself, so it's safe to send.</p>
      <a class="btn" href="./">Back</a>
    <?php else: ?>
      <h1><?= $hasUsers ? 'Add a teammate' : 'Set up' ?></h1>
      <p class="muted"><?= $hasUsers
          ? 'Choose a username and password for a new teammate. You\'ll get a line to add to the server config.'
          : 'No one can sign in yet. Choose a username and password, then add the line you get to the server config.' ?></p>
      <?php if ($error): ?><p class="alert" role="alert"><?= h($error) ?></p><?php endif; ?>
      <form method="post" class="stack">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <label>Username <input name="user" required autocomplete="off"></label>
        <label>Password <input name="pass" type="password" minlength="10" required autocomplete="new-password"></label>
        <label>Password again <input name="pass2" type="password" minlength="10" required autocomplete="new-password"></label>
        <button class="btn primary">Create</button>
      </form>
      <?php if ($hasUsers): ?><p><a href="./">Back to QR codes</a></p><?php endif; ?>
    <?php endif; ?>
  </div>
</main>
<?php else: ?>
<header class="top">
  <div class="stripe" aria-hidden="true"><i></i><i></i><i></i></div>
  <div class="bar">
    <img src="mbc-logo.png" alt="MBC" width="320" height="137" class="toplogo">
    <strong class="apptitle">QR codes</strong>
    <span class="spacer"></span>
    <span class="who"><?= h(go_user()) ?></span>
    <a class="link" href="?teammate">Add teammate</a>
    <button class="link" id="logout">Sign out</button>
  </div>
</header>

<div class="layout">
  <section class="card" aria-labelledby="formTitle">
    <div class="pad">
      <h2 id="formTitle">New QR code</h2>
      <form id="form" class="stack" novalidate>
        <label>Name <span class="hint">Only you see this, e.g. "Gala 2026 – table cards"</span>
          <input name="title" required maxlength="120"></label>
        <label>Short link <span class="hint">What's inside the QR. Can't be changed once printed.</span>
          <span class="slug"><span id="base"></span><input name="slug" required maxlength="60" pattern="[a-z0-9-]+" autocapitalize="off" spellcheck="false"></span></label>
        <label>Sends people to <span class="hint">Any web page: event page, map, YouTube, Facebook, form…</span>
          <input name="url" type="url" required placeholder="https://"></label>
        <details>
          <summary>Different link for iPhone / Android (optional)</summary>
          <div class="stack">
            <label>iPhone &amp; iPad <input name="ios" type="url" placeholder="https://apps.apple.com/…"></label>
            <label>Android <input name="android" type="url" placeholder="https://play.google.com/…"></label>
          </div>
        </details>
        <label>Notes <span class="hint">Optional: where it's printed, who asked for it</span>
          <textarea name="notes" rows="2" maxlength="500"></textarea></label>
        <p class="alert" id="formError" role="alert" hidden></p>
        <div class="row">
          <button class="btn primary" id="saveBtn">Create QR code</button>
          <button class="btn" type="button" id="cancelEdit" hidden>Cancel</button>
        </div>
      </form>
    </div>
  </section>

  <section aria-labelledby="listTitle">
    <div class="listhead">
      <h2 id="listTitle">Your QR codes</h2>
      <input type="search" id="search" placeholder="Search" aria-label="Search QR codes">
    </div>
    <div id="list" class="list" aria-live="polite"><p class="muted">Loading…</p></div>
  </section>
</div>

<dialog id="qrDialog" aria-labelledby="qrTitle">
  <div class="dhead">
    <h2 id="qrTitle"></h2>
    <button class="link" data-close aria-label="Close">✕</button>
  </div>
  <div class="dbody">
    <div class="qrbox"><div id="qrPreview"></div><p class="qrurl" id="qrUrl"></p></div>
    <div class="stack">
      <fieldset>
        <legend>Colour</legend>
        <div class="swatches" id="swatches"></div>
      </fieldset>
      <label class="check"><input type="checkbox" id="withLogo" checked> MBC logo in the middle</label>
      <div class="stack tight">
        <button class="btn primary" id="dlSvg">Download for print (SVG)</button>
        <button class="btn" id="dlPng">Download for screens (PNG)</button>
        <button class="btn" id="copyLink">Copy short link</button>
      </div>
      <p class="muted small">Print at least 2.5&nbsp;cm wide, keep the white border, and test-scan with an iPhone and an Android phone before printing in bulk.</p>
    </div>
  </div>
</dialog>

<div id="toast" class="toast" role="status" hidden></div>
<script src="qrcode.js?v=1"></script>
<script src="app.js?v=1"></script>
<?php endif; ?>
</body>
</html>
