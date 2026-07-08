<?php
// POST {episode_id: N, watched: bool}        -> toggle one episode (id from our episodes cache)
// POST {show_id: "ttNNNNNNN", all: true}     -> mark every cached episode of the show watched
require_once __DIR__ . '/../includes/auth.php';
require_login_json();
$data = read_json_post();

// Bulk: mark all episodes of a show as watched.
if (!empty($data['all'])) {
    $imdbId = $data['show_id'] ?? '';
    if (!valid_imdb_id($imdbId)) {
        json_response(['error' => 'Missing or invalid show_id'], 400);
    }
    $stmt = db()->prepare(
        'INSERT IGNORE INTO watched_episodes (user_id, episode_id)
         SELECT ?, id FROM episodes WHERE show_imdb_id = ?'
    );
    $stmt->execute([current_user_id(), $imdbId]);
    json_response(['ok' => true]);
}

$episodeId = (int) ($data['episode_id'] ?? 0);
$watched = (bool) ($data['watched'] ?? false);
if ($episodeId <= 0) {
    json_response(['error' => 'Missing episode_id'], 400);
}

if ($watched) {
    // Validate the episode exists in the cache before inserting.
    $stmt = db()->prepare('INSERT IGNORE INTO watched_episodes (user_id, episode_id)
                           SELECT ?, id FROM episodes WHERE id = ?');
    $stmt->execute([current_user_id(), $episodeId]);
} else {
    $stmt = db()->prepare('DELETE FROM watched_episodes WHERE user_id = ? AND episode_id = ?');
    $stmt->execute([current_user_id(), $episodeId]);
}

json_response(['ok' => true, 'watched' => $watched]);
