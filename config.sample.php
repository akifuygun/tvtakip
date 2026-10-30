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

// Optional, admins only: per-episode "Watch" link template. {slug} = show name
// slug, {season} / {episode} = numbers. Leave empty for no button.
define('WATCH_URL', '');   // e.g. https://example.com/{slug}-{season}-sezon-{episode}-bolum-izle/
