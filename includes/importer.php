<?php
// Server-side show importer — THE one place that fetches provider data
// (TMDB first for per-episode IMDB ids, TVmaze fallback; air times from
// TVmaze, then TheTVDB) and writes the shared shows/episodes cache. Clients only ever send an IMDB id; provider
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
const TMDB_GALLERY_BASE = 'https://image.tmdb.org/t/p/w1280';
const TVMAZE_BASE = 'https://api.tvmaze.com';
const TVDB_BASE = 'https://api4.thetvdb.com/v4';

// Lightbox slider: up to GALLERY_MAX backdrops after the poster. TMDB's come
// with the detail call; TheTVDB backgrounds (one extra call) only top up shows
// with fewer than GALLERY_TVDB_BELOW.
const GALLERY_MAX = 9;
const GALLERY_TVDB_BELOW = 4;

// TheTVDB states a show's regular air time in its home country's local time
// (US Eastern for US shows). Countries spanning several zones map to the one
// their TV schedules follow; shows from unlisted countries get no time.
const TVDB_COUNTRY_TZ = [
    'usa' => 'America/New_York', 'can' => 'America/Toronto', 'mex' => 'America/Mexico_City',
    'bra' => 'America/Sao_Paulo', 'arg' => 'America/Argentina/Buenos_Aires', 'col' => 'America/Bogota',
    'gbr' => 'Europe/London', 'irl' => 'Europe/Dublin', 'prt' => 'Europe/Lisbon',
    'esp' => 'Europe/Madrid', 'fra' => 'Europe/Paris', 'bel' => 'Europe/Brussels',
    'nld' => 'Europe/Amsterdam', 'deu' => 'Europe/Berlin', 'che' => 'Europe/Zurich',
    'aut' => 'Europe/Vienna', 'ita' => 'Europe/Rome', 'dnk' => 'Europe/Copenhagen',
    'nor' => 'Europe/Oslo', 'swe' => 'Europe/Stockholm', 'fin' => 'Europe/Helsinki',
    'isl' => 'Atlantic/Reykjavik', 'pol' => 'Europe/Warsaw', 'cze' => 'Europe/Prague',
    'grc' => 'Europe/Athens', 'tur' => 'Europe/Istanbul', 'rus' => 'Europe/Moscow',
    'isr' => 'Asia/Jerusalem', 'ind' => 'Asia/Kolkata', 'tha' => 'Asia/Bangkok',
    'chn' => 'Asia/Shanghai', 'hkg' => 'Asia/Hong_Kong', 'twn' => 'Asia/Taipei',
    'kor' => 'Asia/Seoul', 'jpn' => 'Asia/Tokyo', 'phl' => 'Asia/Manila',
    'aus' => 'Australia/Sydney', 'nzl' => 'Pacific/Auckland', 'zaf' => 'Africa/Johannesburg',
];

// MANUALLY CURATED shows whose regular episodes follow TheTVDB's numbering
// instead of the base provider's, because the base one is wrong for them.
// Never detected automatically: across the catalog TheTVDB numbers hundreds
// of shows differently (daily shows by year, anime, spin-offs filed as later
// seasons) where TMDB's numbering is the right one.
const TVDB_EPISODE_SOURCE = [
    'tt13667230', // Konuşanlar: TMDB skips the 2025–26 Disney+ season and calls 2026–27 season 6
    'tt0983983',  // Shaun the Sheep: TMDB skips the 2020 season and calls the 2025 one season 6
    'tt14094206', // Marvel Studios Assembled: TMDB stops after 6 of its 23 episodes
    'tt3494560',  // La Familia del Barrio: TMDB has mostly undated "Episode N" placeholders
];

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

/** Finale flag from a TMDB episode_type: 'season' | 'midseason', '' for a
 *  regular episode, null when TMDB didn't say. */
function tmdb_finale(?string $episodeType): ?string
{
    return match ($episodeType) {
        null => null,
        'finale' => 'season',
        'mid_season' => 'midseason',
        default => '',
    };
}

/** TMDB detail query: translations, English/text-free images and EN/TR videos
 *  in the same call. */
const TMDB_DETAIL_EXTRAS = [
    'append_to_response' => 'translations,images,videos',
    'include_image_language' => 'en,null',
    'include_video_language' => 'en,tr,null',
];

/** YouTube video id from a watch / youtu.be / embed URL (or a bare id), else null. */
function youtube_key(?string $urlOrKey): ?string
{
    $s = trim((string) $urlOrKey);
    if (preg_match('~(?:v=|youtu\.be/|/embed/)([A-Za-z0-9_-]{11})~', $s, $m)) {
        return $m[1];
    }
    return preg_match('/^[A-Za-z0-9_-]{11}$/', $s) ? $s : null;
}

/** Best YouTube trailer per language ('en', 'tr') from a TMDB detail fetched
 *  with TMDB_DETAIL_EXTRAS: official before fan uploads, trailers before
 *  teasers, newest first. Language-less videos count as English. */
function tmdb_trailers(array $detail): array
{
    $videos = array_filter($detail['videos']['results'] ?? [], fn($v) => ($v['site'] ?? '') === 'YouTube'
        && in_array($v['type'] ?? '', ['Trailer', 'Teaser'], true) && youtube_key($v['key'] ?? null));
    usort($videos, fn($a, $b) => [!empty($b['official']), $b['type'] === 'Trailer', $b['published_at'] ?? '']
        <=> [!empty($a['official']), $a['type'] === 'Trailer', $a['published_at'] ?? '']);
    $out = [];
    foreach ($videos as $v) {
        $lang = ($v['iso_639_1'] ?? null) ?: 'en';
        if (in_array($lang, ['en', 'tr'], true) && !isset($out[$lang])) {
            $out[$lang] = $v['key'];
        }
    }
    return $out;
}

/** Backdrop URLs for the lightbox slider from a TMDB detail fetched with
 *  TMDB_DETAIL_EXTRAS: text-free ones first, then English, best-voted first. */
function tmdb_gallery(array $detail): array
{
    $backdrops = array_filter($detail['images']['backdrops'] ?? [], fn($b) => !empty($b['file_path']));
    usort($backdrops, fn($a, $b) => [isset($a['iso_639_1']), -($a['vote_average'] ?? 0)]
        <=> [isset($b['iso_639_1']), -($b['vote_average'] ?? 0)]);
    return array_slice(array_map(fn($b) => TMDB_GALLERY_BASE . $b['file_path'], $backdrops), 0, GALLERY_MAX);
}

/** Turkish overview from a TMDB detail fetched with
 *  append_to_response=translations, or null. */
function tmdb_tr_overview(array $detail): ?string
{
    foreach ($detail['translations']['translations'] ?? [] as $t) {
        $text = trim((string) ($t['data']['overview'] ?? ''));
        if (($t['iso_639_1'] ?? '') === 'tr' && $text !== '') {
            return $text;
        }
    }
    return null;
}

/** Base show + episodes (no per-episode IMDB ids) from TMDB, or null. */
function fetch_base_from_tmdb(string $imdbId): ?array
{
    $found = http_get_json(tmdb_api_url("/find/$imdbId", ['external_source' => 'imdb_id']));
    $tv = $found['tv_results'][0] ?? null;
    if (!$tv) {
        return null;
    }
    $detail = http_get_json(tmdb_api_url('/tv/' . $tv['id'], TMDB_DETAIL_EXTRAS));
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
                'finale' => tmdb_finale($ep['episode_type'] ?? null),
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
            'overview_tr' => tmdb_tr_overview($detail),
            'gallery' => tmdb_gallery($detail),
            'trailer' => tmdb_trailers($detail),
            'premiered' => valid_date($detail['first_air_date'] ?? null),
            'genres' => genres_string(array_column($detail['genres'] ?? [], 'name')),
            // ALL networks the show aired on (network-hoppers list several),
            // comma-separated like genres.
            'network' => genres_string(array_column($detail['networks'] ?? [], 'name')),
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
 * A TVmaze episode's exact UTC air time, or null when TVmaze has none. An
 * empty `airtime` means the time is unknown, yet TVmaze still fills airstamp
 * with a placeholder (noon in the network's timezone) — never store that as
 * an exact release time.
 */
function tvmaze_stamp(array $ep): ?string
{
    return trim((string) ($ep['airtime'] ?? '')) !== '' ? utc_stamp($ep['airstamp'] ?? null) : null;
}

/**
 * Exact air times from TVmaze, keyed "season-number" (UTC DATETIME strings,
 * null for episodes TVmaze lists without an air time). TMDB has no air times,
 * so this is the only source; empty map when the show isn't on TVmaze.
 */
function tvmaze_airstamps(string $imdbId): array
{
    $show = http_get_json(TVMAZE_BASE . '/lookup/shows?imdb=' . urlencode($imdbId));
    if (!$show || !isset($show['id'])) {
        return [];
    }
    $map = [];
    foreach (http_get_json(TVMAZE_BASE . '/shows/' . $show['id'] . '/episodes') ?? [] as $ep) {
        if (isset($ep['season'], $ep['number'])) {
            $map[$ep['season'] . '-' . $ep['number']] = tvmaze_stamp($ep);
        }
    }
    return $map;
}

/**
 * TheTVDB bearer token (valid a month), kept in memory per request and in a
 * temp file across requests. $fresh forces a new login (stale token).
 */
function tvdb_token(bool $fresh = false): ?string
{
    static $token = null;
    $file = sys_get_temp_dir() . '/tvtrack_tvdb_' . md5(TVDB_API_KEY);
    if (!$fresh) {
        if ($token === null && @filemtime($file) > time() - 25 * 86400) {
            $token = @file_get_contents($file) ?: null;
        }
        if ($token !== null) {
            return $token;
        }
    }
    $token = http_post_json(TVDB_BASE . '/login', ['apikey' => TVDB_API_KEY])['data']['token'] ?? null;
    if ($token !== null) {
        @file_put_contents($file, $token);
    }
    return $token;
}

/** Decoded TheTVDB response (its `data` may be null = not found), or null
 *  when the request failed. A failure retries once with a fresh login. */
function tvdb_get(string $path): ?array
{
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $token = tvdb_token($attempt > 0);
        if ($token === null) {
            return null;
        }
        $res = http_get_json(TVDB_BASE . $path, 2, ['Authorization: Bearer ' . $token]);
        if ($res !== null) {
            return $res;
        }
    }
    return null;
}

/**
 * A show on TheTVDB: 'airs' => its regular air time as ['time' => 'HH:MM',
 * 'tz' => zone] or null, 'episodes' => its dated regular episodes as
 * [season, number, name, airdate, finale] rows, 'overview_tr' => its Turkish
 * description or null, 'trailers' => YouTube keys by language ('en'/'tr'),
 * and 'id' => its TheTVDB series id (kept in memory for
 * follow-up calls, never stored). All empty when TheTVDB doesn't know the show (or no
 * key is configured); null when TheTVDB couldn't be asked, so callers keep
 * what's stored. A time with no air day is TheTVDB's unset
 * default, not a schedule.
 */
function tvdb_show(string $imdbId): ?array
{
    $none = ['id' => null, 'airs' => null, 'episodes' => [], 'overview_tr' => null, 'trailers' => []];
    if (!defined('TVDB_API_KEY') || TVDB_API_KEY === '') {
        return $none;
    }
    $found = tvdb_get('/search/remoteid/' . rawurlencode($imdbId));
    if ($found === null) {
        return null;
    }
    $seriesId = null;
    foreach ($found['data'] ?? [] as $hit) {
        if (isset($hit['series']['id'])) {
            $seriesId = (int) $hit['series']['id'];
            break;
        }
    }
    if (!$seriesId) {
        return $none;
    }
    $series = tvdb_get('/series/' . $seriesId . '/extended?meta=episodes,translations&short=true');
    if ($series === null) {
        return null;
    }
    $d = $series['data'] ?? [];

    $time = (string) ($d['airsTime'] ?? '');
    $country = strtolower((string) (($d['originalCountry'] ?? '') ?: ($d['originalNetwork']['country'] ?? '')));
    $airs = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)
        && array_filter($d['airsDays'] ?? [])
        && isset(TVDB_COUNTRY_TZ[$country])
        ? ['time' => $time, 'tz' => TVDB_COUNTRY_TZ[$country]] : null;

    $episodes = [];
    foreach ($d['episodes'] ?? [] as $ep) {
        $season = (int) ($ep['seasonNumber'] ?? 0);
        $number = (int) ($ep['number'] ?? 0);
        $airdate = valid_date($ep['aired'] ?? null);
        if ($season < 1 || $number < 1 || $airdate === null) {
            continue;
        }
        $name = trim((string) ($ep['name'] ?? ''));
        $finale = (string) ($ep['finaleType'] ?? '');
        $episodes[] = [
            'season' => $season,
            'number' => $number,
            'name' => preg_match('/^(TBA|TBD)?$/i', $name) ? null : $name,
            'airdate' => $airdate,
            'finale' => in_array($finale, ['season', 'series', 'midseason'], true) ? $finale : '',
        ];
    }
    $trailers = [];
    foreach ($d['trailers'] ?? [] as $tr) {
        $lang = ['eng' => 'en', 'tur' => 'tr'][$tr['language'] ?? ''] ?? null;
        $key = youtube_key($tr['url'] ?? null);
        if ($lang && $key && !isset($trailers[$lang])) {
            $trailers[$lang] = $key;
        }
    }
    return ['id' => $seriesId, 'airs' => $airs, 'episodes' => $episodes,
            'overview_tr' => tvdb_tr_overview($d), 'trailers' => $trailers];
}

/** Up to $limit TheTVDB series background URLs (text-free first, best-scored
 *  first) for the lightbox slider; [] on failure. */
function tvdb_backgrounds(int $seriesId, int $limit): array
{
    $arts = tvdb_get('/series/' . $seriesId . '/artworks?type=3')['data']['artworks'] ?? [];
    $arts = array_filter($arts, fn($a) => !empty($a['image']) && in_array($a['language'] ?? null, [null, 'eng'], true));
    usort($arts, fn($a, $b) => [isset($a['language']), -($a['score'] ?? 0)] <=> [isset($b['language']), -($b['score'] ?? 0)]);
    return array_slice(array_column($arts, 'image'), 0, max(0, $limit));
}

/** Turkish overview from a TheTVDB record fetched with meta=translations, or null. */
function tvdb_tr_overview(array $record): ?string
{
    foreach ($record['translations']['overviewTranslations'] ?? [] as $t) {
        $text = trim((string) ($t['overview'] ?? ''));
        if (($t['language'] ?? '') === 'tur' && $text !== '') {
            return $text;
        }
    }
    return null;
}

/**
 * TheTVDB's finale flags onto the base episodes, only where it describes the
 * same episode (same season, number AND air date — numbering can differ).
 * Its flag wins when it has one: only TheTVDB tells a series finale apart.
 * A "series finale" with a later season known to either side is a stale flag
 * from before a renewal, so it counts as a season finale.
 */
function tvdb_merge_finales(array &$episodes, array $tvdbEpisodes): void
{
    $flags = [];
    foreach ($tvdbEpisodes as $ep) {
        if ($ep['finale'] !== '') {
            $flags[$ep['season'] . '-' . $ep['number'] . '-' . $ep['airdate']] = $ep['finale'];
        }
    }
    $lastSeason = max([0, ...array_column($episodes, 'season'), ...array_column($tvdbEpisodes, 'season')]);
    foreach ($episodes as &$ep) {
        $key = $ep['season'] . '-' . $ep['number'] . '-' . $ep['airdate'];
        if (isset($flags[$key])) {
            $ep['finale'] = $flags[$key] === 'series' && $ep['season'] < $lastSeason ? 'season' : $flags[$key];
        }
    }
    unset($ep);
}

/**
 * Episodes TheTVDB lists that the base provider doesn't list yet: only ones
 * that continue the base's last season (higher numbers, not dated before its
 * last episode) or open the next season (dated after it); anything else risks
 * duplicating an episode the base numbers differently. The importer adds the
 * upcoming ones and only keeps the aired ones it already has.
 */
function tvdb_continuations(array $base, array $tvdbEpisodes): array
{
    $regular = array_filter($base, fn($ep) => $ep['season'] >= 1);
    $lastSeason = $regular ? max(array_column($regular, 'season')) : 0;
    $lastNumber = 0;
    $lastDate = '';
    $have = [];
    foreach ($regular as $ep) {
        $have[$ep['season'] . '-' . $ep['number']] = true;
        if ($ep['season'] === $lastSeason) {
            $lastNumber = max($lastNumber, $ep['number']);
        }
        $lastDate = max($lastDate, (string) $ep['airdate']);
    }

    $added = [];
    foreach ($tvdbEpisodes as $ep) {
        $continues = $ep['season'] === $lastSeason && $ep['number'] > $lastNumber && $ep['airdate'] >= $lastDate;
        $opensNext = $ep['season'] === $lastSeason + 1 && $ep['airdate'] > $lastDate;
        if (($continues || $opensNext) && !isset($have[$ep['season'] . '-' . $ep['number']])) {
            $added[] = ['imdb_id' => null] + $ep;
        }
    }
    return $added;
}

/** Whether two dates (or a UTC stamp's date) are within a day of each other. */
function within_a_day(?string $a, ?string $b): bool
{
    return $a !== null && $b !== null
        && abs(strtotime(substr($a, 0, 10)) - strtotime(substr($b, 0, 10))) <= 86400;
}

/** [season, number] of the regular episodes the base provider and TheTVDB
 *  number alike: same numbers, air dates within a day of each other. */
function same_numbering(array $base, array $tvdbEpisodes): array
{
    $dates = [];
    foreach ($base as $ep) {
        $dates[$ep['season'] . '-' . $ep['number']] = $ep['airdate'];
    }
    $same = [];
    foreach ($tvdbEpisodes as $ep) {
        if (within_a_day($dates[$ep['season'] . '-' . $ep['number']] ?? null, $ep['airdate'])) {
            $same[] = [$ep['season'], $ep['number']];
        }
    }
    return $same;
}

/** UTC DATETIME of an episode's air date at the show's local air time. */
function tvdb_stamp(string $airdate, array $airs): string
{
    return (new DateTimeImmutable($airdate . ' ' . $airs['time'], new DateTimeZone($airs['tz'])))
        ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
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
            'airstamp' => tvmaze_stamp($ep),
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
            'overview_tr' => null, // TVmaze has no translations
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

/** Slider image list as stored JSON, or null for none (so COALESCE keeps an
 *  earlier list when a provider returns no images). */
function gallery_json(array $urls): ?string
{
    $urls = array_values(array_filter($urls, fn($u) => is_string($u) && str_starts_with($u, 'https://')));
    return $urls ? json_encode($urls, JSON_UNESCAPED_SLASHES) : null;
}

/** Trailer map ({"en": key, "tr": key}) as stored JSON, or null for none (so
 *  COALESCE keeps an earlier map when a provider returns no videos). */
function trailer_json(array $byLang): ?string
{
    $clean = [];
    foreach (['en', 'tr'] as $lang) {
        if ($key = youtube_key($byLang[$lang] ?? null)) {
            $clean[$lang] = $key;
        }
    }
    return $clean ? json_encode($clean) : null;
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
            (imdb_id, name, image_url, backdrop_url, gallery, trailer, status, overview, overview_tr, premiered,
             genres, network, rating, runtime, synced_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(?, NOW(), NULL))
         ON DUPLICATE KEY UPDATE
             name = VALUES(name),
             image_url = COALESCE(VALUES(image_url), image_url),
             backdrop_url = COALESCE(VALUES(backdrop_url), backdrop_url),
             gallery = COALESCE(VALUES(gallery), gallery),
             trailer = COALESCE(VALUES(trailer), trailer),
             status = COALESCE(VALUES(status), status),
             overview = COALESCE(VALUES(overview), overview),
             overview_tr = COALESCE(VALUES(overview_tr), overview_tr),
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
        gallery_json($show['gallery'] ?? []),
        trailer_json($show['trailer'] ?? []),
        normalize_show_status($show['status'] ?? null),
        $show['overview'] ?? null,
        $show['overview_tr'] ?? null,
        valid_date($show['premiered'] ?? null),
        $show['genres'] ?? null,
        mb_substr((string) ($show['network'] ?? ''), 0, 255) ?: null,
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
            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
            $params[] = $showImdbId;
            $params[] = valid_imdb_id($ep['imdb_id'] ?? null) ? $ep['imdb_id'] : null;
            $params[] = max(0, (int) ($ep['season'] ?? 0));
            $params[] = max(0, (int) ($ep['number'] ?? 0));
            $params[] = mb_substr(trim((string) ($ep['name'] ?? '')), 0, 255) ?: null;
            $params[] = valid_date($ep['airdate'] ?? null);
            $params[] = $ep['airstamp'] ?? null;
            $params[] = $ep['finale'] ?? null;
        }
        // finale: '' (a provider said "regular episode") clears an old flag;
        // null (provider has no flags, e.g. TVmaze) keeps it.
        $stmt = $pdo->prepare(
            'INSERT INTO episodes (show_imdb_id, imdb_id, season, number, name, airdate, airstamp, finale)
             VALUES ' . implode(', ', $placeholders) . '
             ON DUPLICATE KEY UPDATE
                 imdb_id = COALESCE(VALUES(imdb_id), imdb_id),
                 name = VALUES(name),
                 airdate = VALUES(airdate),
                 airstamp = COALESCE(VALUES(airstamp), airstamp),
                 finale = COALESCE(VALUES(finale), finale)'
        );
        $stmt->execute($params);
        $count += count($chunk);
    }
    return $count;
}

/** Null the airstamp of the given [season, number] episodes of one show. */
function clear_airstamps(PDO $pdo, string $showImdbId, array $episodes): void
{
    foreach (array_chunk($episodes, 200) as $chunk) {
        $pairs = [];
        $params = [$showImdbId];
        foreach ($chunk as [$season, $number]) {
            $pairs[] = '(?, ?)';
            $params[] = max(0, (int) $season);
            $params[] = max(0, (int) $number);
        }
        $stmt = $pdo->prepare(
            'UPDATE episodes SET airstamp = NULL
             WHERE show_imdb_id = ? AND airstamp IS NOT NULL
               AND (season, number) IN (' . implode(', ', $pairs) . ')'
        );
        $stmt->execute($params);
    }
}

/** SQL condition "(season, number) IN (...)" for [season, number] pairs, and
 *  its params; "FALSE" for none. */
function episode_pairs_sql(array $pairs): array
{
    if (!$pairs) {
        return ['FALSE', []];
    }
    $params = [];
    foreach ($pairs as [$season, $number]) {
        $params[] = max(0, (int) $season);
        $params[] = max(0, (int) $number);
    }
    return ['(season, number) IN (' . implode(', ', array_fill(0, count($pairs), '(?, ?)')) . ')', $params];
}

/** Null the IMDB ids of a show's regular episodes other than $keep
 *  ([season, number] pairs) — ids TMDB gave an episode it numbers differently. */
function clear_episode_imdb_ids(PDO $pdo, string $showImdbId, array $keep): void
{
    [$keepSql, $params] = episode_pairs_sql($keep);
    $stmt = $pdo->prepare(
        "UPDATE episodes SET imdb_id = NULL
         WHERE show_imdb_id = ? AND season >= 1 AND imdb_id IS NOT NULL AND NOT $keepSql"
    );
    $stmt->execute([$showImdbId, ...$params]);
}

/**
 * Fetch episode IMDB ids from TMDB for up to $limit episodes that don't have
 * one yet, and update them in place. Returns how many were filled. Its own
 * short DB write window (db_live) so a dropped idle connection can't kill it.
 * $only: [season, number] of the regular episodes TMDB numbers the same way
 * (specials always qualify); null = all.
 */
function backfill_episode_imdb_ids(PDO $pdo, string $imdbId, int $limit, ?array $only = null): int
{
    if ($limit < 1) {
        return 0;
    }
    $lim = (int) min($limit, 2000);
    [$onlySql, $onlyParams] = $only === null ? ['TRUE', []] : episode_pairs_sql($only);
    $stmt = $pdo->prepare(
        "SELECT season, number FROM episodes
         WHERE show_imdb_id = ? AND imdb_id IS NULL AND (season = 0 OR $onlySql)
         ORDER BY season, number LIMIT $lim"
    );
    $stmt->execute([$imdbId, ...$onlyParams]);
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
 * $prune: also DELETE episodes the providers no longer list (renumbered or
 * removed) — only the manual "Refresh" uses this, so a transient provider gap
 * during auto-refresh can never mass-delete. Watched episodes are never
 * pruned (the FK cascade would take the watched history with them).
 */
function import_show(PDO $pdo, string $imdbId, int $backfillLimit = BACKFILL_BATCH, bool $prune = false): int
{
    @set_time_limit(120);

    $payload = fetch_base_from_tmdb($imdbId) ?? fetch_base_from_tvmaze($imdbId);
    if (!$payload) {
        throw new RuntimeException('Show not found on TMDB or TVmaze.');
    }

    // $listed: "season-number" of every episode TVmaze lists, timed or not.
    $listed = [];
    if ($payload['source'] === 'tvmaze') {
        foreach ($payload['episodes'] as $ep) {
            $listed[$ep['season'] . '-' . $ep['number']] = true;
        }
    }

    // null when TheTVDB was unreachable.
    $tvdb = tvdb_show($imdbId);

    // Curated shows (TVDB_EPISODE_SOURCE) take their regular episodes from
    // TheTVDB; specials stay the base provider's. Without TheTVDB's list their
    // stored episodes are left alone — the base numbering is the wrong one.
    // $sameNumbering: episodes both number alike, the only ones whose TMDB
    // episode ids fit.
    $tvdbSource = in_array($imdbId, TVDB_EPISODE_SOURCE, true);
    $sameNumbering = null;
    if ($tvdbSource) {
        if (!($tvdb['episodes'] ?? [])) {
            return 0;
        }
        $sameNumbering = same_numbering($payload['episodes'], $tvdb['episodes']);
        $payload['episodes'] = [
            ...array_filter($payload['episodes'], fn($ep) => $ep['season'] < 1),
            ...array_map(fn($ep) => ['imdb_id' => null] + $ep, $tvdb['episodes']),
        ];
        $listed = []; // TVmaze's listing follows the base numbering
    }

    // TheTVDB often lists announced episodes before TMDB/TVmaze do — add the
    // upcoming ones (the base provider's data replaces them once it lists
    // them). Aired ones are only kept, in $continuations, so a Refresh never
    // prunes an episode that was added while it was upcoming.
    $continuations = [];
    if ($tvdb) {
        tvdb_merge_finales($payload['episodes'], $tvdb['episodes']);
        $continuations = tvdb_continuations($payload['episodes'], $tvdb['episodes']);
        array_push($payload['episodes'], ...array_filter($continuations, fn($ep) => $ep['airdate'] >= today()));
        // Turkish description: TMDB's when it has one, else TheTVDB's.
        $payload['show']['overview_tr'] ??= $tvdb['overview_tr'];
        // Trailers: TMDB's per language, TheTVDB's for languages TMDB lacks.
        $payload['show']['trailer'] = ($payload['show']['trailer'] ?? []) + $tvdb['trailers'];
        // Slider images: top up a thin TMDB gallery with TheTVDB backgrounds.
        $gallery = $payload['show']['gallery'] ?? [];
        if ($tvdb['id'] && count($gallery) < GALLERY_TVDB_BELOW) {
            $payload['show']['gallery'] = array_values(array_unique(
                array_merge($gallery, tvdb_backgrounds($tvdb['id'], GALLERY_MAX - count($gallery)))
            ));
        }
    }

    // TMDB has no air times — enrich its episodes with TVmaze's UTC airstamps
    // so aired-gating can be exact (the TVmaze path already carries them).
    // TheTVDB-numbered episodes only take a time TVmaze gives the same episode.
    if ($payload['source'] === 'tmdb' || $tvdbSource) {
        $stamps = tvmaze_airstamps($imdbId);
        foreach ($payload['episodes'] as &$ep) {
            $key = $ep['season'] . '-' . $ep['number'];
            if ($tvdbSource && $ep['season'] >= 1 && !within_a_day($stamps[$key] ?? null, $ep['airdate'])) {
                $ep['airstamp'] = null;
                continue;
            }
            $ep['airstamp'] = $stamps[$key] ?? null;
            if (array_key_exists($key, $stamps)) {
                $listed[$key] = true;
            }
        }
        unset($ep);
    }

    // Episodes TVmaze has no time for (mostly streaming drops) get the show's
    // regular air time from TheTVDB. $timeless: [season, number] of listed
    // episodes still without a time, whose stored stamp (e.g. an old
    // placeholder) must be cleared — the upsert's COALESCE would keep it.
    // Skipped when TheTVDB was unreachable: the stored stamp may be its own.
    $airs = $tvdb['airs'] ?? null;
    $timeless = [];
    foreach ($payload['episodes'] as &$ep) {
        if (($ep['airstamp'] ?? null) === null && $airs && $ep['airdate']) {
            $ep['airstamp'] = tvdb_stamp($ep['airdate'], $airs);
        }
        if (($ep['airstamp'] ?? null) === null && $tvdb !== null
            && isset($listed[$ep['season'] . '-' . $ep['number']])) {
            $timeless[] = [$ep['season'], $ep['number']];
        }
    }
    unset($ep);

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
        clear_airstamps($pdo, $imdbId, $timeless);
        if ($sameNumbering !== null) {
            clear_episode_imdb_ids($pdo, $imdbId, $sameNumbering);
        }
        if ($payload['episodes'] && ($prune || $tvdb !== null)) {
            // Drop unwatched rows the providers no longer list, keyed by the
            // episode identity (season, number). Guarded by non-empty payload.
            // The manual Refresh ($prune) drops all of them; every other
            // import only never-aired ones (e.g. a TheTVDB announcement the
            // base provider later numbered differently) — and not when
            // TheTVDB was unreachable, as its additions are missing then.
            $keep = array_map(fn($ep) => [$ep['season'], $ep['number']], [...$payload['episodes'], ...$continuations]);
            [$keepSql, $params] = episode_pairs_sql($keep);
            $sql = "DELETE FROM episodes WHERE show_imdb_id = ? AND NOT $keepSql
                    AND NOT EXISTS (SELECT 1 FROM watched_episodes w WHERE w.episode_id = episodes.id)";
            $params = [$imdbId, ...$params];
            if (!$prune) {
                $sql .= ' AND airdate > ? AND (airstamp IS NULL OR airstamp > UTC_TIMESTAMP())';
                $params[] = today();
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // Phase 2: best-effort id backfill — the show is already usable, so a
    // failure here (timeout, provider hiccup) must not fail the import.
    try {
        backfill_episode_imdb_ids($pdo, $imdbId, $backfillLimit, $sameNumbering);
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
    $detail = http_get_json(tmdb_api_url('/movie/' . $movie['id'], TMDB_DETAIL_EXTRAS));
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
        'overview_tr' => tmdb_tr_overview($detail),
        'gallery' => tmdb_gallery($detail),
        'trailer' => tmdb_trailers($detail),
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
            (imdb_id, name, image_url, backdrop_url, gallery, trailer, status, overview, overview_tr, released,
             genres, studio, rating, runtime, synced_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(?, NOW(), NULL))
         ON DUPLICATE KEY UPDATE
             name = VALUES(name),
             image_url = COALESCE(VALUES(image_url), image_url),
             backdrop_url = COALESCE(VALUES(backdrop_url), backdrop_url),
             gallery = COALESCE(VALUES(gallery), gallery),
             trailer = COALESCE(VALUES(trailer), trailer),
             status = COALESCE(VALUES(status), status),
             overview = COALESCE(VALUES(overview), overview),
             overview_tr = COALESCE(VALUES(overview_tr), overview_tr),
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
        gallery_json($movie['gallery'] ?? []),
        trailer_json($movie['trailer'] ?? []),
        normalize_movie_status($movie['status'] ?? null),
        $movie['overview'] ?? null,
        $movie['overview_tr'] ?? null,
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
