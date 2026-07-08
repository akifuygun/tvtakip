<?php
// POST {action: "track"|"untrack", imdb_id: "ttNNNNNNN"}
// The client sends only the id — show metadata comes from the providers,
// imported server-side when the cache doesn't have the show yet.
require_once __DIR__ . '/../includes/importer.php';
require_login_json();
$data = read_json_post();

$action = $data['action'] ?? '';
$imdbId = $data['imdb_id'] ?? ($data['show']['imdb_id'] ?? ''); // legacy shape tolerated

if (!valid_imdb_id($imdbId)) {
    json_response(['error' => 'Missing or invalid IMDB id'], 400);
}

if ($action === 'track') {
    $stmt = db()->prepare('SELECT synced_at FROM shows WHERE imdb_id = ?');
    $stmt->execute([$imdbId]);
    $show = $stmt->fetch();
    if (!$show || $show['synced_at'] === null) {
        try {
            import_show(db(), $imdbId);
        } catch (RuntimeException $e) {
            json_response(['error' => $e->getMessage()], 404);
        }
    }

    $stmt = db()->prepare('INSERT IGNORE INTO user_shows (user_id, show_imdb_id) VALUES (?, ?)');
    $stmt->execute([current_user_id(), $imdbId]);
    json_response(['ok' => true, 'tracked' => true]);
}

if ($action === 'untrack') {
    // Watched history is intentionally kept — re-tracking picks it back up.
    $stmt = db()->prepare('DELETE FROM user_shows WHERE user_id = ? AND show_imdb_id = ?');
    $stmt->execute([current_user_id(), $imdbId]);
    json_response(['ok' => true, 'tracked' => false]);
}

json_response(['error' => 'Unknown action'], 400);
