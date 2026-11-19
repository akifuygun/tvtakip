<?php
// The version on the CSS/JS URLs (?v=…) — what makes their one-year browser
// caching (public/.htaccess) safe. Nothing else happens in this file:
// scripts/deploy.php loads it as well, to work out the version of a commit.

// The web root, from the checkout's root: the one directory the web server
// serves. A file's path below it is its URL.
const PUBLIC_DIR = 'public';

// Every CSS/JS file a page loads, by its path in PUBLIC_DIR. They share one version.
const VERSIONED_ASSETS = ['assets/css/style.css', 'assets/css/tv.css', 'assets/js/app.js', 'assets/js/tv.js'];

/**
 * The version of the CSS/JS as they are on disk: a hash of their contents,
 * taken when a page is rendered (about 0.1 ms). It changes when one of the
 * files does and only then, so there is nothing to bump. The service worker's
 * cache is named after it too: app.js registers /sw.js?v=<version>.
 */
function asset_version(): string
{
    static $version = null;
    return $version ??= assets_hash(fn (string $path): string => (string) file_get_contents(dirname(__DIR__) . '/' . PUBLIC_DIR . "/$path"));
}

/** The version of the VERSIONED_ASSETS as $contents returns them, asked by path. */
function assets_hash(callable $contents): string
{
    $hash = hash_init('xxh3');
    foreach (VERSIONED_ASSETS as $path) {
        hash_update($hash, $contents($path));
    }
    return substr(hash_final($hash), 0, 10);
}
