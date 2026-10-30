<?php
// POST {imdb_id: "ttNNNNNNN", slug: "bir-baskadir"}
// Set the show slug its Watch links use (admin-only, like the links), for
// shows the site lists under another title. An empty slug — or the one the
// title gives anyway — clears it. Imports never touch shows.watch_slug.
require_once __DIR__ . '/../includes/auth.php';
require_login_json();

if (!watch_enabled()) {
    json_response(['error' => 'Admins only'], 403);
}

$data = read_json_post();
$imdbId = $data['imdb_id'] ?? '';
$slug = trim((string) ($data['slug'] ?? ''));

if (!valid_imdb_id($imdbId)) {
    json_response(['error' => 'Invalid show id'], 400);
}
if ($slug !== '' && (strlen($slug) > 255 || !preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug))) {
    json_response(['error' => t('watch_slug_invalid')], 400);
}

$stmt = db()->prepare('SELECT name, original_name, original_language, premiered FROM shows WHERE imdb_id = ?');
$stmt->execute([$imdbId]);
$show = $stmt->fetch();
if (!$show) {
    json_response(['error' => 'Show not found'], 404);
}

$derived = watch_slug($show + ['watch_slug' => null]);
$slug = $slug === '' || $slug === $derived ? null : $slug;
db()->prepare('UPDATE shows SET watch_slug = ? WHERE imdb_id = ?')->execute([$slug, $imdbId]);

json_response(['ok' => true, 'watch_slug' => $slug ?? $derived]);
