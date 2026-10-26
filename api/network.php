<?php
// POST {imdb_id: "ttNNNNNNN", network: "tabii"}
// Set a show's primary network (shared cache): the poster badge and the first
// network listed, e.g. the Turkish distributor TMDB doesn't know about. An
// empty network clears it. Imports never touch shows.primary_network.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/network_logos.php';
require_login_json();

if (!is_admin()) {
    json_response(['error' => 'Admins only'], 403);
}

$data = read_json_post();
$imdbId = $data['imdb_id'] ?? '';
$network = trim((string) ($data['network'] ?? ''));

if (!valid_imdb_id($imdbId)) {
    json_response(['error' => 'Invalid show id'], 400);
}
if (mb_strlen($network) > 100 || str_contains($network, ',')) {
    json_response(['error' => t('primary_network_invalid')], 400);
}

$stmt = db()->prepare('SELECT network FROM shows WHERE imdb_id = ?');
$stmt->execute([$imdbId]);
$show = $stmt->fetch();
if (!$show) {
    json_response(['error' => 'Show not found'], 404);
}

$network = $network === '' ? null : canonical_network_name($network, network_names($show['network']));
db()->prepare('UPDATE shows SET primary_network = ? WHERE imdb_id = ?')->execute([$network, $imdbId]);

json_response(['ok' => true, 'primary_network' => $network, 'has_logo' => $network !== null && network_logo_or_group($network) !== null]);
