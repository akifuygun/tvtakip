<?php
// "Refresh all shows" requests (the My Shows button). A request is one row in
// refresh_requests — the user's latest. The button only files it;
// scripts/refresh_worker.php (cron) re-imports every show the user tracks and
// closes it, and the page reads its progress from here.
//
// There are no per-show bookkeeping rows: a tracked show is pending while it
// hasn't been synced since the request was made, so a show anything else
// refreshed meanwhile counts as done, and a run cut short resumes by itself.

require_once __DIR__ . '/db.php';

/** SQL: show `s` still awaits request `r`. */
const REFRESH_PENDING_SQL = '(s.synced_at IS NULL OR s.synced_at < r.requested_at)';

/** File a request for the user. A no-op while their previous one is still open. */
function refresh_request_open(int $userId): void
{
    $pdo = db();
    $pdo->prepare(
        'UPDATE refresh_requests
         SET requested_at = NOW(), started_at = NULL, finished_at = NULL, changed = 0
         WHERE user_id = ? AND finished_at IS NOT NULL'
    )->execute([$userId]);
    $pdo->prepare('INSERT IGNORE INTO refresh_requests (user_id) VALUES (?)')->execute([$userId]);
}

/**
 * The user's latest request as the page shows it:
 *   active  — filed and not finished yet
 *   started — the worker picked it up (false = still queued)
 *   done / total — tracked shows refreshed since it was filed / tracked shows
 *   changed — a refreshed show's episode list or status changed
 */
function refresh_request_state(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT finished_at IS NULL AS active, started_at IS NOT NULL AS started, changed
         FROM refresh_requests WHERE user_id = ?'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    $state = [
        'active' => (bool) ($row['active'] ?? false),
        'started' => (bool) ($row['started'] ?? false),
        'done' => 0,
        'total' => 0,
        'changed' => (bool) ($row['changed'] ?? false),
    ];
    if ($state['active']) {
        $stmt = db()->prepare(
            'SELECT COUNT(*), COALESCE(SUM(NOT ' . REFRESH_PENDING_SQL . '), 0)
             FROM refresh_requests r
             JOIN user_shows us ON us.user_id = r.user_id
             JOIN shows s ON s.imdb_id = us.show_imdb_id
             WHERE r.user_id = ?'
        );
        $stmt->execute([$userId]);
        [$state['total'], $state['done']] = array_map('intval', $stmt->fetch(PDO::FETCH_NUM));
    }
    return $state;
}
