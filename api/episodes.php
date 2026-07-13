<?php
// Shared show/episode cache, keyed by IMDB ids.
// GET  ?show_id=ttNNNNNNN
//      -> {show, episodes: [{id, imdb_id, season, number, name, airdate}], watched: [...], today}
// POST {show_id: "ttNNNNNNN"}
//      -> imports/refreshes the show SERVER-SIDE from TMDB/TVmaze, then
//         returns the same payload as GET. No provider data is accepted
//         from the client.
require_once __DIR__ . '/../includes/importer.php';
require_login_json();

function cache_payload(string $imdbId): array
{
    $stmt = db()->prepare(
        'SELECT imdb_id, name, image_url, backdrop_url, status, overview, premiered,
                genres, network, rating, runtime, synced_at
         FROM shows WHERE imdb_id = ?'
    );
    $stmt->execute([$imdbId]);
    $show = $stmt->fetch() ?: null;

    $stmt = db()->prepare(
        'SELECT id, imdb_id, season, number, name, airdate, airstamp FROM episodes
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

    // 'today'/'now' are the server's clock — the client uses them for
    // aired-ness so the UI can never disagree with the API.
    return [
        'show' => $show,
        'episodes' => $episodes,
        'watched' => $watched,
        'today' => today(),
        'now' => gmdate('Y-m-d H:i:s'), // UTC, same format as episodes.airstamp
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $imdbId = $_GET['show_id'] ?? '';
    if (!valid_imdb_id($imdbId)) {
        json_response(['error' => 'Missing or invalid show_id'], 400);
    }
    json_response(cache_payload($imdbId));
}

$data = read_json_post();
$imdbId = $data['show_id'] ?? '';
if (!valid_imdb_id($imdbId)) {
    json_response(['error' => 'Missing or invalid show_id'], 400);
}

// Skip the (expensive, ~10+ sequential provider calls) full re-import when the
// show was synced recently, unless the client explicitly asks to force it (the
// "Refresh episodes" button). Mirrors the synced_at guard in track.php.
const EPISODES_REFRESH_COOLDOWN_HOURS = 12;
$force = !empty($data['force']);
// Compare in SQL (like tick.php) so the cooldown isn't skewed by the difference
// between the DB session timezone and the per-viewer PHP timezone.
$stmt = db()->prepare(
    'SELECT synced_at IS NOT NULL AND synced_at > (NOW() - INTERVAL ? HOUR)
     FROM shows WHERE imdb_id = ?'
);
$stmt->execute([EPISODES_REFRESH_COOLDOWN_HOURS, $imdbId]);
$fresh = (bool) $stmt->fetchColumn();

if ($force || !$fresh) {
    try {
        import_show(db(), $imdbId);
    } catch (RuntimeException $e) {
        json_response(['error' => $e->getMessage()], 404);
    }
}

json_response(cache_payload($imdbId));
