<?php
// The background worker: scheduled freshness plus the "Refresh all shows"
// requests. Cron runs it every minute; a run that finds the previous one still
// working exits at once.
//
// A run works off, in this order:
//   1. the open "Refresh all shows" requests (includes/refresh.php), oldest
//      first: every show the user tracks, whatever its status or cooldown;
//   2. every show that is due, most important first, until none is left. A
//      request filed meanwhile is picked up between two shows, so it never
//      waits for a long scheduled run to end.
//
// Usage: php scripts/refresh_worker.php            refresh what is waiting
//        php scripts/refresh_worker.php --dry-run  only list what is waiting
//
// Output is one line per refreshed show plus a summary — nothing at all when
// there was nothing to do — so cron can append it straight to a log file.

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../includes/importer.php';
require __DIR__ . '/../includes/refresh.php';

const REFRESH_ACTIVE_HOURS = 12;    // cooldown while episodes air around now
const REFRESH_IDLE_HOURS = 48;      // cooldown between seasons
const REFRESH_FINISHED_HOURS = 168; // cooldown of ended/canceled shows: a weekly look for renewals and status corrections
const REFRESH_AIRING_DAYS = 14;     // "around now": an episode airs within ± this many days
const REFRESH_BATCH = 50;           // candidates fetched per query

/** SQL: show `s` is over as far as the providers know (0, never NULL). */
const REFRESH_FINISHED_SQL = "(s.status IS NOT NULL AND s.status IN ('ended', 'canceled'))";

/** SQL: show `s` has an episode airing within REFRESH_AIRING_DAYS of today. */
function refresh_airing_sql(): string
{
    return 'EXISTS (SELECT 1 FROM episodes e WHERE e.show_imdb_id = s.imdb_id
        AND e.airdate BETWEEN CURDATE() - INTERVAL ' . REFRESH_AIRING_DAYS . ' DAY
                          AND CURDATE() + INTERVAL ' . REFRESH_AIRING_DAYS . ' DAY)';
}

/**
 * SQL: show `s` is due a refresh — past its cooldown. Short while airing (the
 * next episode may be listed any day), whatever the status: a canceled show
 * can still have episodes left to air. Otherwise long between seasons (only a
 * new season can appear), and a week once ended or canceled, so a renewal or a
 * corrected status still arrives. Shows still in their first import
 * (synced_at NULL) are left to it.
 */
function refresh_due_sql(): string
{
    return 's.synced_at IS NOT NULL
        AND s.synced_at < NOW() - INTERVAL IF(' . refresh_airing_sql() . ', ' . REFRESH_ACTIVE_HOURS . ',
            IF(' . REFRESH_FINISHED_SQL . ', ' . REFRESH_FINISHED_HOURS . ', ' . REFRESH_IDLE_HOURS . ')) HOUR';
}

/**
 * Due shows, best first: tracked by someone, then airing, then unfinished,
 * then longest unrefreshed. $all: the whole queue instead of one batch.
 */
function refresh_due_shows(PDO $pdo, bool $all = false): array
{
    return $pdo->query(
        'SELECT s.imdb_id, s.name FROM shows s WHERE ' . refresh_due_sql() . '
         ORDER BY EXISTS (SELECT 1 FROM user_shows us WHERE us.show_imdb_id = s.imdb_id) DESC,
                  ' . refresh_airing_sql() . ' DESC, ' . REFRESH_FINISHED_SQL . ' ASC, s.synced_at ASC'
        . ($all ? '' : ' LIMIT ' . REFRESH_BATCH)
    )->fetchAll();
}

/** Users with an open "Refresh all shows" request, oldest request first. */
function refresh_open_requests(PDO $pdo): array
{
    return array_map('intval', $pdo->query(
        'SELECT user_id FROM refresh_requests WHERE finished_at IS NULL ORDER BY requested_at, user_id'
    )->fetchAll(PDO::FETCH_COLUMN));
}

/** The shows the user's open request still awaits: longest unrefreshed first,
 *  never-synced ones ahead of all. */
function refresh_request_shows(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT s.imdb_id, s.name FROM refresh_requests r
         JOIN user_shows us ON us.user_id = r.user_id
         JOIN shows s ON s.imdb_id = us.show_imdb_id
         WHERE r.user_id = ? AND r.finished_at IS NULL AND ' . REFRESH_PENDING_SQL . '
         ORDER BY s.synced_at IS NULL DESC, s.synced_at ASC'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/** What My Shows sees of a show: its episode list (count, numbering, air
 *  dates/times) and status — it changes when a refresh brought anything new. */
function refresh_fingerprint(PDO $pdo, string $imdbId): string
{
    $stmt = $pdo->prepare(
        "SELECT CONCAT_WS(':', COALESCE(MAX(s.status), ''), COUNT(e.id),
                          COALESCE(SUM(CRC32(CONCAT_WS('|', e.season, e.number, e.airdate, e.airstamp))), 0))
         FROM shows s LEFT JOIN episodes e ON e.show_imdb_id = s.imdb_id
         WHERE s.imdb_id = ?"
    );
    $stmt->execute([$imdbId]);
    return (string) $stmt->fetchColumn();
}

function refresh_log(string $line): void
{
    echo gmdate('Y-m-d H:i:s\Z') . ' ' . $line . "\n";
}

/**
 * Re-import a show this run has claimed and log the outcome; false when the
 * import failed. When the show's episode list or status changed, the open
 * requests of the users tracking it are told — their My Shows reloads on it.
 * No pruning here: a provider hiccup during a bulk run would otherwise drop
 * episodes across every show at once (the per-show Refresh button prunes).
 */
function refresh_import(string $imdbId, string $name, string $for = ''): bool
{
    $t = microtime(true);
    try {
        // db_live(): the previous import may have outlasted the connection.
        $pdo = db_live();
        $before = refresh_fingerprint($pdo, $imdbId);
        import_show($pdo, $imdbId);
        $pdo = db_live();
        if (refresh_fingerprint($pdo, $imdbId) !== $before) {
            $pdo->prepare(
                'UPDATE refresh_requests r
                 JOIN user_shows us ON us.user_id = r.user_id AND us.show_imdb_id = ?
                 SET r.changed = 1 WHERE r.finished_at IS NULL'
            )->execute([$imdbId]);
        }
        refresh_log(sprintf('ok   %s %s (%.1fs)%s', $imdbId, $name, microtime(true) - $t, $for));
        return true;
    } catch (Throwable $e) {
        refresh_log("FAIL $imdbId $name$for: " . $e->getMessage());
        return false;
    }
}

/**
 * Work off every open "Refresh all shows" request, then close it. Each show
 * is claimed atomically (the pending check sits in the WHERE), so one that
 * anything else refreshed since the request is skipped, and a failed import
 * still counts as handled instead of stalling the request.
 */
function refresh_run_requests(array &$stats): void
{
    while ($userIds = refresh_open_requests(db_live())) {
        foreach ($userIds as $userId) {
            $t = microtime(true);
            $handled = 0;
            $pdo = db_live();
            $pdo->prepare('UPDATE refresh_requests SET started_at = NOW() WHERE user_id = ? AND started_at IS NULL')
                ->execute([$userId]);
            foreach (refresh_request_shows($pdo, $userId) as $show) {
                $claim = db_live()->prepare(
                    'UPDATE shows s JOIN refresh_requests r ON r.user_id = ?
                     SET s.synced_at = NOW()
                     WHERE s.imdb_id = ? AND r.finished_at IS NULL AND ' . REFRESH_PENDING_SQL
                );
                $claim->execute([$userId, $show['imdb_id']]);
                if ($claim->rowCount() === 0) {
                    continue;
                }
                $handled++;
                $stats[refresh_import($show['imdb_id'], $show['name'], " [user $userId]") ? 'refreshed' : 'failed']++;
            }
            db_live()->prepare('UPDATE refresh_requests SET finished_at = NOW() WHERE user_id = ? AND finished_at IS NULL')
                ->execute([$userId]);
            refresh_log(sprintf('request of user %d done: %d shows in %.0fs', $userId, $handled, microtime(true) - $t));
        }
    }
}

if (in_array('--dry-run', $argv, true)) {
    $pdo = db();
    foreach (refresh_open_requests($pdo) as $userId) {
        foreach (refresh_request_shows($pdo, $userId) as $show) {
            refresh_log("request of user $userId: {$show['imdb_id']} {$show['name']}");
        }
    }
    foreach (refresh_due_shows($pdo, true) as $show) {
        refresh_log("due {$show['imdb_id']} {$show['name']}");
    }
    exit(0);
}

// One run at a time. The lock is released when the process ends, however it
// ends, so a crashed run never blocks the next one.
$lock = fopen(__DIR__ . '/refresh_worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$started = microtime(true);
$stats = ['refreshed' => 0, 'failed' => 0];

try {
    refresh_run_requests($stats);
    while ($shows = refresh_due_shows(db_live())) {
        foreach ($shows as $show) {
            // Claim atomically (the due check sits in the WHERE): a show a web
            // request refreshed since the SELECT is skipped, and a failed
            // import waits out its cooldown instead of being retried in a loop.
            $claim = db_live()->prepare('UPDATE shows s SET s.synced_at = NOW() WHERE s.imdb_id = ? AND ' . refresh_due_sql());
            $claim->execute([$show['imdb_id']]);
            if ($claim->rowCount() === 0) {
                continue;
            }
            $stats[refresh_import($show['imdb_id'], $show['name']) ? 'refreshed' : 'failed']++;
            // A request filed meanwhile goes ahead of the rest of the queue.
            refresh_run_requests($stats);
        }
    }
} catch (Throwable $e) {
    refresh_log('ABORT ' . $e->getMessage());
    exit(1);
}

if ($stats['refreshed'] || $stats['failed']) {
    refresh_log(sprintf('done: %d refreshed, %d failed in %.0fs', $stats['refreshed'], $stats['failed'], microtime(true) - $started));
}
exit($stats['failed'] ? 1 : 0);
