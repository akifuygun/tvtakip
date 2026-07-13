<?php
// Cron-free freshness. Pinged once per browser session by app.js: refreshes
// the single most-stale running/upcoming show via the normal importer, then
// returns immediately. Over organic traffic the whole running set converges.
//
// Bounded by design — one show per call, each claimed atomically and skipped
// for a cooldown window — so even hammering it only keeps the cache fresh; it
// can never force unbounded re-imports. No login required (freshness benefits
// the public pages too), no client input is trusted.
require_once __DIR__ . '/../includes/importer.php';

header('Content-Type: application/json; charset=utf-8');

const TICK_COOLDOWN_HOURS = 12;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'GET only']);
    exit;
}

try {
    $pdo = db();

    // Global single-flight: only ONE tick import runs at a time across the whole
    // site. Without this, a burst of concurrent pings would each claim a
    // DIFFERENT stale show and fan out many parallel ~30s provider imports,
    // exhausting the host's tiny worker pool. GET_LOCK(…, 0) returns instantly:
    // losers do nothing. The lock auto-releases when this request's connection
    // closes, so a killed request can't strand it.
    if (!$pdo->query("SELECT GET_LOCK('tvtrack_tick', 0)")->fetchColumn()) {
        echo json_encode(['refreshed' => null]);
        exit;
    }

    // Only shows that finished an initial import and are past the cooldown;
    // oldest first so coverage rotates across the catalog.
    $find = $pdo->prepare(
        "SELECT imdb_id FROM shows
         WHERE status IN ('running', 'upcoming')
           AND synced_at IS NOT NULL
           AND synced_at < (NOW() - INTERVAL ? HOUR)
         ORDER BY synced_at ASC LIMIT 1"
    );
    $find->execute([TICK_COOLDOWN_HOURS]);
    $imdbId = $find->fetchColumn();

    if (!$imdbId) {
        echo json_encode(['refreshed' => null]);
        exit;
    }

    // Claim atomically: the cooldown in the WHERE means only one concurrent
    // ticker wins this show; the loser (rowCount 0) simply does nothing.
    $claim = $pdo->prepare(
        "UPDATE shows SET synced_at = NOW()
         WHERE imdb_id = ? AND synced_at < (NOW() - INTERVAL ? HOUR)"
    );
    $claim->execute([$imdbId, TICK_COOLDOWN_HOURS]);
    if ($claim->rowCount() === 0) {
        echo json_encode(['refreshed' => null]);
        exit;
    }

    import_show($pdo, $imdbId);
    echo json_encode(['refreshed' => $imdbId]);
} catch (Throwable $e) {
    // Best-effort — a refresh failure must never surface to the pinging client.
    error_log('[tvtrack] tick refresh failed: ' . $e->getMessage());
    echo json_encode(['refreshed' => null]);
}
