<?php
// Fallback importer: fill shows TMDB doesn't know from TVmaze, by IMDB id.
// TVmaze episodes carry no IMDB ids, so those stay NULL (backfillable later
// via the show page's refresh button once TMDB learns the show).
//
// Usage:
//   php scripts/fill_from_tvmaze.php user@email.com tt1234567 [tt2345678 ...]

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../includes/auth.php';

$email = $argv[1] ?? null;
$ids = array_slice($argv, 2);
if (!$email || !$ids) {
    exit("Usage: php fill_from_tvmaze.php <email> <imdb_id> [imdb_id ...]\n");
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();
if (!$user) {
    exit("No user with email $email\n");
}
$userId = (int) $user['id'];

function tvmaze_get(string $path): ?array
{
    $ch = curl_init('https://api.tvmaze.com' . $path);
    // /lookup/* answers with a 301 redirect to the actual resource.
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => true]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ($body !== false && $code === 200) ? json_decode($body, true) : null;
}

$insertShow = $pdo->prepare(
    'INSERT INTO shows (imdb_id, name, image_url, status, overview, premiered, synced_at)
     VALUES (?, ?, ?, ?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE name = VALUES(name), image_url = VALUES(image_url),
         status = VALUES(status), overview = VALUES(overview), premiered = VALUES(premiered),
         synced_at = NOW()'
);
$insertEpisode = $pdo->prepare(
    'INSERT INTO episodes (show_imdb_id, imdb_id, season, number, name, airdate)
     VALUES (?, NULL, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE name = VALUES(name), airdate = VALUES(airdate)'
);
$insertTrack = $pdo->prepare('INSERT IGNORE INTO user_shows (user_id, show_imdb_id) VALUES (?, ?)');
$insertWatched = $pdo->prepare(
    'INSERT IGNORE INTO watched_episodes (user_id, episode_id)
     SELECT ?, id FROM episodes
     WHERE show_imdb_id = ? AND airdate IS NOT NULL AND airdate <= ?'
);

$date = static fn($v) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null;
$notFound = [];

foreach ($ids as $imdbId) {
    if (!valid_imdb_id($imdbId)) {
        echo "$imdbId: invalid IMDB id, skipping\n";
        continue;
    }
    $show = tvmaze_get('/lookup/shows?imdb=' . urlencode($imdbId));
    if (!$show) {
        $notFound[] = $imdbId;
        echo "$imdbId: not found on TVmaze\n";
        continue;
    }
    $episodes = tvmaze_get('/shows/' . $show['id'] . '/episodes') ?? [];

    $pdo->beginTransaction();
    $insertShow->execute([
        $imdbId,
        mb_substr($show['name'] ?? $imdbId, 0, 255),
        $show['image']['medium'] ?? null,
        normalize_show_status($show['status'] ?? null),
        trim(strip_tags((string) ($show['summary'] ?? ''))) ?: null,
        $date($show['premiered'] ?? null),
    ]);
    foreach ($episodes as $ep) {
        $insertEpisode->execute([
            $imdbId,
            max(0, (int) ($ep['season'] ?? 0)),
            max(0, (int) ($ep['number'] ?? 0)),
            mb_substr(trim((string) ($ep['name'] ?? '')), 0, 255) ?: null,
            $date($ep['airdate'] ?? null),
        ]);
    }
    $insertTrack->execute([$userId, $imdbId]);
    $insertWatched->execute([$userId, $imdbId, today()]);
    $pdo->commit();

    echo "{$show['name']} ($imdbId): " . count($episodes) . " episodes imported from TVmaze\n";
    usleep(300000); // stay well under TVmaze rate limits
}

if ($notFound) {
    echo "\nNot found on TVmaze either: " . implode(', ', $notFound) . "\n";
}
