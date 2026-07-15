<?php
// Server-side show importer — THE one place that fetches provider data
// (TMDB first for per-episode IMDB ids, TVmaze fallback) and writes the
// shared shows/episodes cache. Clients only ever send an IMDB id; provider
// data never transits the browser, and the TMDB key never leaves the server.
//
// Import happens in two cheap phases so it survives shared-hosting limits
// (short execution time, idle MySQL connections dropped mid-request):
//   1. base import  — show + episodes (no per-episode IMDB ids), committed
//                     immediately so the show is usable even on huge series.
//   2. id backfill  — episode IMDB ids fetched in small batches, one batch
//                     per import/refresh, converging over a few refreshes.
//
// Used by api/episodes.php, api/track.php, and the CLI scripts.

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/http.php';

const TMDB_BASE = 'https://api.themoviedb.org/3';
const TMDB_IMG_BASE = 'https://image.tmdb.org/t/p/w342';
const TMDB_BACKDROP_BASE = 'https://image.tmdb.org/t/p/w780';
const TVMAZE_BASE = 'https://api.tvmaze.com';

// Episode IMDB ids fetched per import/refresh. Kept small so a single web
// request stays under shared-hosting execution limits when curl_multi is
// unavailable (fetches run sequentially). CLI passes a large value.
const BACKFILL_BATCH = 30;

function tmdb_api_url(string $path, array $q = []): string
{
    $q['api_key'] = TMDB_API_KEY;
    return TMDB_BASE . $path . '?' . http_build_query($q);
}

function valid_date(mixed $value): ?string
{
    return (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) ? $value : null;
}

/** Comma-joined genre string (max 255) from a list of names, or null. */
function genres_string(array $names): ?string
{
    $names = array_values(array_filter(array_map(fn($n) => trim((string) $n), $names)));
    return $names ? mb_substr(implode(', ', $names), 0, 255) : null;
}

/** Provider rating rounded to one decimal, or null when absent/zero. */
function clean_rating(mixed $value): ?float
{
    return (is_numeric($value) && (float) $value > 0) ? round((float) $value, 1) : null;
}

/** Positive episode runtime in minutes (clamped), or null. */
function clean_runtime(mixed $value): ?int
{
    $n = (int) $value;
    return $n > 0 ? min($n, 65535) : null;
}

/** Base show + episodes (no per-episode IMDB ids) from TMDB, or null. */
function fetch_base_from_tmdb(string $imdbId): ?array
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

    return [
        'show' => [
            'imdb_id' => $imdbId,
            'name' => $detail['name'] ?? $imdbId,
            'image_url' => !empty($detail['poster_path']) ? TMDB_IMG_BASE . $detail['poster_path'] : null,
            'backdrop_url' => !empty($detail['backdrop_path']) ? TMDB_BACKDROP_BASE . $detail['backdrop_path'] : null,
            'status' => $detail['status'] ?? null,
            'overview' => trim((string) ($detail['overview'] ?? '')) ?: null,
            'premiered' => valid_date($detail['first_air_date'] ?? null),
            'genres' => genres_string(array_column($detail['genres'] ?? [], 'name')),
            'network' => trim((string) ($detail['networks'][0]['name'] ?? '')) ?: null,
            'rating' => clean_rating($detail['vote_average'] ?? null),
            'runtime' => clean_runtime($detail['episode_run_time'][0] ?? null),
        ],
        'episodes' => $episodes,
        'source' => 'tmdb',
    ];
}

/** ISO airstamp -> UTC DATETIME string, or null. */
function utc_stamp(?string $iso): ?string
{
    $ts = $iso ? strtotime($iso) : false;
    return $ts ? gmdate('Y-m-d H:i:s', $ts) : null;
}

/**
 * Exact air times from TVmaze, keyed "season-number" (UTC DATETIME strings).
 * TMDB has no air times, so this is the only source; empty map when the show
 * isn't on TVmaze.
 */
function tvmaze_airstamps(string $imdbId): array
{
    $show = http_get_json(TVMAZE_BASE . '/lookup/shows?imdb=' . urlencode($imdbId));
    if (!$show || !isset($show['id'])) {
        return [];
    }
    $map = [];
    foreach (http_get_json(TVMAZE_BASE . '/shows/' . $show['id'] . '/episodes') ?? [] as $ep) {
        $stamp = utc_stamp($ep['airstamp'] ?? null);
        if ($stamp !== null && isset($ep['season'], $ep['number'])) {
            $map[$ep['season'] . '-' . $ep['number']] = $stamp;
        }
    }
    return $map;
}

/** Base show + episodes from TVmaze (no episode IMDB ids there). */
function fetch_base_from_tvmaze(string $imdbId): ?array
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
            'airstamp' => utc_stamp($ep['airstamp'] ?? null),
        ];
    }
    return [
        'show' => [
            'imdb_id' => $imdbId,
            'name' => $show['name'] ?? $imdbId,
            'image_url' => $show['image']['medium'] ?? null,
            'backdrop_url' => null, // TVmaze has no wide/backdrop image
            'status' => $show['status'] ?? null,
            'overview' => trim(strip_tags((string) ($show['summary'] ?? ''))) ?: null,
            'premiered' => valid_date($show['premiered'] ?? null),
            'genres' => genres_string($show['genres'] ?? []),
            'network' => trim((string) ($show['network']['name'] ?? $show['webChannel']['name'] ?? '')) ?: null,
            'rating' => clean_rating($show['rating']['average'] ?? null),
            'runtime' => clean_runtime($show['runtime'] ?? $show['averageRuntime'] ?? null),
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
        'INSERT INTO shows
            (imdb_id, name, image_url, backdrop_url, status, overview, premiered,
             genres, network, rating, runtime, synced_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(?, NOW(), NULL))
         ON DUPLICATE KEY UPDATE
             name = VALUES(name),
             image_url = COALESCE(VALUES(image_url), image_url),
             backdrop_url = COALESCE(VALUES(backdrop_url), backdrop_url),
             status = COALESCE(VALUES(status), status),
             overview = COALESCE(VALUES(overview), overview),
             premiered = COALESCE(VALUES(premiered), premiered),
             genres = COALESCE(VALUES(genres), genres),
             network = COALESCE(VALUES(network), network),
             rating = COALESCE(VALUES(rating), rating),
             runtime = COALESCE(VALUES(runtime), runtime),
             synced_at = IF(VALUES(synced_at) IS NULL, synced_at, VALUES(synced_at))'
    );
    $stmt->execute([
        $show['imdb_id'],
        mb_substr(trim((string) ($show['name'] ?? '')) ?: $show['imdb_id'], 0, 255),
        mb_substr((string) ($show['image_url'] ?? ''), 0, 500) ?: null,
        mb_substr((string) ($show['backdrop_url'] ?? ''), 0, 500) ?: null,
        normalize_show_status($show['status'] ?? null),
        $show['overview'] ?? null,
        valid_date($show['premiered'] ?? null),
        $show['genres'] ?? null,
        mb_substr((string) ($show['network'] ?? ''), 0, 120) ?: null,
        $show['rating'] ?? null,
        $show['runtime'] ?? null,
        (int) $markSynced,
    ]);
}

/**
 * Canonical episode upsert, chunked multi-row. Identity is strictly
 * (show, season, number); imdb_id COALESCEs so an import without ids
 * (TVmaze, base phase) never erases ids already backfilled.
 */
function upsert_episodes(PDO $pdo, string $showImdbId, array $episodes): int
{
    $count = 0;
    foreach (array_chunk($episodes, 200) as $chunk) {
        $placeholders = [];
        $params = [];
        foreach ($chunk as $ep) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?)';
            $params[] = $showImdbId;
            $params[] = valid_imdb_id($ep['imdb_id'] ?? null) ? $ep['imdb_id'] : null;
            $params[] = max(0, (int) ($ep['season'] ?? 0));
            $params[] = max(0, (int) ($ep['number'] ?? 0));
            $params[] = mb_substr(trim((string) ($ep['name'] ?? '')), 0, 255) ?: null;
            $params[] = valid_date($ep['airdate'] ?? null);
            $params[] = $ep['airstamp'] ?? null;
        }
        $stmt = $pdo->prepare(
            'INSERT INTO episodes (show_imdb_id, imdb_id, season, number, name, airdate, airstamp)
             VALUES ' . implode(', ', $placeholders) . '
             ON DUPLICATE KEY UPDATE
                 imdb_id = COALESCE(VALUES(imdb_id), imdb_id),
                 name = VALUES(name),
                 airdate = VALUES(airdate),
                 airstamp = COALESCE(VALUES(airstamp), airstamp)'
        );
        $stmt->execute($params);
        $count += count($chunk);
    }
    return $count;
}

/**
 * Fetch episode IMDB ids from TMDB for up to $limit episodes that don't have
 * one yet, and update them in place. Returns how many were filled. Its own
 * short DB write window (db_live) so a dropped idle connection can't kill it.
 */
function backfill_episode_imdb_ids(PDO $pdo, string $imdbId, int $limit): int
{
    if ($limit < 1) {
        return 0;
    }
    $lim = (int) min($limit, 2000);
    $stmt = $pdo->prepare(
        "SELECT season, number FROM episodes
         WHERE show_imdb_id = ? AND imdb_id IS NULL ORDER BY season, number LIMIT $lim"
    );
    $stmt->execute([$imdbId]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return 0;
    }

    $found = http_get_json(tmdb_api_url("/find/$imdbId", ['external_source' => 'imdb_id']));
    $tvId = $found['tv_results'][0]['id'] ?? null;
    if (!$tvId) {
        return 0; // not on TMDB (TVmaze-only show) — ids stay null for now
    }

    $urls = array_map(
        fn($r) => tmdb_api_url('/tv/' . $tvId . '/season/' . $r['season'] . '/episode/' . $r['number'] . '/external_ids'),
        $rows
    );
    $exts = http_multi_json($urls, 12);

    $pdo = db_live();
    $upd = $pdo->prepare(
        'UPDATE episodes SET imdb_id = ?
         WHERE show_imdb_id = ? AND season = ? AND number = ? AND imdb_id IS NULL'
    );
    $pdo->beginTransaction();
    $n = 0;
    foreach ($rows as $i => $r) {
        $epImdb = $exts[$i]['imdb_id'] ?? null;
        if (valid_imdb_id($epImdb)) {
            $upd->execute([$epImdb, $imdbId, $r['season'], $r['number']]);
            $n++;
        }
    }
    $pdo->commit();
    return $n;
}

/**
 * Import (or refresh) one show: base phase committed immediately, then one
 * batch of episode-id backfill. Returns episode count. Throws
 * RuntimeException when neither provider knows the show.
 *
 * $backfillLimit: episode ids to fetch this call (CLI passes a large value to
 * complete in one run; the web default keeps each request short).
 */
function import_show(PDO $pdo, string $imdbId, int $backfillLimit = BACKFILL_BATCH): int
{
    @set_time_limit(120);

    $payload = fetch_base_from_tmdb($imdbId) ?? fetch_base_from_tvmaze($imdbId);
    if (!$payload) {
        throw new RuntimeException('Show not found on TMDB or TVmaze.');
    }

    // TMDB has no air times — enrich its episodes with TVmaze's UTC airstamps
    // so aired-gating can be exact (the TVmaze path already carries them).
    if ($payload['source'] === 'tmdb') {
        $stamps = tvmaze_airstamps($imdbId);
        if ($stamps) {
            foreach ($payload['episodes'] as &$ep) {
                $ep['airstamp'] = $stamps[$ep['season'] . '-' . $ep['number']] ?? null;
            }
            unset($ep);
        }
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

    // Phase 1: base import, committed immediately (reconnect first — the fetch
    // above may have outlasted an idle MySQL connection).
    $pdo = db_live();
    $pdo->beginTransaction();
    try {
        upsert_show($pdo, $payload['show'], true);
        $count = upsert_episodes($pdo, $imdbId, $payload['episodes']);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // Phase 2: best-effort id backfill — the show is already usable, so a
    // failure here (timeout, provider hiccup) must not fail the import.
    try {
        backfill_episode_imdb_ids($pdo, $imdbId, $backfillLimit);
    } catch (Throwable $e) {
        // ignore; a later refresh will backfill
    }

    return $count;
}

// ---------------------------------------------------------------------------
// Movies. TMDB-only (TVmaze has no movies); a movie is a single row, so the
// import is just two HTTP calls — no phases, no backfill.

/** Movie metadata from TMDB by IMDB id, or null when TMDB doesn't know it. */
function fetch_movie_from_tmdb(string $imdbId): ?array
{
    $found = http_get_json(tmdb_api_url("/find/$imdbId", ['external_source' => 'imdb_id']));
    $movie = $found['movie_results'][0] ?? null;
    if (!$movie) {
        return null;
    }
    $detail = http_get_json(tmdb_api_url('/movie/' . $movie['id']));
    if (!$detail) {
        return null;
    }
    return [
        'imdb_id' => $imdbId,
        'name' => $detail['title'] ?? $imdbId,
        'image_url' => !empty($detail['poster_path']) ? TMDB_IMG_BASE . $detail['poster_path'] : null,
        'backdrop_url' => !empty($detail['backdrop_path']) ? TMDB_BACKDROP_BASE . $detail['backdrop_path'] : null,
        'status' => $detail['status'] ?? null,
        'overview' => trim((string) ($detail['overview'] ?? '')) ?: null,
        'released' => valid_date($detail['release_date'] ?? null),
        'genres' => genres_string(array_column($detail['genres'] ?? [], 'name')),
        'studio' => trim((string) ($detail['production_companies'][0]['name'] ?? '')) ?: null,
        'rating' => clean_rating($detail['vote_average'] ?? null),
        'runtime' => clean_runtime($detail['runtime'] ?? null), // scalar on movies
    ];
}

/** Canonical movie upsert — same COALESCE guards as upsert_show(). */
function upsert_movie(PDO $pdo, array $movie, bool $markSynced): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO movies
            (imdb_id, name, image_url, backdrop_url, status, overview, released,
             genres, studio, rating, runtime, synced_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(?, NOW(), NULL))
         ON DUPLICATE KEY UPDATE
             name = VALUES(name),
             image_url = COALESCE(VALUES(image_url), image_url),
             backdrop_url = COALESCE(VALUES(backdrop_url), backdrop_url),
             status = COALESCE(VALUES(status), status),
             overview = COALESCE(VALUES(overview), overview),
             released = COALESCE(VALUES(released), released),
             genres = COALESCE(VALUES(genres), genres),
             studio = COALESCE(VALUES(studio), studio),
             rating = COALESCE(VALUES(rating), rating),
             runtime = COALESCE(VALUES(runtime), runtime),
             synced_at = IF(VALUES(synced_at) IS NULL, synced_at, VALUES(synced_at))'
    );
    $stmt->execute([
        $movie['imdb_id'],
        mb_substr(trim((string) ($movie['name'] ?? '')) ?: $movie['imdb_id'], 0, 255),
        mb_substr((string) ($movie['image_url'] ?? ''), 0, 500) ?: null,
        mb_substr((string) ($movie['backdrop_url'] ?? ''), 0, 500) ?: null,
        normalize_movie_status($movie['status'] ?? null),
        $movie['overview'] ?? null,
        valid_date($movie['released'] ?? null),
        $movie['genres'] ?? null,
        mb_substr((string) ($movie['studio'] ?? ''), 0, 120) ?: null,
        $movie['rating'] ?? null,
        $movie['runtime'] ?? null,
        (int) $markSynced,
    ]);
}

/** Import (or refresh) one movie. Throws RuntimeException when TMDB lacks it. */
function import_movie(PDO $pdo, string $imdbId): void
{
    @set_time_limit(60);

    $movie = fetch_movie_from_tmdb($imdbId);
    if (!$movie) {
        throw new RuntimeException('Movie not found on TMDB.');
    }

    // Reconnect first — the fetch may have outlasted an idle MySQL connection.
    $pdo = db_live();
    $pdo->beginTransaction();
    try {
        upsert_movie($pdo, $movie, true);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
