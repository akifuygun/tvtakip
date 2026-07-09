<?php
// POST {imdb_id: "ttNNNNNNN", image_url: "https://..."}
// Set a show's poster (shared cache). Used when a provider gave us no image.
require_once __DIR__ . '/../includes/auth.php';
require_login_json();

// Posters are a shared resource — only admins may set or remove them.
if (!is_admin()) {
    json_response(['error' => 'Admins only'], 403);
}

$data = read_json_post();
$imdbId = $data['imdb_id'] ?? '';
$url = trim((string) ($data['image_url'] ?? ''));

if (!valid_imdb_id($imdbId)) {
    json_response(['error' => 'Invalid show id'], 400);
}

// Remove a show's poster.
if (!empty($data['remove'])) {
    $stmt = db()->prepare('UPDATE shows SET image_url = NULL WHERE imdb_id = ?');
    $stmt->execute([$imdbId]);
    json_response(['ok' => true, 'removed' => true]);
}

if ($url === '' || mb_strlen($url) > 500) {
    json_response(['error' => 'Image URL required (max 500 characters).'], 400);
}
// Only accept real http(s) URLs — blocks javascript:/data: and junk.
$scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
    json_response(['error' => 'Enter a valid http(s) image URL.'], 400);
}

$stmt = db()->prepare('UPDATE shows SET image_url = ? WHERE imdb_id = ?');
$stmt->execute([$url, $imdbId]);

if ($stmt->rowCount() === 0) {
    // No row changed — either unchanged value or the show isn't cached yet.
    $chk = db()->prepare('SELECT 1 FROM shows WHERE imdb_id = ?');
    $chk->execute([$imdbId]);
    if (!$chk->fetch()) {
        json_response(['error' => 'Track the show first, then add an image.'], 404);
    }
}

json_response(['ok' => true, 'image_url' => $url]);
