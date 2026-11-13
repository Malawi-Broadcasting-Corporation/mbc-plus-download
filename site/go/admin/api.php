<?php
/** JSON API for the admin tool. Every call needs a signed-in user; changes also need the CSRF token. */
define('MBC_GO', 1);
require dirname(__DIR__) . '/inc/bootstrap.php';
go_session_start();

$action = (string) ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];

if (!go_user()) go_json(['error' => 'Please sign in again.'], 401);

if ($method === 'POST' && !hash_equals($_SESSION['csrf'], (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    go_json(['error' => 'Your session expired. Reload the page.'], 403);
}
if (!empty(go_current()['mustChange']) && $action !== 'logout') {
    go_json(['error' => 'Choose your own password first. Reload the page.'], 403);
}
if (strpos($action, 'user') === 0 && !go_is_admin()) {
    go_json(['error' => 'Only admins can manage the team.'], 403);
}

/** Change one user under lock, with the "never lock everyone out" rules. Returns the updated record. */
function go_change_user(string $name, callable $change): array
{
    return go_locked(function () use ($name, $change) {
        $users = go_read_users();
        if (!isset($users[$name])) go_json(['error' => 'That person no longer exists.'], 404);
        $users[$name] = $change($users[$name]);
        if (go_active_admins($users) < 1) go_json(['error' => 'There must always be at least one active admin.'], 422);
        go_write_users($users);
        return $users[$name];
    });
}

function go_public_user(string $name, array $u): array
{
    return [
        'username' => $name, 'role' => $u['role'], 'active' => !empty($u['active']), 'mustChange' => !empty($u['mustChange']),
        'created' => $u['created'] ?? null, 'createdBy' => $u['createdBy'] ?? null, 'lastLogin' => $u['lastLogin'] ?? null,
    ];
}

try {
    switch ("$method $action") {
        case 'GET list':
            go_json(['links' => array_values(go_read_links()), 'stats' => go_stats(), 'base_url' => go_config()['base_url']]);

        case 'POST save':
            $slug = go_normalize_slug((string) ($body['slug'] ?? ''));
            $isNew = !empty($body['new']);
            $title = trim((string) ($body['title'] ?? ''));
            $url = trim((string) ($body['url'] ?? ''));
            $ios = trim((string) ($body['ios'] ?? ''));
            $android = trim((string) ($body['android'] ?? ''));
            $notes = trim((string) ($body['notes'] ?? ''));

            if ($title === '') go_json(['error' => 'Give the QR code a name.'], 422);
            if (!go_valid_slug($slug)) go_json(['error' => 'Short link can use letters, numbers and dashes only (and not "' . $slug . '").'], 422);
            if (!go_valid_url($url)) go_json(['error' => 'Destination must be a full web address starting with https://'], 422);
            foreach (['iPhone' => $ios, 'Android' => $android] as $label => $u) {
                if ($u !== '' && !go_valid_url($u)) go_json(['error' => "$label link must be a full web address starting with https://"], 422);
            }

            $saved = go_locked(function () use ($slug, $isNew, $title, $url, $ios, $android, $notes) {
                $links = go_read_links();
                if ($isNew && isset($links[$slug])) return null;
                if (!$isNew && !isset($links[$slug])) return false;
                $now = gmdate('Y-m-d\TH:i:s\Z');
                $prev = $links[$slug] ?? ['slug' => $slug, 'active' => true, 'created' => $now, 'createdBy' => go_user()];
                $links[$slug] = array_merge($prev, [
                    'title' => go_cut($title, 120), 'url' => $url, 'ios' => $ios, 'android' => $android,
                    'notes' => go_cut($notes, 500), 'updated' => $now, 'updatedBy' => go_user(),
                ]);
                go_write_links($links);
                return $links[$slug];
            });
            if ($saved === null) go_json(['error' => "The short link \"$slug\" is already taken. Pick another."], 409);
            if ($saved === false) go_json(['error' => 'That QR code no longer exists.'], 404);
            go_json(['link' => $saved]);

        case 'POST active':
            $slug = go_normalize_slug((string) ($body['slug'] ?? ''));
            $active = !empty($body['active']);
            $saved = go_locked(function () use ($slug, $active) {
                $links = go_read_links();
                if (!isset($links[$slug])) return null;
                $links[$slug]['active'] = $active;
                $links[$slug]['updated'] = gmdate('Y-m-d\TH:i:s\Z');
                $links[$slug]['updatedBy'] = go_user();
                go_write_links($links);
                return $links[$slug];
            });
            if (!$saved) go_json(['error' => 'That QR code no longer exists.'], 404);
            go_json(['link' => $saved]);

        case 'POST delete':
            $slug = go_normalize_slug((string) ($body['slug'] ?? ''));
            go_locked(function () use ($slug) {
                $links = go_read_links();
                unset($links[$slug]);
                go_write_links($links);
            });
            go_json(['ok' => true]);

        // ---- team (admins only, checked above) ----
        case 'GET users':
            $out = [];
            foreach (go_read_users() as $name => $u) $out[] = go_public_user($name, $u);
            go_json(['users' => $out, 'me' => go_user()]);

        case 'POST user-add':
            $name = strtolower(trim((string) ($body['username'] ?? '')));
            $role = (string) ($body['role'] ?? 'editor');
            if (!go_valid_username($name)) go_json(['error' => 'Username: 2–32 lowercase letters, numbers, dots or dashes (e.g. "chikondi.b").'], 422);
            if (!in_array($role, GO_ROLES, true)) go_json(['error' => 'Unknown role.'], 422);
            $temp = go_temp_password();
            $added = go_locked(function () use ($name, $role, $temp) {
                $users = go_read_users();
                if (isset($users[$name])) return false;
                $users[$name] = [
                    'hash' => password_hash($temp, PASSWORD_DEFAULT), 'role' => $role, 'active' => true, 'mustChange' => true,
                    'pwv' => 1, 'created' => gmdate('Y-m-d\TH:i:s\Z'), 'createdBy' => go_user(), 'lastLogin' => null,
                ];
                go_write_users($users);
                return true;
            });
            if (!$added) go_json(['error' => "The username \"$name\" is already taken."], 409);
            go_json(['username' => $name, 'password' => $temp]);

        case 'POST user-update':
            $name = (string) ($body['username'] ?? '');
            if ($name === go_user()) go_json(['error' => 'You can\'t change your own role or access. Ask another admin.'], 422);
            $u = go_change_user($name, function ($u) use ($body) {
                if (isset($body['role'])) {
                    if (!in_array($body['role'], GO_ROLES, true)) go_json(['error' => 'Unknown role.'], 422);
                    $u['role'] = $body['role'];
                }
                if (isset($body['active'])) $u['active'] = (bool) $body['active'];
                return $u;
            });
            go_json(['user' => go_public_user($name, $u)]);

        case 'POST user-reset':
            $name = (string) ($body['username'] ?? '');
            if ($name === go_user()) go_json(['error' => 'Use "My password" to change your own.'], 422);
            $temp = go_temp_password();
            go_change_user($name, function ($u) use ($temp) {
                $u['hash'] = password_hash($temp, PASSWORD_DEFAULT);
                $u['mustChange'] = true;
                $u['pwv'] = (int) ($u['pwv'] ?? 0) + 1; // signs them out everywhere
                return $u;
            });
            go_json(['username' => $name, 'password' => $temp]);

        case 'POST user-delete':
            $name = (string) ($body['username'] ?? '');
            if ($name === go_user()) go_json(['error' => 'You can\'t delete yourself.'], 422);
            go_locked(function () use ($name) {
                $users = go_read_users();
                unset($users[$name]);
                if (go_active_admins($users) < 1) go_json(['error' => 'There must always be at least one active admin.'], 422);
                go_write_users($users);
            });
            go_json(['ok' => true]);

        case 'POST logout':
            $_SESSION = [];
            session_destroy();
            go_json(['ok' => true]);

        default:
            go_json(['error' => 'Unknown request.'], 400);
    }
} catch (Throwable $e) {
    go_json(['error' => $e->getMessage()], 500);
}
