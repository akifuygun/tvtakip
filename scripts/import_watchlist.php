<?php
// One-off CLI importer: wipes all data, creates a user, imports a Trakt-style
// watchlist JSON ([{imdb_id: "tt...", type: "show"}, ...]) with full episode
// data from TMDB, and marks every already-aired episode watched.
//
// Usage:
//   php scripts/import_watchlist.php watchlist.json email "Display Name" password

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../includes/auth.php'; // db(), normalize_show_status(), config

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

// ---- TMDB helpers -------------------------------------------------------

function tmdb_url(string $path, array $q = []): string
{
    $q['api_key'] = TMDB_API_KEY;
    return 'https://api.themoviedb.org/3' . $path . '?' . http_build_query($q);
}

/** Single GET with retries; returns decoded JSON or null. */
function tmdb_get(string $path, array $q = []): ?array
{
    $url = tmdb_url($path, $q);
    for ($try = 0; $try < 3; $try++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body !== false && $code === 200) {
            return json_decode($body, true);
        }
        usleep($code === 429 ? 1500000 : 400000);
    }
    return null;
}

/** Concurrent GETs (order-preserving); null for any URL that failed. */
function tmdb_multi(array $urls, int $limit = 12): array
{
    $results = array_fill(0, count($urls), null);
    if (!$urls) {
        return $results;
    }
    $mh = curl_multi_init();
    $active = [];
    $next = 0;
    $add = function () use (&$next, $urls, $mh, &$active) {
        if ($next >= count($urls)) {
            return;
        }
        $i = $next++;
        $ch = curl_init($urls[$i]);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        curl_multi_add_handle($mh, $ch);
        $active[(int) $ch] = $i;
    };
    for ($i = 0; $i < $limit; $i++) {
        $add();
    }
    do {
        curl_multi_exec($mh, $running);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $i = $active[(int) $ch];
            $body = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $results[$i] = ($code === 200 && $body) ? json_decode($body, true) : null;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($active[(int) $ch]);
            $add();
        }
        if ($running) {
            curl_multi_select($mh, 0.2);
        }
    } while ($running || $active);
    curl_multi_close($mh);
    return $results;
}

// ---- Wipe and create user -----------------------------------------------

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

// ---- Import each show ----------------------------------------------------

$insertShow = $pdo->prepare(
    'INSERT INTO shows (imdb_id, name, image_url, status, overview, premiered, synced_at)
     VALUES (?, ?, ?, ?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE name = VALUES(name), synced_at = NOW()'
);
$insertEpisode = $pdo->prepare(
    'INSERT INTO episodes (show_imdb_id, imdb_id, season, number, name, airdate)
     VALUES (?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE imdb_id = VALUES(imdb_id), name = VALUES(name), airdate = VALUES(airdate)'
);
$insertTrack = $pdo->prepare('INSERT IGNORE INTO user_shows (user_id, show_imdb_id) VALUES (?, ?)');
$insertWatched = $pdo->prepare(
    'INSERT IGNORE INTO watched_episodes (user_id, episode_id)
     SELECT ?, id FROM episodes
     WHERE show_imdb_id = ? AND airdate IS NOT NULL AND airdate <= ?'
);

$date = static fn($v) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null;

$notFound = [];
$totalEpisodes = 0;
$totalMissingEpImdb = 0;
$n = 0;

foreach ($ids as $imdbId) {
    $n++;
    $find = tmdb_get("/find/$imdbId", ['external_source' => 'imdb_id']);
    $tv = $find['tv_results'][0] ?? null;
    if (!$tv) {
        $notFound[] = $imdbId;
        echo "[$n/" . count($ids) . "] $imdbId NOT FOUND on TMDB\n";
        continue;
    }
    $detail = tmdb_get('/tv/' . $tv['id']);
    if (!$detail) {
        $notFound[] = $imdbId;
        echo "[$n/" . count($ids) . "] $imdbId detail fetch failed\n";
        continue;
    }

    $seasonUrls = array_map(
        fn($s) => tmdb_url('/tv/' . $tv['id'] . '/season/' . $s['season_number']),
        $detail['seasons'] ?? []
    );
    $episodes = [];
    foreach (tmdb_multi($seasonUrls, 6) as $season) {
        foreach ($season['episodes'] ?? [] as $ep) {
            $episodes[] = $ep;
        }
    }

    $extUrls = array_map(
        fn($ep) => tmdb_url('/tv/' . $tv['id'] . '/season/' . $ep['season_number'] . '/episode/' . $ep['episode_number'] . '/external_ids'),
        $episodes
    );
    $exts = tmdb_multi($extUrls, 12);

    $pdo->beginTransaction();
    $insertShow->execute([
        $imdbId,
        mb_substr($detail['name'] ?? $imdbId, 0, 255),
        !empty($detail['poster_path']) ? 'https://image.tmdb.org/t/p/w342' . $detail['poster_path'] : null,
        normalize_show_status($detail['status'] ?? null),
        $detail['overview'] ?: null,
        $date($detail['first_air_date'] ?? null),
    ]);
    $missing = 0;
    foreach ($episodes as $i => $ep) {
        $epImdb = $exts[$i]['imdb_id'] ?? null;
        if (!valid_imdb_id($epImdb)) {
            $epImdb = null;
            $missing++;
        }
        $insertEpisode->execute([
            $imdbId,
            $epImdb,
            max(0, (int) ($ep['season_number'] ?? 0)),
            max(0, (int) ($ep['episode_number'] ?? 0)),
            mb_substr(trim((string) ($ep['name'] ?? '')), 0, 255) ?: null,
            $date($ep['air_date'] ?? null),
        ]);
    }
    $insertTrack->execute([$userId, $imdbId]);
    $insertWatched->execute([$userId, $imdbId, today()]);
    $pdo->commit();

    $totalEpisodes += count($episodes);
    $totalMissingEpImdb += $missing;
    echo "[$n/" . count($ids) . "] {$detail['name']} ($imdbId): " . count($episodes) . " episodes"
        . ($missing ? ", $missing without episode IMDB id" : '') . "\n";
}

echo "\nDONE. Shows imported: " . (count($ids) - count($notFound)) . ", episodes: $totalEpisodes"
    . " ($totalMissingEpImdb without IMDB id)\n";
if ($notFound) {
    echo "Not found on TMDB: " . implode(', ', $notFound) . "\n";
}
if ($skipped) {
    echo "Skipped (no IMDB id in JSON): " . implode(' | ', $skipped) . "\n";
}
