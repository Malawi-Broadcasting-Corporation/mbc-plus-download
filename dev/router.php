<?php
/**
 * Local dev server that mimics the .htaccess rules on mbc.mw.
 *   php -S localhost:8080 -t site dev/router.php
 * Then open http://localhost:8080/go/admin/
 */
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$site = dirname(__DIR__) . '/site';

if (preg_match('#^/go/(data|inc)(/|$)#i', $path)) {          // "Require all denied"
    http_response_code(403);
    exit('Forbidden');
}
if ($path === '/go/admin') {                                   // mod_dir adds the slash
    header('Location: /go/admin/', true, 301);
    exit;
}
if (preg_match('#^/go/([A-Za-z0-9-]+)/?$#', $path, $m) && !file_exists($site . $path)) {
    $_GET['s'] = $m[1];                                        // RewriteRule -> index.php?s=
    chdir($site . '/go');
    require $site . '/go/index.php';
    return true;
}
return false;                                                  // serve the file as-is
