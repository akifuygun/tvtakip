<?php
// Populate the cache with TMDB's most popular TV shows of all time
// (sorted by vote count — the stable "everyone knows these" ranking).
// Ensures the DB contains at least N synced shows from that ranking;
// already-imported shows count toward the target and are skipped.
//
// Usage: php scripts/import_popular.php [target=500]

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../includes/importer.php';

$target = max(1, (int) ($argv[1] ?? 500));
$pdo = db();

$synced = $pdo->prepare('SELECT synced_at FROM shows WHERE imdb_id = ?');
$seen = [];
$done = 0;
$imported = 0;
$skipped = 0;
$failed = [];
$page = 1;

while ($done < $target && $page <= 50) {
    $list = http_get_json(tmdb_api_url('/discover/tv', [
        'sort_by' => 'vote_count.desc',
        'page' => $page,
    ]));
    $results = $list['results'] ?? [];
    if (!$results) {
        echo "No more results at page $page.\n";
        break;
    }

    foreach ($results as $tv) {
        if ($done >= $target) {
            break;
        }
        $ext = http_get_json(tmdb_api_url('/tv/' . $tv['id'] . '/external_ids'));
        $imdbId = $ext['imdb_id'] ?? null;
        if (!valid_imdb_id($imdbId) || isset($seen[$imdbId])) {
            continue;
        }
        $seen[$imdbId] = true;

        $synced->execute([$imdbId]);
        $row = $synced->fetch();
        if ($row && $row['synced_at'] !== null) {
            $done++;
            $skipped++;
            continue;
        }

        try {
            $count = import_show($pdo, $imdbId, PHP_INT_MAX);
            $done++;
            $imported++;
            echo "[$done/$target] {$tv['name']} ($imdbId): $count episodes\n";
        } catch (Throwable $e) {
            $failed[] = $imdbId;
            echo "[$done/$target] {$tv['name']} ($imdbId) FAILED: {$e->getMessage()}\n";
        }
    }
    $page++;
}

echo "\nDONE. ensured=$done (newly imported=$imported, already present=$skipped)\n";
if ($failed) {
    echo 'Failed: ' . implode(', ', $failed) . "\n";
}
