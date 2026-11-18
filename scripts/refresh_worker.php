<?php
// Scheduled freshness: re-imports every show that is due, most important
// first, until none is left. Cron runs it every 10 minutes; a run that finds
// the previous one still working exits at once.
//
// Usage: php scripts/refresh_worker.php            refresh what is due
//        php scripts/refresh_worker.php --dry-run  only list what is due
//
// Output is one line per refreshed show plus a summary — nothing at all when
// no show was due — so cron can append it straight to a log file.

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../includes/importer.php';

const REFRESH_ACTIVE_HOURS = 12; // cooldown while episodes air around now
const REFRESH_IDLE_HOURS = 48;   // cooldown between seasons
const REFRESH_AIRING_DAYS = 14;  // "around now": an episode airs within ± this many days
const REFRESH_BATCH = 50;        // candidates fetched per query

/** SQL: show `s` has an episode airing within REFRESH_AIRING_DAYS of today. */
function refresh_airing_sql(): string
{
    return 'EXISTS (SELECT 1 FROM episodes e WHERE e.show_imdb_id = s.imdb_id
        AND e.airdate BETWEEN CURDATE() - INTERVAL ' . REFRESH_AIRING_DAYS . ' DAY
                          AND CURDATE() + INTERVAL ' . REFRESH_AIRING_DAYS . ' DAY)';
}

/**
 * SQL: show `s` is due a refresh — not known to be finished, and past its
 * cooldown (short while airing: the next episode may be listed any day; long
 * between seasons: only a new season can appear). Shows still in their first
 * import (synced_at NULL) are left to it.
 */
function refresh_due_sql(): string
{
    return "s.synced_at IS NOT NULL
        AND (s.status IS NULL OR s.status NOT IN ('ended', 'canceled'))
        AND s.synced_at < NOW() - INTERVAL IF(" . refresh_airing_sql() . ', '
        . REFRESH_ACTIVE_HOURS . ', ' . REFRESH_IDLE_HOURS . ') HOUR';
}

/** Due shows, best first: tracked by someone, then airing, then longest unrefreshed. */
function refresh_due_shows(PDO $pdo): array
{
    return $pdo->query(
        'SELECT s.imdb_id, s.name FROM shows s WHERE ' . refresh_due_sql() . '
         ORDER BY EXISTS (SELECT 1 FROM user_shows us WHERE us.show_imdb_id = s.imdb_id) DESC,
                  ' . refresh_airing_sql() . ' DESC, s.synced_at ASC
         LIMIT ' . REFRESH_BATCH
    )->fetchAll();
}

function refresh_log(string $line): void
{
    echo date('Y-m-d H:i:s') . ' ' . $line . "\n";
}

$dryRun = in_array('--dry-run', $argv, true);

// One run at a time. The lock is released when the process ends, however it
// ends, so a crashed run never blocks the next one.
$lock = fopen(__DIR__ . '/refresh_worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$started = microtime(true);
$refreshed = 0;
$failed = 0;

try {
    while ($shows = refresh_due_shows(db_live())) {
        foreach ($shows as $show) {
            $imdbId = $show['imdb_id'];
            if ($dryRun) {
                refresh_log("due $imdbId {$show['name']}");
                continue;
            }
            // Claim atomically (the due check sits in the WHERE): a show a web
            // request refreshed since the SELECT is skipped, and a failed
            // import waits out its cooldown instead of being retried in a loop.
            // db_live(): the previous import may have outlasted the connection.
            $pdo = db_live();
            $claim = $pdo->prepare('UPDATE shows s SET s.synced_at = NOW() WHERE s.imdb_id = ? AND ' . refresh_due_sql());
            $claim->execute([$imdbId]);
            if ($claim->rowCount() === 0) {
                continue;
            }
            $t = microtime(true);
            try {
                import_show($pdo, $imdbId);
                $refreshed++;
                refresh_log(sprintf('ok   %s %s (%.1fs)', $imdbId, $show['name'], microtime(true) - $t));
            } catch (Throwable $e) {
                $failed++;
                refresh_log("FAIL $imdbId {$show['name']}: " . $e->getMessage());
            }
        }
        if ($dryRun) {
            break;
        }
    }
} catch (Throwable $e) {
    refresh_log('ABORT ' . $e->getMessage());
    exit(1);
}

if ($refreshed || $failed) {
    refresh_log(sprintf('done: %d refreshed, %d failed in %.0fs', $refreshed, $failed, microtime(true) - $started));
}
exit($failed ? 1 : 0);
