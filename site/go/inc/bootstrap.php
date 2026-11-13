<?php
/**
 * Shared code for the MBC short-link / QR system.
 * Works on PHP 7.4+ with no database: everything lives in ../data/.
 */
if (!defined('MBC_GO')) { http_response_code(404); exit; }

define('GO_DATA', dirname(__DIR__) . '/data');
define('GO_LINKS', GO_DATA . '/links.json');
define('GO_SCANS', GO_DATA . '/scans.log');
define('GO_CONFIG', GO_DATA . '/config.php');
define('GO_LOCK', GO_DATA . '/.lock');
define('GO_ATTEMPTS', GO_DATA . '/login-attempts.json');
define('GO_USERS', GO_DATA . '/users.json');

// Names that can't be used as short links (folders / files in /go/).
const GO_RESERVED = ['admin', 'data', 'inc', 'index', 'favicon', 'mbc-mark', 'robots'];

function go_config(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $cfg = is_file(GO_CONFIG) ? (array) (include GO_CONFIG) : [];
    $cfg += ['base_url' => '', 'setup_code' => '', 'fallback_url' => 'https://mbc.mw/'];
    if ($cfg['base_url'] === '') {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $cfg['base_url'] = ($https ? 'https' : 'http') . '://' . $host . '/go/';
    }
    $cfg['base_url'] = rtrim($cfg['base_url'], '/') . '/';
    return $cfg;
}

/** Run $fn while holding an exclusive lock, so two people saving at once can't lose data. */
function go_locked(callable $fn)
{
    $h = fopen(GO_LOCK, 'c');
    if (!$h || !flock($h, LOCK_EX)) throw new RuntimeException('Could not lock data folder. Is data/ writable?');
    try { return $fn(); } finally { flock($h, LOCK_UN); fclose($h); }
}

function go_read_links(): array
{
    if (!is_file(GO_LINKS)) return [];
    $data = json_decode((string) file_get_contents(GO_LINKS), true);
    return is_array($data['links'] ?? null) ? $data['links'] : [];
}

function go_write_links(array $links): void
{
    ksort($links);
    $json = json_encode(['links' => $links], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $tmp = GO_LINKS . '.tmp';
    if (file_put_contents($tmp, $json) === false || !rename($tmp, GO_LINKS)) {
        throw new RuntimeException('Could not save links. Is data/ writable?');
    }
}

function go_normalize_slug(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim((string) $s, '-');
}

function go_valid_slug(string $s): bool
{
    return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]{0,58}[a-z0-9])?$/', $s) && !in_array($s, GO_RESERVED, true);
}

function go_valid_url(string $u): bool
{
    return (bool) filter_var($u, FILTER_VALIDATE_URL) && (bool) preg_match('#^https?://#i', $u);
}

/** 'i' = iPhone/iPad, 'a' = Android, 'o' = other. */
function go_device(string $ua): string
{
    if (preg_match('/iPhone|iPad|iPod/i', $ua)) return 'i';
    if (preg_match('/Android/i', $ua)) return 'a';
    return 'o';
}

function go_is_bot(string $ua): bool
{
    return $ua === '' || (bool) preg_match('/bot|crawl|spider|preview|facebookexternalhit|WhatsApp|Slack|Telegram|Discord|curl|wget|python|monitor/i', $ua);
}

function go_log_scan(string $slug, string $device): void
{
    @file_put_contents(GO_SCANS, gmdate('Y-m-d\TH:i:s\Z') . "\t$slug\t$device\n", FILE_APPEND | LOCK_EX);
}

/** Totals per short link: all time, last 7 days, by device, last scan, and daily counts for 30 days. */
function go_stats(): array
{
    $stats = [];
    if (!is_file(GO_SCANS)) return $stats;
    $week = gmdate('Y-m-d', time() - 6 * 86400);
    $month = gmdate('Y-m-d', time() - 29 * 86400);
    $h = fopen(GO_SCANS, 'r');
    if (!$h) return $stats;
    while (($line = fgets($h)) !== false) {
        $p = explode("\t", rtrim($line, "\n"));
        if (count($p) < 3) continue;
        [$ts, $slug, $dev] = $p;
        $s = &$stats[$slug];
        if ($s === null) $s = ['total' => 0, 'week' => 0, 'i' => 0, 'a' => 0, 'o' => 0, 'last' => null, 'days' => []];
        $s['total']++;
        if (isset($s[$dev])) $s[$dev]++;
        $day = substr($ts, 0, 10);
        if ($day >= $week) $s['week']++;
        if ($day >= $month) $s['days'][$day] = ($s['days'][$day] ?? 0) + 1;
        $s['last'] = $ts;
        unset($s);
    }
    fclose($h);
    return $stats;
}

function go_json($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

// ---- Admin session -------------------------------------------------------

function go_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('mbcgo');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/go/admin/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict',
    ]);
    session_start();
    // sign out after 8 idle hours
    if (isset($_SESSION['user'], $_SESSION['seen']) && time() - $_SESSION['seen'] > 8 * 3600) {
        $_SESSION = [];
    }
    if (isset($_SESSION['user'])) $_SESSION['seen'] = time();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// ---- Users (data/users.json) ---------------------------------------------
// username => { hash, role: admin|editor, active, mustChange, pwv, created, createdBy, lastLogin }
// pwv ("password version") goes up on every password change/reset; sessions from before it are signed out.

const GO_ROLES = ['admin', 'editor'];

function go_read_users(): array
{
    if (!is_file(GO_USERS)) return [];
    $data = json_decode((string) file_get_contents(GO_USERS), true);
    return is_array($data['users'] ?? null) ? $data['users'] : [];
}

function go_write_users(array $users): void
{
    ksort($users);
    $tmp = GO_USERS . '.tmp';
    if (file_put_contents($tmp, json_encode(['users' => $users], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false || !rename($tmp, GO_USERS)) {
        throw new RuntimeException('Could not save users. Is data/ writable?');
    }
}

function go_valid_username(string $u): bool
{
    return (bool) preg_match('/^[a-z0-9][a-z0-9._-]{1,31}$/', $u);
}

function go_password_problem(string $p): ?string
{
    if (strlen($p) < 10) return 'Use a password of at least 10 characters.';
    if (strlen($p) > 200) return 'That password is too long.';
    return null;
}

/** Readable one-time password, e.g. "kemu-7tar-pq4h". */
function go_temp_password(): string
{
    $chars = 'abcdefghjkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < 12; $i++) {
        if ($i && $i % 4 === 0) $out .= '-';
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

function go_active_admins(array $users): int
{
    $n = 0;
    foreach ($users as $u) if (!empty($u['active']) && ($u['role'] ?? '') === 'admin') $n++;
    return $n;
}

/** The signed-in user (with 'username' added), or null. Re-checked on every request. */
function go_current(): ?array
{
    static $cache = false;
    if ($cache !== false) return $cache;
    $cache = null;
    $name = $_SESSION['user'] ?? null;
    if (!$name) return null;
    $u = go_read_users()[$name] ?? null;
    if (!$u || empty($u['active']) || (int) ($u['pwv'] ?? 0) !== (int) ($_SESSION['pwv'] ?? -1)) {
        $_SESSION = ['csrf' => $_SESSION['csrf'] ?? bin2hex(random_bytes(16))];
        return null;
    }
    $u['username'] = $name;
    return $cache = $u;
}

function go_user(): ?string
{
    $u = go_current();
    return $u ? $u['username'] : null;
}

function go_is_admin(): bool
{
    $u = go_current();
    return $u && $u['role'] === 'admin';
}

function go_sign_in(string $name, array $u): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = $name;
    $_SESSION['pwv'] = (int) ($u['pwv'] ?? 0);
    $_SESSION['seen'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function go_client_key(): string
{
    return hash('sha256', 'mbcgo|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
}

/** Max 5 failed sign-ins per 15 minutes from one address. */
function go_login_blocked(): bool
{
    $a = is_file(GO_ATTEMPTS) ? (json_decode((string) file_get_contents(GO_ATTEMPTS), true) ?: []) : [];
    $k = go_client_key();
    $recent = array_filter($a[$k] ?? [], function ($t) { return $t > time() - 900; });
    return count($recent) >= 5;
}

function go_login_failed(): void
{
    go_locked(function () {
        $a = is_file(GO_ATTEMPTS) ? (json_decode((string) file_get_contents(GO_ATTEMPTS), true) ?: []) : [];
        $cut = time() - 900;
        foreach ($a as $k => $list) {
            $a[$k] = array_values(array_filter($list, function ($t) use ($cut) { return $t > $cut; }));
            if (!$a[$k]) unset($a[$k]);
        }
        $a[go_client_key()][] = time();
        file_put_contents(GO_ATTEMPTS, json_encode($a));
    });
}

function go_try_login(string $user, string $pass): bool
{
    $user = strtolower(trim($user));
    $u = go_read_users()[$user] ?? null;
    $hash = $u['hash'] ?? null;
    // verify against a dummy hash for unknown users so timing doesn't reveal valid usernames
    $ok = password_verify($pass, $hash ?: '$2y$10$abcdefghijklmnopqrstuuJ4V3cZ6Qm6lZb0h6eWw2b1l7b3d1nK.');
    if ($ok && $hash && !empty($u['active'])) {
        go_locked(function () use ($user) {
            $users = go_read_users();
            if (!isset($users[$user])) return;
            $users[$user]['lastLogin'] = gmdate('Y-m-d\TH:i:s\Z');
            go_write_users($users);
        });
        go_sign_in($user, $u);
        return true;
    }
    go_login_failed();
    return false;
}

function go_cut(string $s, int $n): string
{
    return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n);
}
