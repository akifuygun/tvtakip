<?php
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function current_username(): ?string
{
    return $_SESSION['username'] ?? null;
}

function is_logged_in(): bool
{
    return current_user_id() !== null;
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
    return [
        'running' => 'Running',
        'ended' => 'Ended',
        'canceled' => 'Canceled',
        'upcoming' => 'Upcoming',
    ][$status] ?? '';
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
