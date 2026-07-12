<?php
// Legacy URL — the show page is now unified at /series/ttNNN (series.php),
// interactive for logged-in users and read-only for guests. Redirect old
// /show.php?id= links and bookmarks there.
require_once __DIR__ . '/includes/auth.php';

$showId = $_GET['id'] ?? '';
header('Location: ' . (valid_imdb_id($showId) ? series_url($showId) : '/'), true, 301);
exit;
