<?php
// POST {action: "track"|"untrack", show: {id, name, image_url?, status?}}
require_once __DIR__ . '/../includes/auth.php';
require_login_json();
$data = read_json_post();

$action = $data['action'] ?? '';
$show = $data['show'] ?? [];
$showId = (int) ($show['id'] ?? 0);

if ($showId <= 0) {
    json_response(['error' => 'Missing show id'], 400);
}

if ($action === 'track') {
    $name = trim((string) ($show['name'] ?? ''));
    if ($name === '') {
        json_response(['error' => 'Missing show name'], 400);
    }
    $imageUrl = substr((string) ($show['image_url'] ?? ''), 0, 500) ?: null;
    $status = substr((string) ($show['status'] ?? ''), 0, 50) ?: null;

    $stmt = db()->prepare(
        'INSERT INTO user_shows (user_id, tvmaze_id, name, image_url, status)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE name = VALUES(name), image_url = VALUES(image_url), status = VALUES(status)'
    );
    $stmt->execute([current_user_id(), $showId, substr($name, 0, 255), $imageUrl, $status]);
    json_response(['ok' => true, 'tracked' => true]);
}

if ($action === 'untrack') {
    $stmt = db()->prepare('DELETE FROM user_shows WHERE user_id = ? AND tvmaze_id = ?');
    $stmt->execute([current_user_id(), $showId]);
    // Also clear watched history for that show.
    $stmt = db()->prepare('DELETE FROM watched_episodes WHERE user_id = ? AND tvmaze_show_id = ?');
    $stmt->execute([current_user_id(), $showId]);
    json_response(['ok' => true, 'tracked' => false]);
}

json_response(['error' => 'Unknown action'], 400);
