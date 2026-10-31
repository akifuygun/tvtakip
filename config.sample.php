<?php
// Copy this file to config.php and fill in your credentials.
// InfinityFree values are shown in the control panel under "MySQL Databases".

define('DB_HOST', 'localhost');        // e.g. sql123.infinityfree.com
define('DB_NAME', 'tvtakip');          // e.g. epiz_12345678_tvtakip
define('DB_USER', 'root');             // e.g. epiz_12345678
define('DB_PASS', '');

// TMDB API key (v3) — https://www.themoviedb.org/settings/api
define('TMDB_API_KEY', '');

// TheTVDB API key (v4) — https://thetvdb.com/dashboard/account/apikey
// Fills air times TVmaze lacks (streaming drops). Leave empty to skip TheTVDB.
define('TVDB_API_KEY', '');

// Comma-separated emails that get admin controls (e.g. removing posters).
define('ADMIN_EMAILS', '');

// Optional, admins only: per-episode "Watch" badges, one per site, in this
// order; the key is the badge label. Empty array = no badges.
//   url    — episode link template: {slug} = show slug, {season} / {episode} = numbers
//   finale — optional: the same for Turkish productions' season/series finales
//   and    — "&" in titles spelled "and" (true, default) or dropped (false)
//   remake — a show sharing its name with an older cached one:
//            'year'  = slug + "-YYYY" (its start year)
//            'clash' = URL + "-2" where the older one has the same episode
//   tv     — its badge on the TV view (/tv): 'open' (default) opens the link,
//            'alert' shows the link's URL in a dialog instead
define('WATCH_SITES', [
    // 'EX' => [
    //     'url' => 'https://example.com/{slug}-{season}x{episode}',
    //     'and' => false,
    //     'remake' => 'clash',
    //     'tv' => 'alert',
    // ],
]);
