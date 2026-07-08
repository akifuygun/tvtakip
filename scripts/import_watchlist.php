<?php
// One-off CLI importer: wipes all data, creates a user, imports a Trakt-style
// watchlist JSON ([{imdb_id: "tt...", type: "show"}, ...]) via the shared
// server-side importer (TMDB first, TVmaze fallback), and marks every
// already-aired episode watched.
//
// Usage:
//   php scripts/import_watchlist.php watchlist.json email "Display Name" password

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../includes/importer.php';

[$self, $jsonPath, $email, $displayName, $password] = $argv + [null, null, null, null, null];
if (!$jsonPath || !$email || !$displayName || !$password) {
    exit("Usage: php import_watchlist.php <watchlist.json> <email> <name> <password>\n");
}

$items = json_decode(file_get_contents($jsonPath), true);
if (!is_array($items)) {
    exit("Could not parse $jsonPath\n");
}

$ids = [];
$skipped = [];
foreach ($items as $item) {
    if (valid_imdb_id($item['imdb_id'] ?? null)) {
        $ids[$item['imdb_id']] = true;
    } else {
        $skipped[] = json_encode($item);
    }
}
$ids = array_keys($ids);
echo count($ids) . " unique IMDB ids, " . count($skipped) . " entries without IMDB id\n";

$pdo = db();
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['watched_episodes', 'user_shows', 'episodes', 'shows', 'users'] as $table) {
    $pdo->exec("TRUNCATE TABLE $table");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
echo "All tables truncated.\n";

$stmt = $pdo->prepare('INSERT INTO users (email, display_name, password_hash) VALUES (?, ?, ?)');
$stmt->execute([$email, $displayName, password_hash($password, PASSWORD_DEFAULT)]);
$userId = (int) $pdo->lastInsertId();
echo "Created user #$userId $email\n";

$insertTrack = $pdo->prepare('INSERT IGNORE INTO user_shows (user_id, show_imdb_id) VALUES (?, ?)');
$insertWatched = $pdo->prepare(
    'INSERT IGNORE INTO watched_episodes (user_id, episode_id)
     SELECT ?, id FROM episodes
     WHERE show_imdb_id = ? AND airdate IS NOT NULL AND airdate <= ?'
);

$failed = [];
$totalEpisodes = 0;
$n = 0;

foreach ($ids as $imdbId) {
    $n++;
    try {
        $count = import_show($pdo, $imdbId, PHP_INT_MAX); // CLI: backfill all ids in one run
    } catch (RuntimeException $e) {
        $failed[] = $imdbId;
        echo "[$n/" . count($ids) . "] $imdbId FAILED: {$e->getMessage()}\n";
        continue;
    }
    $insertTrack->execute([$userId, $imdbId]);
    $insertWatched->execute([$userId, $imdbId, today()]);
    $totalEpisodes += $count;

    $name = $pdo->query("SELECT name FROM shows WHERE imdb_id = " . $pdo->quote($imdbId))->fetchColumn();
    echo "[$n/" . count($ids) . "] $name ($imdbId): $count episodes\n";
}

echo "\nDONE. Shows imported: " . (count($ids) - count($failed)) . ", episodes: $totalEpisodes\n";
if ($failed) {
    echo "Not found on either provider: " . implode(', ', $failed) . "\n";
}
if ($skipped) {
    echo "Skipped (no IMDB id in JSON): " . implode(' | ', $skipped) . "\n";
}
