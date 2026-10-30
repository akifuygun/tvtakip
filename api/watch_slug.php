<?php
// POST {imdb_id: "ttNNNNNNN", site: "DB", slug: "bir-baskadir"}
// Set the show slug one Watch site's links use (admin-only, like the links),
// for shows that site lists under another title. An empty slug — or the one
// the title gives anyway — clears it. Stored in shows.watch_slug as JSON
// {site: slug}; imports never touch it.
require_once __DIR__ . '/../includes/auth.php';
require_login_json();

if (!watch_enabled()) {
    json_response(['error' => 'Admins only'], 403);
}

$data = read_json_post();
$imdbId = $data['imdb_id'] ?? '';
$site = (string) ($data['site'] ?? '');
$slug = trim((string) ($data['slug'] ?? ''));

if (!valid_imdb_id($imdbId) || !isset(watch_sites()[$site])) {
    json_response(['error' => 'Invalid show or site'], 400);
}
if ($slug !== '' && (strlen($slug) > 100 || !preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug))) {
    json_response(['error' => t('watch_slug_invalid')], 400);
}

$stmt = db()->prepare(
    'SELECT name, original_name, original_language, premiered, watch_slug FROM shows WHERE imdb_id = ?'
);
$stmt->execute([$imdbId]);
$show = $stmt->fetch();
if (!$show) {
    json_response(['error' => 'Show not found'], 404);
}

$overrides = watch_slug_overrides($show['watch_slug']);
$derived = watch_slug(['watch_slug' => null] + $show, $site);
if ($slug === '' || $slug === $derived) {
    unset($overrides[$site]);
} else {
    $overrides[$site] = $slug;
}
$overrides = array_intersect_key($overrides, watch_sites());
$stored = $overrides ? json_encode($overrides) : null;
if ($stored !== null && strlen($stored) > 255) { // shows.watch_slug is VARCHAR(255)
    json_response(['error' => t('watch_slug_invalid')], 400);
}
db()->prepare('UPDATE shows SET watch_slug = ? WHERE imdb_id = ?')->execute([$stored, $imdbId]);

json_response(['ok' => true, 'slug' => $overrides[$site] ?? $derived]);
