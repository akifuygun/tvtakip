<?php
// GET ?q=... -> {results: [{imdb_id, name, year, image, source}]}
// Queries TVmaze and TMDB server-side in parallel-ish and merges by IMDB id,
// so the TMDB key never reaches the browser and either provider being down
// only narrows the results. A show IMDB doesn't list comes with its
// provisional id instead (valid_show_id()). PUBLIC — guests search too
// (results link to public pages, and clicking imports the show into the
// shared cache).
require_once __DIR__ . '/../includes/importer.php';

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
$provisional = []; // shows without an IMDB id, by the provisional id they were first listed under

foreach ($tvmaze ?? [] as $entry) {
    $show = $entry['show'] ?? null;
    if (!isset($show['id'])) {
        continue;
    }
    $imdbId = $show['externals']['imdb'] ?? null;
    $item = [
        'imdb_id' => valid_imdb_id($imdbId) ? $imdbId : 'mz' . $show['id'],
        'name' => (string) ($show['name'] ?? ''),
        'year' => isset($show['premiered']) ? substr((string) $show['premiered'], 0, 4) : null,
        'image' => $show['image']['medium'] ?? '',
        'source' => 'TVmaze',
    ];
    if (valid_imdb_id($imdbId)) {
        $byImdb[$imdbId] = $item;
    } else {
        $provisional[$item['imdb_id']] = $item;
    }
}

$tmdbResults = array_slice($tmdb['results'] ?? [], 0, 12);
// Search results carry no external ids — resolve each one's IMDB id.
$extUrls = array_map(fn($r) => tmdb_api_url('/tv/' . $r['id'] . '/external_ids'), $tmdbResults);
$exts = http_multi_json($extUrls, 8);

foreach ($tmdbResults as $i => $r) {
    $imdbId = $exts[$i]['imdb_id'] ?? null;
    $item = [
        'imdb_id' => valid_imdb_id($imdbId) ? $imdbId : 'tm' . $r['id'],
        'name' => (string) ($r['name'] ?? ''),
        'year' => isset($r['first_air_date']) ? substr((string) $r['first_air_date'], 0, 4) : null,
        'image' => !empty($r['poster_path']) ? TMDB_IMG_BASE . $r['poster_path'] : '',
        'source' => 'TMDB',
    ];
    if (valid_imdb_id($imdbId)) {
        if (isset($byImdb[$imdbId])) {
            $existing = &$byImdb[$imdbId];
            $existing['image'] = $existing['image'] ?: $item['image'];
            $existing['source'] = 'TVmaze + TMDB';
            unset($existing);
        } else {
            $byImdb[$imdbId] = $item;
        }
        continue;
    }
    // No IMDB id to merge on: an entry of the same name and year is taken for
    // the same show, and this one is dropped — unless that one is TVmaze's
    // and has no IMDB id either. Then TMDB's id stands for the show: its
    // import has more (Turkish names, images, trailers).
    $twin = null;
    foreach ($byImdb + $provisional as $key => $x) {
        if (mb_strtolower($x['name']) === mb_strtolower($item['name']) && $x['year'] === $item['year']) {
            $twin = $key;
            break;
        }
    }
    if ($twin === null) {
        $provisional[$item['imdb_id']] = $item;
    } elseif (str_starts_with($provisional[$twin]['imdb_id'] ?? '', 'mz')) {
        $provisional[$twin] = ['image' => $provisional[$twin]['image'] ?: $item['image'], 'source' => 'TVmaze + TMDB'] + $item;
    }
}

json_response(['results' => [...array_values($byImdb), ...array_values($provisional)]]);
