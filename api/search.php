<?php
// GET ?q=... -> {results: [{imdb_id|null, name, year, image, source}]}
// Queries TVmaze and TMDB server-side in parallel-ish and merges by IMDB id,
// so the TMDB key never reaches the browser and either provider being down
// only narrows the results.
require_once __DIR__ . '/../includes/importer.php';
require_login_json();

$q = trim((string) ($_GET['q'] ?? ''));
if ($q === '' || mb_strlen($q) > 100) {
    json_response(['error' => 'Missing or invalid q'], 400);
}

[$tvmaze, $tmdb] = http_multi_json([
    TVMAZE_BASE . '/search/shows?q=' . urlencode($q),
    tmdb_api_url('/search/tv', ['query' => $q]),
], 2);

if ($tvmaze === null && $tmdb === null) {
    json_response(['error' => 'Both search providers failed'], 502);
}

$byImdb = [];
$noId = [];

foreach ($tvmaze ?? [] as $entry) {
    $show = $entry['show'] ?? null;
    if (!$show) {
        continue;
    }
    $item = [
        'imdb_id' => valid_imdb_id($show['externals']['imdb'] ?? null) ? $show['externals']['imdb'] : null,
        'name' => (string) ($show['name'] ?? ''),
        'year' => isset($show['premiered']) ? substr((string) $show['premiered'], 0, 4) : null,
        'image' => $show['image']['medium'] ?? '',
        'source' => 'TVmaze',
    ];
    if ($item['imdb_id']) {
        $byImdb[$item['imdb_id']] = $item;
    } else {
        $noId[] = $item;
    }
}

$tmdbResults = array_slice($tmdb['results'] ?? [], 0, 12);
// Search results carry no external ids — resolve each one's IMDB id.
$extUrls = array_map(fn($r) => tmdb_api_url('/tv/' . $r['id'] . '/external_ids'), $tmdbResults);
$exts = http_multi_json($extUrls, 8);

foreach ($tmdbResults as $i => $r) {
    $imdbId = $exts[$i]['imdb_id'] ?? null;
    $item = [
        'imdb_id' => valid_imdb_id($imdbId) ? $imdbId : null,
        'name' => (string) ($r['name'] ?? ''),
        'year' => isset($r['first_air_date']) ? substr((string) $r['first_air_date'], 0, 4) : null,
        'image' => !empty($r['poster_path']) ? TMDB_IMG_BASE . $r['poster_path'] : '',
        'source' => 'TMDB',
    ];
    if ($item['imdb_id']) {
        if (isset($byImdb[$item['imdb_id']])) {
            $existing = &$byImdb[$item['imdb_id']];
            $existing['image'] = $existing['image'] ?: $item['image'];
            $existing['source'] = 'TVmaze + TMDB';
            unset($existing);
        } else {
            $byImdb[$item['imdb_id']] = $item;
        }
    } else {
        // No IMDB id to merge on — drop only if it looks like a duplicate.
        $dup = false;
        foreach (array_merge(array_values($byImdb), $noId) as $x) {
            if (mb_strtolower($x['name']) === mb_strtolower($item['name']) && $x['year'] === $item['year']) {
                $dup = true;
                break;
            }
        }
        if (!$dup) {
            $noId[] = $item;
        }
    }
}

json_response(['results' => array_merge(array_values($byImdb), $noId)]);
