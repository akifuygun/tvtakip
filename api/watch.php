<?php
// GET  ?show_id=N                 -> {watched: [episodeId, ...]}
// POST {show_id, episode: {id, season, number}, watched: bool}
// POST {show_id, episodes: [{id, season, number}, ...]}  -> mark all watched
require_once __DIR__ . '/../includes/auth.php';
require_login_json();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $showId = (int) ($_GET['show_id'] ?? 0);
    if ($showId <= 0) {
        json_response(['error' => 'Missing show_id'], 400);
    }
    $stmt = db()->prepare('SELECT tvmaze_episode_id FROM watched_episodes WHERE user_id = ? AND tvmaze_show_id = ?');
    $stmt->execute([current_user_id(), $showId]);
    json_response(['watched' => array_map('intval', array_column($stmt->fetchAll(), 'tvmaze_episode_id'))]);
}

$data = read_json_post();
$showId = (int) ($data['show_id'] ?? 0);

// Bulk: mark a whole list of episodes as watched in one request.
if (isset($data['episodes'])) {
    if ($showId <= 0 || !is_array($data['episodes']) || !$data['episodes']) {
        json_response(['error' => 'Missing show_id or episodes'], 400);
    }
    if (count($data['episodes']) > 2000) {
        json_response(['error' => 'Too many episodes'], 400);
    }
    $stmt = db()->prepare(
        'INSERT IGNORE INTO watched_episodes (user_id, tvmaze_show_id, tvmaze_episode_id, season, episode)
         VALUES (?, ?, ?, ?, ?)'
    );
    db()->beginTransaction();
    foreach ($data['episodes'] as $ep) {
        $episodeId = (int) (is_array($ep) ? ($ep['id'] ?? 0) : 0);
        if ($episodeId <= 0) {
            continue;
        }
        $stmt->execute([
            current_user_id(),
            $showId,
            $episodeId,
            max(0, (int) ($ep['season'] ?? 0)),
            max(0, (int) ($ep['number'] ?? 0)),
        ]);
    }
    db()->commit();
    json_response(['ok' => true]);
}

$episode = $data['episode'] ?? [];
$episodeId = (int) ($episode['id'] ?? 0);
$watched = (bool) ($data['watched'] ?? false);

if ($showId <= 0 || $episodeId <= 0) {
    json_response(['error' => 'Missing show_id or episode id'], 400);
}

if ($watched) {
    $stmt = db()->prepare(
        'INSERT IGNORE INTO watched_episodes (user_id, tvmaze_show_id, tvmaze_episode_id, season, episode)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        current_user_id(),
        $showId,
        $episodeId,
        max(0, (int) ($episode['season'] ?? 0)),
        max(0, (int) ($episode['number'] ?? 0)),
    ]);
} else {
    $stmt = db()->prepare('DELETE FROM watched_episodes WHERE user_id = ? AND tvmaze_episode_id = ?');
    $stmt->execute([current_user_id(), $episodeId]);
}

json_response(['ok' => true, 'watched' => $watched]);
