<?php
// The "Refresh all shows" button on My Shows: a forced re-import of EVERY
// show the caller tracks, cooldown or not. The automatic tick (api/tick.php)
// skips fresh, ended and canceled shows, so this is how a renewed "ended" show
// or a provider correction is picked up without opening each show page.
//
//   POST {since?: "YYYY-MM-DD HH:MM:SS"}   (login + CSRF)
//     -> {since, refreshed: [ids], changed, more, busy, done, total}
//
// The host kills long requests, so each call imports only within a budget and
// reports `more`; the client calls again with the same `since` until done.
// `since` is the run's start on the DB clock: a show is due while its
// synced_at predates it, so one the tick or another tab refreshed meanwhile
// is skipped, and an import that failed still counts as done (its claim moved
// synced_at) instead of stalling the run. `busy`: the caller's tick holds the
// per-user lock right now — call again shortly.
//
// Unlike the per-show Refresh this does NOT prune: a provider hiccup during a
// bulk run would otherwise drop episodes across every show at once.
require_once __DIR__ . '/../includes/importer.php';
require_login_json();
$data = read_json_post();
$userId = current_user_id();
// Nothing below touches the session: release its lock so the user's other
// requests don't queue behind these imports.
session_write_close();
// A started import finishes even if the user navigates away mid-request.
ignore_user_abort(true);

const REFRESH_BUDGET_SECONDS = 10; // no new import starts after this
const REFRESH_BATCH = 50;          // candidates considered per call

$since = $data['since'] ?? null;
if (!is_string($since) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since)) {
    $since = (string) db()->query('SELECT NOW()')->fetchColumn();
}

/** SQL: show `s` still awaits this run (one positional param: $since). */
const REFRESH_DUE_SQL = '(s.synced_at IS NULL OR s.synced_at < ?)';

/** What My Shows sees of a show: its episode list (count, numbering, air
 *  dates/times) and status — changes when a refresh brought anything new. */
function refresh_fingerprint(string $imdbId): string
{
    $stmt = db()->prepare(
        "SELECT CONCAT(s.status, ':', COUNT(e.id), ':',
                       COALESCE(SUM(CRC32(CONCAT_WS('|', e.season, e.number, e.airdate, e.airstamp))), 0))
         FROM shows s LEFT JOIN episodes e ON e.show_imdb_id = s.imdb_id
         WHERE s.imdb_id = ?"
    );
    $stmt->execute([$imdbId]);
    return (string) $stmt->fetchColumn();
}

/** [tracked shows, of which still due] for the progress counter. */
function refresh_counts(int $userId, string $since): array
{
    $stmt = db()->prepare(
        'SELECT COUNT(*), COALESCE(SUM(' . REFRESH_DUE_SQL . '), 0)
         FROM user_shows us JOIN shows s ON s.imdb_id = us.show_imdb_id
         WHERE us.user_id = ?'
    );
    $stmt->execute([$since, $userId]);
    return array_map('intval', $stmt->fetch(PDO::FETCH_NUM));
}

$deadline = microtime(true) + REFRESH_BUDGET_SECONDS;
$out = ['since' => $since, 'refreshed' => [], 'changed' => false, 'more' => false, 'busy' => false];

try {
    // Same per-user lock as the tick's own-shows phase, so a calendar tab's
    // auto-refresh and this run never import the user's shows side by side.
    // Returns instantly when taken and auto-releases when this request's
    // connection closes.
    $lock = db()->prepare('SELECT GET_LOCK(?, 0)');
    $lock->execute(["tvtrack_tick_u$userId"]);
    if (!$lock->fetchColumn()) {
        $out['busy'] = true;
    } else {
        // Longest unrefreshed first (never-synced ones ahead of all), so a
        // run that gets cut short has covered the shows most in need.
        $stmt = db()->prepare(
            'SELECT s.imdb_id FROM user_shows us JOIN shows s ON s.imdb_id = us.show_imdb_id
             WHERE us.user_id = ? AND ' . REFRESH_DUE_SQL . '
             ORDER BY s.synced_at IS NULL DESC, s.synced_at ASC
             LIMIT ' . REFRESH_BATCH
        );
        $stmt->execute([$userId, $since]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $imdbId) {
            if (microtime(true) >= $deadline) {
                break;
            }
            // Claim atomically (the due check sits in the WHERE): a loser lost
            // the show to the tick or another tab since the SELECT and moves
            // on. db_live(): the previous import may have outlasted the
            // connection.
            $pdo = db_live();
            $claim = $pdo->prepare('UPDATE shows s SET s.synced_at = NOW() WHERE s.imdb_id = ? AND ' . REFRESH_DUE_SQL);
            $claim->execute([$imdbId, $since]);
            if ($claim->rowCount() === 0) {
                continue;
            }
            try {
                $before = refresh_fingerprint($imdbId);
                import_show($pdo, $imdbId);
                $out['changed'] = $out['changed'] || refresh_fingerprint($imdbId) !== $before;
                $out['refreshed'][] = $imdbId;
            } catch (Throwable $e) {
                // One bad show must not stall the rest; its claim keeps the
                // run moving past it.
                error_log("[tvtrack] refresh-all failed for $imdbId: " . $e->getMessage());
            }
        }
    }
    [$out['total'], $remaining] = refresh_counts($userId, $since);
    $out['done'] = $out['total'] - $remaining;
    $out['more'] = $remaining > 0;
} catch (Throwable $e) {
    error_log('[tvtrack] refresh-all failed: ' . $e->getMessage());
    json_response(['error' => 'Refresh failed'], 500);
}

json_response($out);
