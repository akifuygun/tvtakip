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
        'SELECT imdb_id, name, image_url, status, overview, premiered, synced_at
         FROM shows WHERE imdb_id = ?'
    );
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

    // 'today' is the server's date — the client uses it for aired-ness so the
    // UI can never disagree with the API's own clock.
    return ['show' => $show, 'episodes' => $episodes, 'watched' => $watched, 'today' => today()];
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

try {
    import_show(db(), $imdbId);
} catch (RuntimeException $e) {
    json_response(['error' => $e->getMessage()], 404);
}

json_response(cache_payload($imdbId));
