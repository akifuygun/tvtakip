<?php
// Shared show/episode cache, keyed by IMDB ids.
// GET  ?show_id=ttNNNNNNN
//      -> {show: {...}|null, episodes: [{id, imdb_id, season, number, name, airdate}], watched: [episodeId,...]}
// POST {show: {imdb_id, name, image_url?, status?, overview?, premiered?},
//       episodes: [{imdb_id?, season, number, name?, airdate?}, ...]}
//      -> upserts the cache (called by the first browser that fetches a show from TMDB)
require_once __DIR__ . '/../includes/auth.php';
require_login_json();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $imdbId = $_GET['show_id'] ?? '';
    if (!valid_imdb_id($imdbId)) {
        json_response(['error' => 'Missing or invalid show_id'], 400);
    }

    $stmt = db()->prepare('SELECT imdb_id, name, image_url, status, overview, premiered, synced_at FROM shows WHERE imdb_id = ?');
    $stmt->execute([$imdbId]);
    $show = $stmt->fetch() ?: null;

    $stmt = db()->prepare(
        'SELECT id, imdb_id, season, number, name, airdate FROM episodes
         WHERE show_imdb_id = ? ORDER BY season, number'
    );
    $stmt->execute([$imdbId]);
    $episodes = $stmt->fetchAll();

    $stmt = db()->prepare(
        'SELECT we.episode_id FROM watched_episodes we
         JOIN episodes e ON e.id = we.episode_id
         WHERE we.user_id = ? AND e.show_imdb_id = ?'
    );
    $stmt->execute([current_user_id(), $imdbId]);
    $watched = array_map('intval', array_column($stmt->fetchAll(), 'episode_id'));

    json_response(['show' => $show, 'episodes' => $episodes, 'watched' => $watched]);
}

$data = read_json_post();
$show = $data['show'] ?? [];
$imdbId = $show['imdb_id'] ?? '';
$episodes = $data['episodes'] ?? [];

if (!valid_imdb_id($imdbId)) {
    json_response(['error' => 'Missing or invalid show imdb_id'], 400);
}
$name = trim((string) ($show['name'] ?? ''));
if ($name === '') {
    json_response(['error' => 'Missing show name'], 400);
}
if (!is_array($episodes) || count($episodes) > 3000) {
    json_response(['error' => 'Invalid episodes payload'], 400);
}

$date = static function ($value): ?string {
    return (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) ? $value : null;
};

db()->beginTransaction();

$stmt = db()->prepare(
    'INSERT INTO shows (imdb_id, name, image_url, status, overview, premiered, synced_at)
     VALUES (?, ?, ?, ?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE name = VALUES(name),
         image_url = COALESCE(VALUES(image_url), image_url),
         status = COALESCE(VALUES(status), status),
         overview = COALESCE(VALUES(overview), overview),
         premiered = COALESCE(VALUES(premiered), premiered),
         synced_at = NOW()'
);
$stmt->execute([
    $imdbId,
    substr($name, 0, 255),
    substr((string) ($show['image_url'] ?? ''), 0, 500) ?: null,
    normalize_show_status($show['status'] ?? null),
    trim((string) ($show['overview'] ?? '')) ?: null,
    $date($show['premiered'] ?? null),
]);

$stmt = db()->prepare(
    'INSERT INTO episodes (show_imdb_id, imdb_id, season, number, name, airdate)
     VALUES (?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE imdb_id = COALESCE(VALUES(imdb_id), imdb_id),
         name = VALUES(name), airdate = VALUES(airdate)'
);
foreach ($episodes as $ep) {
    if (!is_array($ep)) {
        continue;
    }
    $epImdb = $ep['imdb_id'] ?? null;
    $stmt->execute([
        $imdbId,
        valid_imdb_id($epImdb) ? $epImdb : null,
        max(0, (int) ($ep['season'] ?? 0)),
        max(0, (int) ($ep['number'] ?? 0)),
        substr(trim((string) ($ep['name'] ?? '')), 0, 255) ?: null,
        $date($ep['airdate'] ?? null),
    ]);
}

db()->commit();
json_response(['ok' => true, 'count' => count($episodes)]);
