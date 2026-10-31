<?php
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/i18n.php';

/**
 * The user's timezone, reported by the browser via the 'tz' cookie
 * (Intl.DateTimeFormat, set in app.js) and validated against PHP's identifier
 * list. Falls back to Istanbul (primary audience) for first hits and crawlers.
 */
function app_timezone(): string
{
    static $tz = null;
    if ($tz === null) {
        $candidate = (string) ($_COOKIE['tz'] ?? '');
        $tz = in_array($candidate, DateTimeZone::listIdentifiers(), true)
            ? $candidate : 'Europe/Istanbul';
    }
    return $tz;
}

// One clock per request, in the user's timezone. Exact aired-gating uses UTC
// airstamps (timezone-independent); date-only fallbacks and display use this.
date_default_timezone_set(app_timezone());

function today(): string
{
    return date('Y-m-d');
}

const SESSION_LIFETIME = 60 * 60 * 24 * 30;   // 30 days
const REMEMBER_COOKIE = 'tvtrack_remember';
const REMEMBER_LIFETIME = 60 * 60 * 24 * 60;  // 60 days

// Cache-buster appended to CSS/JS URLs so far-future caching (.htaccess) is
// safe: bump this on any CSS/JS change, alongside the CACHE const in sw.js.
const ASSET_VERSION = '71';

/** True when the current request reached us over HTTPS (directly or via the
 *  InfinityFree proxy), so cookies can carry the Secure flag. */
function request_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/** User's colour theme from the `theme` cookie ('light' or 'dark'), defaulting
 *  to dark (the site's original look). app.js writes the cookie on toggle; it's
 *  read here so the right theme is set on <html> before first paint (no flash). */
function app_theme(): string
{
    return ($_COOKIE['theme'] ?? '') === 'light' ? 'light' : 'dark';
}

/** Page layout from the `layout` cookie: 'top' (header + footer bars, the
 *  default) or 'side' (one left panel, for wide screens). Set on <html> like
 *  the theme so the layout is right on first paint. */
function app_layout(): string
{
    return ($_COOKIE['layout'] ?? '') === 'side' ? 'side' : 'top';
}

if (session_status() === PHP_SESSION_NONE) {
    // Long-lived session so users aren't logged out during normal use; the
    // remember-me token below restores it if the shared host GCs it early.
    ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => request_is_https(),
    ]);
    session_start();
}

// If the session lapsed but a valid remember-me cookie is present, log back in.
attempt_remember_login();

function current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function current_display_name(): ?string
{
    return $_SESSION['display_name'] ?? null;
}

function is_logged_in(): bool
{
    return current_user_id() !== null;
}

/** Establish a logged-in session for a user. */
function login_user(int $userId, string $displayName, string $email): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['display_name'] = $displayName;
    $_SESSION['email'] = $email;
}

/** Issue a remember-me cookie + DB token (selector:validator). Fail-safe:
 *  if the token can't be stored, the user just keeps a normal session. */
function remember_user(int $userId): void
{
    try {
        $selector = bin2hex(random_bytes(16));    // 32 hex chars
        $validator = bin2hex(random_bytes(32));   // 64 hex chars
        $expires = time() + REMEMBER_LIFETIME;

        $stmt = db()->prepare(
            'INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $selector, hash('sha256', $validator), date('Y-m-d H:i:s', $expires)]);

        setcookie(REMEMBER_COOKIE, $selector . ':' . $validator, [
            'expires' => $expires,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => request_is_https(),
        ]);
    } catch (Throwable $e) {
        // remember-me unavailable (e.g. table missing) — ignore
    }
}

/** Delete the current remember-me token (DB row + cookie), if any. */
function forget_user(): void
{
    if (!empty($_COOKIE[REMEMBER_COOKIE])) {
        [$selector] = array_pad(explode(':', $_COOKIE[REMEMBER_COOKIE], 2), 2, '');
        if ($selector !== '') {
            try {
                $stmt = db()->prepare('DELETE FROM remember_tokens WHERE selector = ?');
                $stmt->execute([$selector]);
            } catch (Throwable $e) {
                // ignore — still clear the cookie below
            }
        }
    }
    setcookie(REMEMBER_COOKIE, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => request_is_https(),
    ]);
}

/** Restore a session from a valid remember-me cookie, rotating the validator. */
function attempt_remember_login(): void
{
    if (is_logged_in() || empty($_COOKIE[REMEMBER_COOKIE])) {
        return;
    }
    [$selector, $validator] = array_pad(explode(':', $_COOKIE[REMEMBER_COOKIE], 2), 2, '');
    if ($selector === '' || $validator === '') {
        forget_user();
        return;
    }

    try {
        $stmt = db()->prepare(
            'SELECT rt.id, rt.user_id, rt.validator_hash, rt.expires_at, u.display_name, u.email
             FROM remember_tokens rt JOIN users u ON u.id = rt.user_id WHERE rt.selector = ?'
        );
        $stmt->execute([$selector]);
        $row = $stmt->fetch();

        if (!$row) {
            forget_user();
            return;
        }
        // Constant-time compare; drop the token if expired or the validator is wrong.
        if (strtotime($row['expires_at']) < time()
            || !hash_equals($row['validator_hash'], hash('sha256', $validator))) {
            $del = db()->prepare('DELETE FROM remember_tokens WHERE id = ?');
            $del->execute([$row['id']]);
            forget_user();
            return;
        }

        login_user((int) $row['user_id'], $row['display_name'], $row['email']);

        // Rotate the validator so a stolen cookie is single-use.
        $newValidator = bin2hex(random_bytes(32));
        $expires = time() + REMEMBER_LIFETIME;
        $upd = db()->prepare('UPDATE remember_tokens SET validator_hash = ?, expires_at = ? WHERE id = ?');
        $upd->execute([hash('sha256', $newValidator), date('Y-m-d H:i:s', $expires), $row['id']]);
        setcookie(REMEMBER_COOKIE, $selector . ':' . $newValidator, [
            'expires' => $expires,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => request_is_https(),
        ]);
    } catch (Throwable $e) {
        // remember-me unavailable (e.g. table missing) — stay on a normal session
    }
}

/** True if the logged-in user's email is listed in ADMIN_EMAILS. */
function is_admin(): bool
{
    $email = strtolower(trim($_SESSION['email'] ?? ''));
    if ($email === '' || !defined('ADMIN_EMAILS') || ADMIN_EMAILS === '') {
        return false;
    }
    $admins = array_map('trim', explode(',', strtolower(ADMIN_EMAILS)));
    return in_array($email, $admins, true);
}

/** Redirect to login for pages that require a session. */
function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

/** For API endpoints: emit a JSON 401 instead of redirecting. */
function require_login_json(): void
{
    if (!is_logged_in()) {
        json_response(['error' => 'Not logged in'], 401);
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(?string $token): bool
{
    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function valid_imdb_id(?string $id): bool
{
    return is_string($id) && preg_match('/^tt\d{6,10}$/', $id) === 1;
}

/** Public (SEO) URL of a show's series page, in the given/current language. */
function series_url(string $imdbId, ?string $lang = null): string
{
    return lang_path('/series/' . $imdbId, $lang);
}

/** Public (SEO) URL of a movie page, in the given/current language. */
function movie_url(string $imdbId, ?string $lang = null): string
{
    return lang_path('/movie/' . $imdbId, $lang);
}

/** Browse page with one filter chip preselected (facet: network|genre|status). */
function browse_filter_url(string $facet, string $value): string
{
    return lang_path('/browse') . '?' . $facet . '=' . rawurlencode($value);
}

/**
 * Scheme+host for absolute SEO URLs (canonical, og:url, sitemap). The Host
 * header is attacker-controlled, so only whitelisted hosts are echoed back;
 * anything else falls back to the production domain.
 */
function seo_base(): string
{
    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    if ($host !== 'tvtakip.akifuygun.com'
        && !preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $host)) {
        $host = 'tvtakip.akifuygun.com';
    }
    return (request_is_https() ? 'https' : 'http') . '://' . $host;
}

/**
 * SQL condition: has this episode aired? Exact when the UTC airstamp is known
 * (TVmaze), date-granular fallback otherwise. Adds ONE positional param —
 * callers must append today() at this position in their parameter list.
 */
function aired_sql(string $alias = ''): string
{
    $p = $alias !== '' ? $alias . '.' : '';
    return "(({$p}airstamp IS NOT NULL AND {$p}airstamp <= UTC_TIMESTAMP())
        OR ({$p}airstamp IS NULL AND {$p}airdate IS NOT NULL AND {$p}airdate <= ?))";
}

/**
 * Calendar gate: does this episode air on or before TODAY in the viewer's
 * timezone? Day-granular on purpose — an episode airing tonight is listed from
 * the start of its local air day, not from its exact airstamp. Appends TWO
 * positional params; callers pass today_end_utc(), then today().
 */
function airs_by_today_sql(string $alias = ''): string
{
    $p = $alias !== '' ? $alias . '.' : '';
    return "(({$p}airstamp IS NOT NULL AND {$p}airstamp < ?)
        OR ({$p}airstamp IS NULL AND {$p}airdate IS NOT NULL AND {$p}airdate <= ?))";
}

/** Start of tomorrow in the viewer's timezone, as a UTC datetime (airstamp format). */
function today_end_utc(): string
{
    return (new DateTimeImmutable('tomorrow'))
        ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/**
 * Strict variant for DISPLAY surfaces (progress counts): a date-only
 * episode counts as aired only once its air date is OVER in the user's
 * timezone. "Airs July 17" means at some unknown time during July 17, so
 * midnight-of-the-17th (what aired_sql's fallback allows) can be hours early —
 * users saw episodes "ready to watch" before their actual release. Marking
 * watched stays on the permissive aired_sql so a user who watches an episode
 * on its release evening isn't blocked. Episodes with exact airstamps behave
 * identically under both. Appends one positional param; callers pass today().
 */
function certainly_aired_sql(string $alias = ''): string
{
    $p = $alias !== '' ? $alias . '.' : '';
    return "(({$p}airstamp IS NOT NULL AND {$p}airstamp <= UTC_TIMESTAMP())
        OR ({$p}airstamp IS NULL AND {$p}airdate IS NOT NULL AND {$p}airdate < ?))";
}

/** SxxEyy code for an episode. */
function episode_code(int $season, int $number): string
{
    return sprintf('S%02dE%02d', $season, $number);
}

/** URL slug of a title: lowercase ASCII (Turkish letters and common accents
 *  folded), "&" spelled "and" ($and) or dropped, apostrophes dropped, anything
 *  else non-alphanumeric → one hyphen. */
function name_slug(string $name, bool $and = true): string
{
    $s = mb_strtolower(strtr($name, ['İ' => 'i', '&' => $and ? ' and ' : ' ']));
    $s = strtr($s, [
        'ı' => 'i', 'ş' => 's', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', 'ç' => 'c',
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u',
        'ñ' => 'n',
    ]);
    $s = preg_replace("/['’]/u", '', $s);
    return trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-');
}

/** The Watch sites from config WATCH_SITES: label => [url, finale?, and?, remake?]
 *  (see config.sample.php), in badge order. */
function watch_sites(): array
{
    return defined('WATCH_SITES') && is_array(WATCH_SITES) ? WATCH_SITES : [];
}

/** Whether this viewer gets Watch links: admins, with WATCH_SITES configured. */
function watch_enabled(): bool
{
    return watch_sites() && is_admin();
}

// The admin-set origins of the Watch sites (the footer ❤ → api/watch_base.php),
// for when a site moves domains. A generated `return [label => origin];` file,
// not in git.
const WATCH_BASE_FILE = __DIR__ . '/watch_base.php';

/** "scheme://host/" a URL starts with, or ''. */
function url_origin(string $url): string
{
    return preg_match('#^https?://[^/]+/#i', $url, $m) ? $m[0] : '';
}

/** Each Watch site's origin, label => "scheme://host/": the admin-set one,
 *  else its URL template's. */
function watch_bases(): array
{
    $saved = is_file(WATCH_BASE_FILE) ? include WATCH_BASE_FILE : null;
    $bases = [];
    foreach (watch_sites() as $site => $cfg) {
        $base = is_array($saved) ? (string) ($saved[$site] ?? '') : '';
        $bases[$site] = $base !== '' && url_origin($base) === $base ? $base : url_origin($cfg['url']);
    }
    return $bases;
}

/** The admin-set slugs in shows.watch_slug: JSON {label: slug}; a plain slug
 *  (the older format) counts for every site. */
function watch_slug_overrides(?string $stored): array
{
    if ($stored === null || $stored === '') {
        return [];
    }
    $json = json_decode($stored, true);
    return is_array($json) ? $json : array_fill_keys(array_keys(watch_sites()), $stored);
}

/** The earliest cached show with the same name that premiered before this one
 *  (remakes, revivals), or null. $show needs name and premiered. */
function older_namesake(array $show): ?string
{
    static $groups = null; // name => [imdb_id => premiered], for names several shows share
    if ($groups === null) {
        $groups = [];
        $rows = db()->query(
            'SELECT s.name, s.imdb_id, s.premiered FROM shows s
             JOIN (SELECT name FROM shows GROUP BY name HAVING COUNT(*) > 1) d ON d.name = s.name'
        );
        foreach ($rows as $r) {
            $groups[$r['name']][$r['imdb_id']] = (string) $r['premiered'];
        }
    }
    $older = null;
    $olderDate = (string) ($show['premiered'] ?? '');
    foreach ($olderDate !== '' ? $groups[$show['name']] ?? [] : [] as $imdbId => $date) {
        if ($date !== '' && $date < $olderDate) {
            [$older, $olderDate] = [$imdbId, $date];
        }
    }
    return $older;
}

/** Whether a cached show has episode SxE. */
function show_has_episode(string $imdbId, int $season, int $number): bool
{
    static $codes = []; // imdb_id => set of "season-number"
    if (!isset($codes[$imdbId])) {
        $stmt = db()->prepare("SELECT CONCAT(season, '-', number) FROM episodes WHERE show_imdb_id = ?");
        $stmt->execute([$imdbId]);
        $codes[$imdbId] = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    return isset($codes[$imdbId]["$season-$number"]);
}

/**
 * A show's slug on one Watch site: the admin-set one, else its title (Turkish
 * productions by their original one); with remake "year", the start year is
 * added when an older cached show has the same name. $show needs name,
 * original_name, original_language, premiered and watch_slug.
 */
function watch_slug(array $show, string $site): string
{
    $override = (string) (watch_slug_overrides($show['watch_slug'] ?? null)[$site] ?? '');
    if ($override !== '') {
        return $override;
    }
    $cfg = watch_sites()[$site] ?? [];
    $turkish = ($show['original_language'] ?? null) === 'tr' && ($show['original_name'] ?? '') !== '';
    $slug = name_slug($turkish ? $show['original_name'] : $show['name'], $cfg['and'] ?? true);
    if (($cfg['remake'] ?? '') === 'year' && older_namesake($show)) {
        $slug .= '-' . substr((string) $show['premiered'], 0, 4);
    }
    return $slug;
}

/**
 * Admin-only Watch links for a regular episode, one per site in badge order:
 * [['site' => label, 'url' => url], …]; [] for everyone else. Each site's
 * template ({slug}, {season}, {episode}) — its finale one, when set, for
 * Turkish productions' season/series finales — on the site's current origin.
 * With remake "clash", a derived slug whose older namesake has the same
 * episode gets "-2" at the end, as the site's own duplicate-address suffix.
 */
function watch_links(array $show, int $season, int $number, ?string $finale): array
{
    if ($season < 1 || !watch_enabled()) {
        return [];
    }
    static $bases = null;
    $bases ??= watch_bases();
    $turkishFinale = in_array($finale, ['season', 'series'], true) && ($show['original_language'] ?? null) === 'tr';
    $overrides = watch_slug_overrides($show['watch_slug'] ?? null);
    $links = [];
    foreach (watch_sites() as $site => $cfg) {
        $template = $turkishFinale && ($cfg['finale'] ?? '') !== '' ? $cfg['finale'] : $cfg['url'];
        $url = strtr($template, ['{slug}' => watch_slug($show, $site), '{season}' => $season, '{episode}' => $number]);
        if (($cfg['remake'] ?? '') === 'clash' && ($overrides[$site] ?? '') === ''
            && ($older = older_namesake($show)) && show_has_episode($older, $season, $number)) {
            $url = preg_replace('#/?$#', '-2$0', $url, 1);
        }
        $links[] = ['site' => $site, 'url' => $bases[$site] . substr($url, strlen(url_origin($url)))];
    }
    return $links;
}

/** Premiere/finale badge for an episode ('' for regular ones and specials).
 *  Finales come from provider flags; premieres are simply episode 1. */
function episode_badge_html(int $season, int $number, ?string $finale): string
{
    if ($season < 1) {
        return '';
    }
    [$key, $kind] = match ($finale) {
        'series' => ['ep_series_finale', 'finale'],
        'season' => ['ep_season_finale', 'finale'],
        'midseason' => ['ep_midseason_finale', 'finale'],
        default => $number === 1
            ? [$season === 1 ? 'ep_series_premiere' : 'ep_season_premiere', 'premiere']
            : [null, null],
    };
    return $key ? '<span class="ep-badge ep-badge-' . $kind . '">' . t($key) . '</span>' : '';
}

/** Fold provider genre-name variants into one canonical label (browse filter). */
function canonical_genre(string $genre): string
{
    static $map = [
        'Science-Fiction' => 'Sci-Fi & Fantasy',
    ];
    return $map[$genre] ?? $genre;
}

/** Unique canonical genres from a comma-separated string. */
function genre_list(?string $genres): array
{
    $out = [];
    foreach (array_filter(array_map('trim', explode(',', (string) $genres))) as $g) {
        $out[canonical_genre($g)] = true;
    }
    return array_keys($out);
}

/** Network logo badge overlaid on a card poster; '' when none of the show's
 *  networks (comma-separated list) has a self-hosted logo, directly or via
 *  its brand group (network_logos.php). An admin-set primary network wins. */
function network_badge_html(?string $network, ?string $primary = null): string
{
    require_once __DIR__ . '/network_logos.php';
    $badge = network_badge_info($network, $primary);
    return $badge
        ? '<img class="net-badge" src="' . htmlspecialchars($badge[1]) . '" alt="' . htmlspecialchars($badge[0]) . '" loading="lazy">'
        : '';
}

/** Shared public show-card markup (browse page, landing page). */
function show_card_html(array $show, string $href): string
{
    $name = htmlspecialchars($show['name']);
    $img = $show['image_url']
        ? '<img src="' . htmlspecialchars($show['image_url']) . '" alt="' . $name . '" loading="lazy">'
        : '<div class="no-poster">' . t('no_image') . '</div>';
    $label = status_label($show['status'] ?? null);
    $badge = $label
        ? '<span class="status status-' . htmlspecialchars($show['status']) . '">' . $label . '</span>'
        : '';
    $rating = !empty($show['rating'])
        ? '<span class="card-rating">⭐ ' . number_format((float) $show['rating'], 1) . '</span>'
        : '';
    $meta = ($badge || $rating) ? '<div class="card-meta">' . $rating . $badge . '</div>' : '';
    require_once __DIR__ . '/network_logos.php';
    $primary = $show['primary_network'] ?? null;
    $data = ' data-network="' . htmlspecialchars(implode(', ', network_names($show['network'] ?? null, $primary))) . '"'
        . ' data-genres="' . htmlspecialchars(implode(', ', genre_list($show['genres'] ?? null))) . '"'
        . ' data-status="' . htmlspecialchars($show['status'] ?? '') . '"';
    return '<div class="show-card"' . $data . '>' . network_badge_html($show['network'] ?? null, $primary)
        . '<a href="' . htmlspecialchars($href) . '">'
        . $img . '<h3>' . $name . '</h3></a>' . $meta . '</div>';
}

/** Single-line plain-text excerpt for meta descriptions, cut at a word. */
function text_excerpt(?string $text, int $max = 155): string
{
    $text = trim(preg_replace('/\s+/u', ' ', (string) $text));
    if ($text === '' || mb_strlen($text) <= $max) {
        return $text;
    }
    $cut = mb_substr($text, 0, $max);
    return preg_replace('/\s+\S*$/u', '', $cut) . '…';
}

/**
 * Map provider status vocabularies (TVmaze: "Running", "To Be Determined", …;
 * TMDB: "Returning Series", "In Production", …) to one canonical set:
 * running | ended | canceled | upcoming | unknown. Null when no status given.
 */
function normalize_show_status(?string $status): ?string
{
    $key = strtolower(trim((string) $status));
    if ($key === '') {
        return null;
    }
    return [
        'running' => 'running',
        'ended' => 'ended',
        'canceled' => 'canceled',
        'upcoming' => 'upcoming',
        'unknown' => 'unknown',
        'returning series' => 'running',
        'cancelled' => 'canceled',
        'in production' => 'upcoming',
        'planned' => 'upcoming',
        'pilot' => 'upcoming',
        'in development' => 'upcoming',
        'to be determined' => 'unknown',
    ][$key] ?? 'unknown';
}

/** Display label for a canonical status; empty string hides the badge. */
function status_label(?string $status): string
{
    return match ($status) {
        'running' => t('status_running'),
        'ended' => t('status_ended'),
        'canceled' => t('status_canceled'),
        'upcoming' => t('status_upcoming'),
        default => '',
    };
}

/**
 * Map TMDB's movie status vocabulary ("Released", "Post Production",
 * "In Production", "Planned", "Rumored", "Canceled") to one canonical set:
 * released | upcoming | canceled | unknown. Null when no status given.
 */
function normalize_movie_status(?string $status): ?string
{
    $key = strtolower(trim((string) $status));
    if ($key === '') {
        return null;
    }
    return [
        'released' => 'released',
        'post production' => 'upcoming',
        'in production' => 'upcoming',
        'planned' => 'upcoming',
        'rumored' => 'upcoming',
        'announced' => 'upcoming',
        'upcoming' => 'upcoming',
        'canceled' => 'canceled',
        'cancelled' => 'canceled',
    ][$key] ?? 'unknown';
}

/** Display label for a canonical movie status; empty string hides the badge. */
function movie_status_label(?string $status): string
{
    return match ($status) {
        'released' => t('movie_status_released'),
        'upcoming' => t('status_upcoming'),
        'canceled' => t('status_canceled'),
        default => '',
    };
}

/** Read and validate a JSON POST body for API endpoints. CSRF token comes in the X-CSRF-Token header. */
function read_json_post(): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['error' => 'POST required'], 405);
    }
    if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        json_response(['error' => 'Invalid CSRF token'], 403);
    }
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        json_response(['error' => 'Invalid JSON body'], 400);
    }
    return $data;
}
