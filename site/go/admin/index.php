<?php
define('MBC_GO', 1);
require dirname(__DIR__) . '/inc/bootstrap.php';
go_session_start();
header('Cache-Control: no-store');

$cfg = go_config();
$hasUsers = (bool) go_read_users();
$me = go_current();
$error = '';
$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$csrfOk = $post && hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''));

if (!$hasUsers) $mode = 'setup';
elseif (!$me) $mode = 'login';
elseif (!empty($me['mustChange']) || isset($_GET['password'])) $mode = 'password';
else $mode = 'app';

if ($post && !$csrfOk) {
    $error = 'The form expired. Please try again.';
} elseif ($post && $mode === 'setup') {
    // First admin. Needs the setup code from data/config.php so a stranger can't claim the tool.
    $name = strtolower(trim((string) ($_POST['user'] ?? '')));
    $p = (string) ($_POST['pass'] ?? '');
    $code = (string) $cfg['setup_code'];
    if (go_login_blocked()) $error = 'Too many attempts. Wait 15 minutes and try again.';
    elseif ($code === '' || !hash_equals($code, trim((string) ($_POST['code'] ?? '')))) { go_login_failed(); $error = 'That setup code is not right.'; }
    elseif (!go_valid_username($name)) $error = 'Username: 2–32 lowercase letters, numbers, dots or dashes.';
    elseif ($e = go_password_problem($p)) $error = $e;
    elseif ($p !== (string) ($_POST['pass2'] ?? '')) $error = 'The two passwords don\'t match.';
    else {
        $created = go_locked(function () use ($name, $p) {
            if (go_read_users()) return null; // someone finished setup first
            $u = ['hash' => password_hash($p, PASSWORD_DEFAULT), 'role' => 'admin', 'active' => true, 'mustChange' => false,
                  'pwv' => 1, 'created' => gmdate('Y-m-d\TH:i:s\Z'), 'createdBy' => 'setup', 'lastLogin' => gmdate('Y-m-d\TH:i:s\Z')];
            go_write_users([$name => $u]);
            return $u;
        });
        if ($created) go_sign_in($name, $created);
        header('Location: ./', true, 303);
        exit;
    }
} elseif ($post && $mode === 'login') {
    if (go_login_blocked()) {
        $error = 'Too many attempts. Wait 15 minutes and try again.';
    } elseif (go_try_login((string) ($_POST['user'] ?? ''), (string) ($_POST['pass'] ?? ''))) {
        header('Location: ./', true, 303);
        exit;
    } else {
        $error = 'Wrong username or password.';
    }
} elseif ($post && $mode === 'password') {
    $forced = !empty($me['mustChange']);
    $p = (string) ($_POST['pass'] ?? '');
    if (!$forced && !password_verify((string) ($_POST['current'] ?? ''), $me['hash'])) $error = 'Your current password is not right.';
    elseif ($e = go_password_problem($p)) $error = $e;
    elseif ($p !== (string) ($_POST['pass2'] ?? '')) $error = 'The two passwords don\'t match.';
    elseif ($forced && password_verify($p, $me['hash'])) $error = 'Choose a new password, not the temporary one.';
    else {
        $name = $me['username'];
        $u = go_locked(function () use ($name, $p) {
            $users = go_read_users();
            $users[$name]['hash'] = password_hash($p, PASSWORD_DEFAULT);
            $users[$name]['mustChange'] = false;
            $users[$name]['pwv'] = (int) ($users[$name]['pwv'] ?? 0) + 1; // signs out other devices
            go_write_users($users);
            return $users[$name];
        });
        go_sign_in($name, $u);
        header('Location: ./?saved', true, 303);
        exit;
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
<link rel="stylesheet" href="app.css?v=2">
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
        <label>Username <input name="user" autocomplete="username" autocapitalize="off" required autofocus></label>
        <label>Password <input name="pass" type="password" autocomplete="current-password" required></label>
        <button class="btn primary">Sign in</button>
      </form>
      <p class="muted small">Forgot your password? Ask an admin on your team to reset it.</p>

    <?php elseif ($mode === 'setup'): ?>
      <h1>Set up</h1>
      <?php if ($cfg['setup_code'] === ''): ?>
        <p class="muted">Before anyone can use this tool, whoever manages the mbc.mw server (cPanel) needs to create
          <code>go/data/config.php</code> from <code>config.sample.php</code> and set a <code>setup_code</code> in it.</p>
      <?php else: ?>
        <p class="muted">Create the first admin account. You'll need the setup code from the server config.
          After this, add everyone else from the <b>Team</b> page.</p>
        <?php if ($error): ?><p class="alert" role="alert"><?= h($error) ?></p><?php endif; ?>
        <form method="post" class="stack">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <label>Setup code <input name="code" required autocomplete="off"></label>
          <label>Your username <span class="hint">e.g. chikondi.b</span>
            <input name="user" required autocomplete="username" autocapitalize="off" value="<?= h($_POST['user'] ?? '') ?>"></label>
          <label>Password <span class="hint">At least 10 characters</span>
            <input name="pass" type="password" minlength="10" required autocomplete="new-password"></label>
          <label>Password again <input name="pass2" type="password" minlength="10" required autocomplete="new-password"></label>
          <button class="btn primary">Create admin account</button>
        </form>
      <?php endif; ?>

    <?php else: /* password */ ?>
      <?php $forced = !empty($me['mustChange']); ?>
      <h1><?= $forced ? 'Choose your password' : 'Change password' ?></h1>
      <p class="muted"><?= $forced
          ? 'Welcome, ' . h($me['username']) . '. You signed in with a temporary password. Choose your own to continue.'
          : 'This signs you out on your other devices.' ?></p>
      <?php if ($error): ?><p class="alert" role="alert"><?= h($error) ?></p><?php endif; ?>
      <form method="post" class="stack">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="user" value="<?= h($me['username']) ?>" autocomplete="username">
        <?php if (!$forced): ?>
          <label>Current password <input name="current" type="password" required autocomplete="current-password"></label>
        <?php endif; ?>
        <label>New password <span class="hint">At least 10 characters</span>
          <input name="pass" type="password" minlength="10" required autocomplete="new-password" autofocus></label>
        <label>New password again <input name="pass2" type="password" minlength="10" required autocomplete="new-password"></label>
        <button class="btn primary">Save password</button>
      </form>
      <p class="row">
        <?php if (!$forced): ?><a class="btn" href="./">Cancel</a><?php endif; ?>
        <button class="link" id="logout">Sign out</button>
      </p>
    <?php endif; ?>
  </div>
</main>
<?php if ($mode === 'password'): ?>
<script>
document.getElementById("logout").addEventListener("click", function () {
  fetch("api.php?action=logout", { method: "POST", headers: { "X-CSRF-Token": document.querySelector('meta[name="csrf"]').content }, credentials: "same-origin" })
    .then(function () { location.href = "./"; });
});
</script>
<?php endif; ?>

<?php else: ?>
<header class="top">
  <div class="stripe" aria-hidden="true"><i></i><i></i><i></i></div>
  <div class="bar">
    <img src="mbc-logo.png" alt="MBC" width="320" height="137" class="toplogo">
    <nav class="tabs" aria-label="Sections">
      <a href="#codes" data-tab="codes">QR codes</a>
      <?php if (go_is_admin()): ?><a href="#team" data-tab="team">Team</a><?php endif; ?>
    </nav>
    <span class="spacer"></span>
    <span class="who"><?= h($me['username']) ?> · <?= h($me['role']) ?></span>
    <a class="link" href="?password">My password</a>
    <button class="link" id="logout">Sign out</button>
  </div>
</header>

<div class="layout" id="view-codes">
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

<?php if (go_is_admin()): ?>
<div class="layout" id="view-team" hidden>
  <section class="card" aria-labelledby="addTitle">
    <div class="pad">
      <h2 id="addTitle">Add a person</h2>
      <form id="userForm" class="stack" novalidate>
        <label>Username <span class="hint">Lowercase, e.g. "chikondi.b". They sign in with this.</span>
          <input name="username" required maxlength="32" autocapitalize="off" spellcheck="false" autocomplete="off"></label>
        <fieldset>
          <legend>Role</legend>
          <label class="check"><input type="radio" name="role" value="editor" checked> <span><b>Editor</b>: creates and manages QR codes</span></label>
          <label class="check"><input type="radio" name="role" value="admin"> <span><b>Admin</b>: also manages the team</span></label>
        </fieldset>
        <p class="alert" id="userError" role="alert" hidden></p>
        <button class="btn primary">Add person</button>
      </form>
      <p class="muted small">They get a temporary password to sign in once, then choose their own.</p>
    </div>
  </section>
  <section aria-labelledby="teamTitle">
    <div class="listhead"><h2 id="teamTitle">Team</h2></div>
    <div id="team" class="list" aria-live="polite"><p class="muted">Loading…</p></div>
  </section>
</div>

<dialog id="pwDialog" aria-labelledby="pwTitle">
  <div class="dhead"><h2 id="pwTitle">Temporary password</h2><button class="link" data-close aria-label="Close">✕</button></div>
  <div class="pad stack">
    <p id="pwIntro"></p>
    <div class="secret"><code id="pwValue"></code><button class="btn" id="pwCopy">Copy</button></div>
    <p class="muted small">Send it to them privately (in person or a direct message). It works once: they'll choose their own password when they sign in. It won't be shown again.</p>
    <button class="btn primary" data-close>Done</button>
  </div>
</dialog>
<?php endif; ?>

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
<script src="app.js?v=2"></script>
<?php if (isset($_GET['saved'])): ?><script>history.replaceState(null, "", "./"); document.getElementById("toast").textContent = "Password saved"; document.getElementById("toast").hidden = false; setTimeout(function () { document.getElementById("toast").hidden = true; }, 2600);</script><?php endif; ?>
<?php endif; ?>
</body>
</html>
