<?php
// Copy this file to config.php and fill in your credentials.
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
