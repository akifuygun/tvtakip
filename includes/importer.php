<?php
// Server-side show importer — THE one place that fetches provider data
// (TMDB first for per-episode IMDB ids, TVmaze fallback) and writes the
// shared shows/episodes cache. Clients only ever send an IMDB id; provider
// data never transits the browser, and the TMDB key never leaves the server.
//
// Used by api/episodes.php, api/track.php, and the CLI scripts.

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/http.php';

const TMDB_BASE = 'https://api.themoviedb.org/3';
const TMDB_IMG_BASE = 'https://image.tmdb.org/t/p/w342';
const TVMAZE_BASE = 'https://api.tvmaze.com';

// Per-import cap on TMDB external_ids calls; huge shows backfill
// progressively across refreshes instead of timing out one request.
const MAX_EXTERNAL_ID_FETCHES = 500;

function tmdb_api_url(string $path, array $q = []): string
{
    $q['api_key'] = TMDB_API_KEY;
    return TMDB_BASE . $path . '?' . http_build_query($q);
}

function valid_date(mixed $value): ?string
{
    return (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) ? $value : null;
}

/**
 * Full show + episodes from TMDB by show IMDB id, or null if TMDB doesn't
 * know it. $haveIdKeys lists "season-number" strings that already carry an
 * episode IMDB id in our cache, so refreshes only fetch the missing ones.
 */
function fetch_show_from_tmdb(string $imdbId, array $haveIdKeys = []): ?array
{
    $found = http_get_json(tmdb_api_url("/find/$imdbId", ['external_source' => 'imdb_id']));
    $tv = $found['tv_results'][0] ?? null;
    if (!$tv) {
        return null;
    }
    $detail = http_get_json(tmdb_api_url('/tv/' . $tv['id']));
    if (!$detail) {
        return null;
    }

    $seasonUrls = array_map(
        fn($s) => tmdb_api_url('/tv/' . $tv['id'] . '/season/' . $s['season_number']),
        $detail['seasons'] ?? []
    );
    $episodes = [];
    foreach (http_multi_json($seasonUrls, 6) as $season) {
        foreach ($season['episodes'] ?? [] as $ep) {
            $episodes[] = [
                'imdb_id' => null,
                'season' => max(0, (int) ($ep['season_number'] ?? 0)),
                'number' => max(0, (int) ($ep['episode_number'] ?? 0)),
                'name' => trim((string) ($ep['name'] ?? '')),
                'airdate' => valid_date($ep['air_date'] ?? null),
            ];
        }
    }

    // Episode IMDB ids for episodes we don't have one for yet, capped.
    $have = array_flip($haveIdKeys);
    $need = [];
    foreach ($episodes as $i => $ep) {
        if (!isset($have[$ep['season'] . '-' . $ep['number']])) {
            $need[] = $i;
        }
    }
    $need = array_slice($need, 0, MAX_EXTERNAL_ID_FETCHES);
    $extUrls = array_map(
        fn($i) => tmdb_api_url('/tv/' . $tv['id'] . '/season/' . $episodes[$i]['season'] . '/episode/' . $episodes[$i]['number'] . '/external_ids'),
        $need
    );
    foreach (http_multi_json($extUrls, 12) as $k => $ext) {
        $epImdb = $ext['imdb_id'] ?? null;
        if (valid_imdb_id($epImdb)) {
            $episodes[$need[$k]]['imdb_id'] = $epImdb;
        }
    }

    return [
        'show' => [
            'imdb_id' => $imdbId,
            'name' => $detail['name'] ?? $imdbId,
            'image_url' => !empty($detail['poster_path']) ? TMDB_IMG_BASE . $detail['poster_path'] : null,
            'status' => $detail['status'] ?? null,
            'overview' => trim((string) ($detail['overview'] ?? '')) ?: null,
            'premiered' => valid_date($detail['first_air_date'] ?? null),
        ],
        'episodes' => $episodes,
        'source' => 'tmdb',
    ];
}

/** Fallback: show + episodes from TVmaze (no episode IMDB ids there). */
function fetch_show_from_tvmaze(string $imdbId): ?array
{
    $show = http_get_json(TVMAZE_BASE . '/lookup/shows?imdb=' . urlencode($imdbId));
    if (!$show || !isset($show['id'])) {
        return null;
    }
    $episodes = [];
    foreach (http_get_json(TVMAZE_BASE . '/shows/' . $show['id'] . '/episodes') ?? [] as $ep) {
        $episodes[] = [
            'imdb_id' => null,
            'season' => max(0, (int) ($ep['season'] ?? 0)),
            'number' => max(0, (int) ($ep['number'] ?? 0)),
            'name' => trim((string) ($ep['name'] ?? '')),
            'airdate' => valid_date($ep['airdate'] ?? null),
        ];
    }
    return [
        'show' => [
            'imdb_id' => $imdbId,
            'name' => $show['name'] ?? $imdbId,
            'image_url' => $show['image']['medium'] ?? null,
            'status' => $show['status'] ?? null,
            'overview' => trim(strip_tags((string) ($show['summary'] ?? ''))) ?: null,
            'premiered' => valid_date($show['premiered'] ?? null),
        ],
        'episodes' => $episodes,
        'source' => 'tvmaze',
    ];
}

/**
 * Canonical show upsert. COALESCE guards: a provider with less data can
 * never blank out values another already filled in. synced_at only moves
 * when a completed import says so.
 */
function upsert_show(PDO $pdo, array $show, bool $markSynced): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO shows (imdb_id, name, image_url, status, overview, premiered, synced_at)
         VALUES (?, ?, ?, ?, ?, ?, IF(?, NOW(), NULL))
         ON DUPLICATE KEY UPDATE
             name = VALUES(name),
             image_url = COALESCE(VALUES(image_url), image_url),
             status = COALESCE(VALUES(status), status),
             overview = COALESCE(VALUES(overview), overview),
             premiered = COALESCE(VALUES(premiered), premiered),
             synced_at = IF(VALUES(synced_at) IS NULL, synced_at, VALUES(synced_at))'
    );
    $stmt->execute([
        $show['imdb_id'],
        mb_substr(trim((string) ($show['name'] ?? '')) ?: $show['imdb_id'], 0, 255),
        mb_substr((string) ($show['image_url'] ?? ''), 0, 500) ?: null,
        normalize_show_status($show['status'] ?? null),
        $show['overview'] ?? null,
        valid_date($show['premiered'] ?? null),
        (int) $markSynced,
    ]);
}

/**
 * Canonical episode upsert, chunked multi-row. Identity is strictly
 * (show, season, number); imdb_id COALESCEs so an import without ids
 * (TVmaze, capped TMDB run) never erases ids already backfilled.
 */
function upsert_episodes(PDO $pdo, string $showImdbId, array $episodes): int
{
    $count = 0;
    foreach (array_chunk($episodes, 200) as $chunk) {
        $placeholders = [];
        $params = [];
        foreach ($chunk as $ep) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?)';
            $params[] = $showImdbId;
            $params[] = valid_imdb_id($ep['imdb_id'] ?? null) ? $ep['imdb_id'] : null;
            $params[] = max(0, (int) ($ep['season'] ?? 0));
            $params[] = max(0, (int) ($ep['number'] ?? 0));
            $params[] = mb_substr(trim((string) ($ep['name'] ?? '')), 0, 255) ?: null;
            $params[] = valid_date($ep['airdate'] ?? null);
        }
        $stmt = $pdo->prepare(
            'INSERT INTO episodes (show_imdb_id, imdb_id, season, number, name, airdate)
             VALUES ' . implode(', ', $placeholders) . '
             ON DUPLICATE KEY UPDATE
                 imdb_id = COALESCE(VALUES(imdb_id), imdb_id),
                 name = VALUES(name),
                 airdate = VALUES(airdate)'
        );
        $stmt->execute($params);
        $count += count($chunk);
    }
    return $count;
}

/**
 * Import (or refresh) one show into the cache: TMDB first, TVmaze fallback,
 * poster combined from whichever provider has one. Returns episode count.
 * Throws RuntimeException when neither provider knows the show.
 */
function import_show(PDO $pdo, string $imdbId): int
{
    set_time_limit(120);

    // season-number keys that already have an episode IMDB id — refreshes
    // then spend their external_ids budget only on the missing ones.
    $stmt = $pdo->prepare(
        "SELECT CONCAT(season, '-', number) AS k FROM episodes
         WHERE show_imdb_id = ? AND imdb_id IS NOT NULL"
    );
    $stmt->execute([$imdbId]);
    $haveIdKeys = array_column($stmt->fetchAll(), 'k');

    $payload = fetch_show_from_tmdb($imdbId, $haveIdKeys)
        ?? fetch_show_from_tvmaze($imdbId);
    if (!$payload) {
        throw new RuntimeException('Show not found on TMDB or TVmaze.');
    }

    // Combine providers for the poster: whoever has one wins.
    if (empty($payload['show']['image_url'])) {
        if ($payload['source'] === 'tmdb') {
            $other = http_get_json(TVMAZE_BASE . '/lookup/shows?imdb=' . urlencode($imdbId));
            $payload['show']['image_url'] = $other['image']['medium'] ?? null;
        } else {
            $found = http_get_json(tmdb_api_url("/find/$imdbId", ['external_source' => 'imdb_id']));
            $poster = $found['tv_results'][0]['poster_path'] ?? null;
            $payload['show']['image_url'] = $poster ? TMDB_IMG_BASE . $poster : null;
        }
    }

    $pdo->beginTransaction();
    try {
        upsert_show($pdo, $payload['show'], true);
        $count = upsert_episodes($pdo, $imdbId, $payload['episodes']);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $count;
}
