<?php
// Router for PHP's built-in dev server — mirrors the .htaccess rewrites so
// pretty URLs work locally too. Production (Apache) never uses this file.
// Usage: php -S localhost:8000 router.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (preg_match('#^/series/(tt\d{6,10})/?$#', $path, $m)) {
    $_GET['id'] = $m[1];
    require __DIR__ . '/series.php';
    return true;
}
if (preg_match('#^/series(/|$)#', $path)) {
    // Non-matching /series/* — mirror Apache, which 404s (no path-walking
    // fallback to index.php like the built-in server would do).
    http_response_code(404);
    echo 'Not Found';
    return true;
}
if (preg_match('#^/browse/?$#', $path)) {
    require __DIR__ . '/browse.php';
    return true;
}
if (preg_match('#^/upcoming/?$#', $path)) {
    require __DIR__ . '/upcoming.php';
    return true;
}
if ($path === '/sitemap.xml') {
    require __DIR__ . '/sitemap.php';
    return true;
}

return false; // serve the requested file/script as-is
