<?php
// Router for PHP's built-in dev server — mirrors the rewrites of
// public/.htaccess so pretty URLs (including the /tr/ language prefix) work
// locally too. Production never uses this file.
// Usage, from this directory: php -S localhost:8000 -t public router.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// The response headers .htaccess sets, so a browser applies the same limits
// to what PHP answers here (the built-in server sends static files itself,
// without them) — all but Strict-Transport-Security, which is for HTTPS.
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");

if (preg_match('#^(?:/tr)?/series/(tt\d{6,10}|(?:tm|mz)\d{1,9})/?$#', $path, $m)) {
    $_GET['id'] = $m[1];
    require __DIR__ . '/public/series.php';
    return true;
}
if (preg_match('#^(?:/tr)?/series(/|$)#', $path)) {
    // Non-matching /series/* — mirror Apache, which 404s (no path-walking
    // fallback to index.php like the built-in server would do).
    http_response_code(404);
    echo 'Not Found';
    return true;
}
if (preg_match('#^(?:/tr)?/movie/(tt\d{6,10}|tm\d{1,9})/?$#', $path, $m)) {
    $_GET['id'] = $m[1];
    require __DIR__ . '/public/movie.php';
    return true;
}
if (preg_match('#^(?:/tr)?/movies/?$#', $path)) {
    require __DIR__ . '/public/movies.php';
    return true;
}
if (preg_match('#^(?:/tr)?/movie(/|$)#', $path)) {
    http_response_code(404);
    echo 'Not Found';
    return true;
}
if (preg_match('#^(?:/tr)?/browse/?$#', $path)) {
    require __DIR__ . '/public/browse.php';
    return true;
}
if (preg_match('#^(?:/tr)?/upcoming/?$#', $path)) {
    require __DIR__ . '/public/upcoming.php';
    return true;
}
if (preg_match('#^/tr/?$#', $path)) {
    require __DIR__ . '/public/index.php';
    return true;
}
if ($path === '/sitemap.xml') {
    require __DIR__ . '/public/sitemap.php';
    return true;
}
if (preg_match('#^/tv/?$#', $path)) {
    require __DIR__ . '/public/tv.php';
    return true;
}

return false; // serve the requested file/script of public/ as-is
