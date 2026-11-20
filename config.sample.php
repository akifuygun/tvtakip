<?php
// Copy this file to config.php, here beside public/ (the web root — never into
// it), and fill in your credentials.
// Production (hostUNO) values: `devil mysql db list` or the DevilWEB panel.

define('DB_HOST', 'localhost');        // e.g. mysql2.hostuno.com
define('DB_NAME', 'tvtakip');          // e.g. m1234_tvtakip
define('DB_USER', 'root');             // e.g. m1234
define('DB_PASS', '');

// TMDB API key (v3) — https://www.themoviedb.org/settings/api
define('TMDB_API_KEY', '');

// TheTVDB API key (v4) — https://thetvdb.com/dashboard/account/apikey
// Fills air times TVmaze lacks (streaming drops). Leave empty to skip TheTVDB.
define('TVDB_API_KEY', '');

// The site's private directory, for the session files, the cached TheTVDB
// login token and PHP's error log (php-error.log): outside the web root,
// readable by the site's own account only (created with mode 700 when missing;
// PHP's open_basedir must include it).
// '' = PHP's own session.save_path and error log, and the system's temp
// directory — fine on a development machine, not on a shared server, where
// that is a temp directory every account can list, session ids included, and
// an error log the site's account may not get to read.
define('SESSION_DIR', '');             // e.g. /usr/home/<login>/domains/<domain>/sessions

// Optional, admins only (an admin is an account given the role with
// `php scripts/admin.php add <email>`): per-episode "Watch" badges, one per site, in this
// order; the key is the badge label. Empty array = no badges.
//   url    — episode link template: {slug} = show slug, {season} / {episode} = numbers
//   finale — optional: the same for Turkish productions' season/series finales
//   and    — "&" in titles spelled "and" (true, default) or dropped (false)
//   remake — a show sharing its name with an older cached one:
//            'year'  = slug + "-YYYY" (its start year)
//            'clash' = URL + "-2" where the older one has the same episode
define('WATCH_SITES', [
    // 'EX' => [
    //     'url' => 'https://example.com/{slug}-{season}x{episode}',
    //     'and' => false,
    //     'remake' => 'clash',
    // ],
]);
