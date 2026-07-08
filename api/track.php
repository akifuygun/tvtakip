<?php
// POST {action: "track"|"untrack", show: {imdb_id, name?, image_url?, status?}}
require_once __DIR__ . '/../includes/auth.php';
require_login_json();
$data = read_json_post();

$action = $data['action'] ?? '';
$show = $data['show'] ?? [];
$imdbId = $show['imdb_id'] ?? '';

if (!valid_imdb_id($imdbId)) {
    json_response(['error' => 'Missing or invalid IMDB id'], 400);
}

if ($action === 'track') {
    $name = trim((string) ($show['name'] ?? ''));
    if ($name === '') {
        json_response(['error' => 'Missing show name'], 400);
    }
    $imageUrl = mb_substr((string) ($show['image_url'] ?? ''), 0, 500) ?: null;
    $status = normalize_show_status($show['status'] ?? null);

    // Upsert the shared show cache row, then link it to the user.
    $stmt = db()->prepare(
        'INSERT INTO shows (imdb_id, name, image_url, status)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE name = VALUES(name),
             image_url = COALESCE(VALUES(image_url), image_url),
             status = COALESCE(VALUES(status), status)'
    );
    $stmt->execute([$imdbId, mb_substr($name, 0, 255), $imageUrl, $status]);

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
