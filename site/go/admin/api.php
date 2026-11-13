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
