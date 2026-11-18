<?php
// Backfill episodes.name_tr (Turkish episode names from TMDB) for the shows
// imported before the column existed: one TMDB call per stored season, far
// lighter than re-importing every show. Only episodes TMDB was never asked
// about (name_tr IS NULL) are touched, so it can be re-run at any time and
// picks up where a stopped run left off.
// Usage: php scripts/backfill_names_tr.php

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../includes/importer.php';

// The cron worker may be importing at the same moment: this takes a small
// share of TMDB's rate limit, so the two together stay under it.
const BACKFILL_RPS = TMDB_MAX_RPS / 5;

@set_time_limit(0);

$shows = db()->query(
    'SELECT s.imdb_id, s.name FROM shows s
     WHERE EXISTS (SELECT 1 FROM episodes e WHERE e.show_imdb_id = s.imdb_id AND e.name_tr IS NULL)
     ORDER BY s.imdb_id'
)->fetchAll();
echo count($shows) . " shows with episodes never asked about\n";

$n = 0;
$totals = ['named' => 0, 'none' => 0];
foreach ($shows as $show) {
    $imdbId = $show['imdb_id'];
    $label = '[' . ++$n . '/' . count($shows) . "] {$show['name']} ($imdbId)";

    $found = http_get_json(tmdb_api_url("/find/$imdbId", ['external_source' => 'imdb_id']));
    if ($found === null) {
        echo "$label: TMDB unreachable\n";
        continue;
    }
    $tmdbId = (int) ($found['tv_results'][0]['id'] ?? 0);
    if (!$tmdbId) {
        echo "$label: not on TMDB\n";
        continue;
    }

    // Shows numbered by TheTVDB: only an import can tell which of TMDB's
    // regular episodes are the same ones, so just their specials are filled.
    $pdo = db_live();
    $stmt = $pdo->prepare(
        'SELECT season, number, name FROM episodes WHERE show_imdb_id = ? AND name_tr IS NULL'
        . (in_array($imdbId, TVDB_EPISODE_SOURCE, true) ? ' AND season = 0' : '')
    );
    $stmt->execute([$imdbId]);
    $episodes = $stmt->fetchAll();
    $seasons = array_values(array_unique(array_map('intval', array_column($episodes, 'season'))));
    $fetched = http_multi_json(
        array_map(fn($season) => tmdb_season_url($tmdbId, $season, 'tr-TR'), $seasons),
        6,
        BACKFILL_RPS
    );
    $turkish = tmdb_turkish_names($fetched);

    $pdo = db_live();
    $update = $pdo->prepare(
        'UPDATE episodes SET name_tr = ?
         WHERE show_imdb_id = ? AND season = ? AND number = ? AND name_tr IS NULL'
    );
    $named = $none = 0;
    $pdo->beginTransaction();
    foreach ($episodes as $ep) {
        $key = $ep['season'] . '-' . $ep['number'];
        if (!isset($turkish[$key])) {
            continue; // not on TMDB under this number, or its season's fetch failed
        }
        $name = turkish_episode_name($turkish[$key], (string) $ep['name']);
        $update->execute([$name, $imdbId, $ep['season'], $ep['number']]);
        $name !== '' ? $named++ : $none++;
    }
    $pdo->commit();

    $failed = count(array_filter($fetched, fn($season) => $season === null));
    echo "$label: $named Turkish names, $none without"
        . ($failed ? ", $failed of " . count($seasons) . ' seasons not fetched' : '') . "\n";
    $totals['named'] += $named;
    $totals['none'] += $none;
}
echo "DONE: {$totals['named']} Turkish names, {$totals['none']} episodes without one\n";
