<?php
// The "Refresh all shows" button on My Shows: a forced re-import of EVERY
// show the caller tracks, cooldown or not, ended and canceled too. Nothing is
// imported here — the request is recorded and scripts/refresh_worker.php
// (cron) works it off, so the run carries on with the page closed.
//
//   POST {}  (login + CSRF)  file a request; a no-op while one is still open
//   GET      (login)         read its progress
//     -> {active, started, done, total, changed}   see refresh_request_state()
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/refresh.php';
require_login_json();
$userId = current_user_id();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        read_json_post();
        refresh_request_open($userId);
    }
    $state = refresh_request_state($userId);
} catch (PDOException $e) {
    error_log('[tvtrack] refresh-all failed: ' . $e->getMessage());
    json_response(['error' => 'Refresh failed'], 500);
}

json_response($state);
