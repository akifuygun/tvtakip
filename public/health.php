<?php
// GET /health — what an uptime monitor asks: 200 {"status":"ok"} when PHP runs
// and the database answers, 503 {"status":"error"} when the database doesn't.
// Nothing else is looked at, so the answer comes at once: no session, no
// provider, and a database that doesn't accept the connection within
// HEALTH_DB_TIMEOUT seconds counts as down. Why it failed goes to the error
// log, never to the client.
require_once __DIR__ . '/../includes/errors.php';
require_once __DIR__ . '/../includes/db.php';

const HEALTH_DB_TIMEOUT = 5;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

try {
    db_connect(HEALTH_DB_TIMEOUT)->query('SELECT 1');
} catch (Throwable $e) {
    error_log('[tvtrack] health: the database does not answer: ' . $e->getMessage());
    http_response_code(503);
    exit('{"status":"error"}');
}
echo '{"status":"ok"}';
