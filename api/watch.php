<?php
// POST {episode_id: N, watched: bool}                -> toggle one episode (id from our episodes cache)
// POST {show_id: "ttNNNNNNN", all: true}             -> mark every cached episode of the show watched
// POST {show_id: "ttNNNNNNN", all: true, season: N}  -> mark one season watched
require_once __DIR__ . '/../includes/auth.php';
require_login_json();
$data = read_json_post();

// Bulk: mark all episodes of a show as watched.
if (!empty($data['all'])) {
    $imdbId = $data['show_id'] ?? '';
    if (!valid_imdb_id($imdbId)) {
        json_response(['error' => 'Missing or invalid show_id'], 400);
    }
    // Unaired episodes (future or unknown airdate) are never markable.
    $sql = 'INSERT IGNORE INTO watched_episodes (user_id, episode_id)
            SELECT ?, id FROM episodes
            WHERE show_imdb_id = ? AND airdate IS NOT NULL AND airdate <= CURDATE()';
    $params = [current_user_id(), $imdbId];
    if (isset($data['season'])) {
        $sql .= ' AND season = ?';
        $params[] = max(0, (int) $data['season']);
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    json_response(['ok' => true]);
}

$episodeId = (int) ($data['episode_id'] ?? 0);
$watched = (bool) ($data['watched'] ?? false);
if ($episodeId <= 0) {
    json_response(['error' => 'Missing episode_id'], 400);
}

if ($watched) {
    $stmt = db()->prepare('SELECT airdate FROM episodes WHERE id = ?');
    $stmt->execute([$episodeId]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(['error' => 'Unknown episode'], 404);
    }
    if ($row['airdate'] === null || $row['airdate'] > date('Y-m-d')) {
        json_response(['error' => 'This episode has not aired yet.'], 400);
    }
    $stmt = db()->prepare('INSERT IGNORE INTO watched_episodes (user_id, episode_id) VALUES (?, ?)');
    $stmt->execute([current_user_id(), $episodeId]);
} else {
    $stmt = db()->prepare('DELETE FROM watched_episodes WHERE user_id = ? AND episode_id = ?');
    $stmt->execute([current_user_id(), $episodeId]);
}

json_response(['ok' => true, 'watched' => $watched]);
