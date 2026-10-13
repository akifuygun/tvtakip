<?php
// Cron-free freshness. The host (InfinityFree free tier) has no cron and bans
// automated periodic requests, so refreshes ride on real page views instead:
//
//   GET /api/tick.php             app.js, once per browser session: the
//                                 caller's own stale tracked shows first, then
//                                 the most-stale shows site-wide.
//   GET /api/tick.php?scope=mine  the calendar, on every view: only the
//                                 caller's own shows. `changed` says whether any
//                                 of them gained or moved episodes (the page
//                                 re-renders in place), `more` that some are
//                                 still stale (the page calls again).
//
// Bounded by design: imports only start within TICK_BUDGET_SECONDS (the host
// kills long requests), and every show is claimed atomically and then skipped
// for its cooldown, so even hammering this only keeps the cache fresh. No
// login required (freshness benefits the public pages too), no client input
// is trusted beyond the scope flag.
require_once __DIR__ . '/../includes/importer.php';

header('Content-Type: application/json; charset=utf-8');

const TICK_ACTIVE_HOURS = 12;   // cooldown while episodes air around now
const TICK_IDLE_HOURS = 48;     // cooldown between seasons
const TICK_AIRING_DAYS = 14;    // "around now": an episode airs within ± this many days
const TICK_BUDGET_SECONDS = 10; // no new import starts after this
const TICK_BATCH = 50;          // candidates considered per call

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'GET only']);
    exit;
}

$userId = current_user_id();
$mineOnly = ($_GET['scope'] ?? '') === 'mine';
// Nothing below touches the session: release its lock so the visitor's other
// requests don't queue behind these imports.
session_write_close();
// A started import finishes even if the visitor navigates away mid-request.
ignore_user_abort(true);

/** SQL: show `s` has an episode airing within TICK_AIRING_DAYS of today. */
function tick_airing_sql(): string
{
    return 'EXISTS (SELECT 1 FROM episodes e WHERE e.show_imdb_id = s.imdb_id
        AND e.airdate BETWEEN CURDATE() - INTERVAL ' . TICK_AIRING_DAYS . ' DAY
                          AND CURDATE() + INTERVAL ' . TICK_AIRING_DAYS . ' DAY)';
}

/**
 * SQL: show `s` is due a refresh — not known to be finished, and past its
 * cooldown (short while airing: the next episode may be listed any day; long
 * between seasons: only a new season can appear). Shows still in their first
 * import (synced_at NULL) are left to it.
 */
function tick_due_sql(): string
{
    return "s.synced_at IS NOT NULL
        AND (s.status IS NULL OR s.status NOT IN ('ended', 'canceled'))
        AND s.synced_at < NOW() - INTERVAL IF(" . tick_airing_sql() . ', '
        . TICK_ACTIVE_HOURS . ', ' . TICK_IDLE_HOURS . ') HOUR';
}

/** What the calendar sees of a show's episodes: changes when one appears,
 *  disappears, or moves to another air date/time. */
function tick_fingerprint(string $imdbId): string
{
    $stmt = db()->prepare(
        "SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', season, number, airdate, airstamp))), 0))
         FROM episodes WHERE show_imdb_id = ?"
    );
    $stmt->execute([$imdbId]);
    return (string) $stmt->fetchColumn();
}

/**
 * Refresh candidate shows (best first) until the budget is spent. Returns
 * [refreshed ids, whether any episode list changed, whether candidates remain].
 */
function tick_run(array $imdbIds, float $deadline): array
{
    $refreshed = [];
    $changed = false;
    foreach ($imdbIds as $imdbId) {
        if (microtime(true) >= $deadline) {
            return [$refreshed, $changed, true];
        }
        // Claim atomically: the cooldown in the WHERE means only one concurrent
        // ticker wins this show; a loser (rowCount 0) moves on. db_live(): the
        // previous import may have outlasted the connection.
        $pdo = db_live();
        $claim = $pdo->prepare('UPDATE shows s SET s.synced_at = NOW() WHERE s.imdb_id = ? AND ' . tick_due_sql());
        $claim->execute([$imdbId]);
        if ($claim->rowCount() === 0) {
            continue;
        }
        try {
            $before = tick_fingerprint($imdbId);
            import_show($pdo, $imdbId);
            $changed = $changed || tick_fingerprint($imdbId) !== $before;
            $refreshed[] = $imdbId;
        } catch (Throwable $e) {
            // One bad show must not stall the rest; its claim keeps it from
            // being retried before the cooldown passes again.
            error_log("[tvtrack] tick refresh failed for $imdbId: " . $e->getMessage());
        }
    }
    return [$refreshed, $changed, count($imdbIds) >= TICK_BATCH];
}

$deadline = microtime(true) + TICK_BUDGET_SECONDS;
$out = ['refreshed' => [], 'changed' => false, 'more' => false];

try {
    // The caller's own tracked shows first — that's what their calendar shows;
    // airing ones lead. One worker per user, so extra tabs add no load. Like
    // the global lock below, it returns instantly when taken and auto-releases
    // when this request's connection closes.
    $lock = db()->prepare('SELECT GET_LOCK(?, 0)');
    if ($userId !== null && $lock->execute(["tvtrack_tick_u$userId"]) && $lock->fetchColumn()) {
        $stmt = db()->prepare(
            'SELECT s.imdb_id FROM user_shows us JOIN shows s ON s.imdb_id = us.show_imdb_id
             WHERE us.user_id = ? AND ' . tick_due_sql() . '
             ORDER BY ' . tick_airing_sql() . ' DESC, s.synced_at ASC
             LIMIT ' . TICK_BATCH
        );
        $stmt->execute([$userId]);
        [$out['refreshed'], $out['changed'], $out['more']] = tick_run($stmt->fetchAll(PDO::FETCH_COLUMN), $deadline);
    }

    // Then site-wide with whatever budget is left: shows someone tracks before
    // the rest of the catalog. Global single-flight — without it, a burst of
    // concurrent pings would each claim a DIFFERENT stale show and fan out
    // parallel provider imports, exhausting the host's tiny worker pool.
    if (!$mineOnly && !$out['more'] && microtime(true) < $deadline
        && db_live()->query("SELECT GET_LOCK('tvtrack_tick', 0)")->fetchColumn()) {
        $stmt = db()->query(
            'SELECT s.imdb_id FROM shows s WHERE ' . tick_due_sql() . '
             ORDER BY EXISTS (SELECT 1 FROM user_shows us WHERE us.show_imdb_id = s.imdb_id) DESC,
                      ' . tick_airing_sql() . ' DESC, s.synced_at ASC
             LIMIT ' . TICK_BATCH
        );
        [$ids] = tick_run($stmt->fetchAll(PDO::FETCH_COLUMN), $deadline);
        $out['refreshed'] = array_merge($out['refreshed'], $ids);
    }
} catch (Throwable $e) {
    // Best-effort — a refresh failure must never surface to the pinging client.
    error_log('[tvtrack] tick failed: ' . $e->getMessage());
}

echo json_encode($out);
