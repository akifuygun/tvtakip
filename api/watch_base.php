<?php
// POST {base: "https://www.example.com/"}
// Set the origin (scheme + host) of the admin-only Watch links, for when the
// site moves domains — the footer ❤. Paths stay WATCH_URL's. Saved to
// includes/watch_base.php (not in git); an empty base reverts to WATCH_URL's.
require_once __DIR__ . '/../includes/auth.php';
require_login_json();

if (!watch_enabled()) {
    json_response(['error' => 'Admins only'], 403);
}

$data = read_json_post();
$input = trim((string) ($data['base'] ?? ''));

if ($input === '') {
    @unlink(WATCH_BASE_FILE);
} else {
    // A bare domain is fine too; any path after the host is ignored.
    $parts = parse_url(preg_match('#^[a-z]+://#i', $input) ? $input : 'https://' . $input);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['port'])
        || !preg_match('/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $host)) {
        json_response(['error' => t('watch_base_invalid')], 400);
    }
    $tmp = WATCH_BASE_FILE . '.tmp';
    $code = "<?php\n// Written by api/watch_base.php: the Watch links' origin.\nreturn "
        . var_export("$scheme://$host/", true) . ";\n";
    if (file_put_contents($tmp, $code, LOCK_EX) === false || !rename($tmp, WATCH_BASE_FILE)) {
        json_response(['error' => 'Could not save the domain'], 500);
    }
}
if (function_exists('opcache_invalidate')) {
    @opcache_invalidate(WATCH_BASE_FILE, true);
}

json_response(['ok' => true, 'base' => watch_base()]);
