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
const ASSET_VERSION = '36';

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

/** SxxEyy code for an episode. */
function episode_code(int $season, int $number): string
{
    return sprintf('S%02dE%02d', $season, $number);
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
    $data = ' data-network="' . htmlspecialchars($show['network'] ?? '') . '"'
        . ' data-genres="' . htmlspecialchars(implode(', ', genre_list($show['genres'] ?? null))) . '"'
        . ' data-status="' . htmlspecialchars($show['status'] ?? '') . '"';
    return '<div class="show-card"' . $data . '><a href="' . htmlspecialchars($href) . '">'
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
