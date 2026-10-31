<?php
// Calendar queries, shared by the calendar (index.php) and its TV view (tv.php)
// so both always list the same episodes.
require_once __DIR__ . '/auth.php';

// Leading sort key: shows that have ended or were canceled go after the ones
// still on air (a show with no status counts as on air).
const CALENDAR_FINISHED_LAST = "COALESCE(s.status IN ('ended', 'canceled'), 0)";

/**
 * For each tracked show, its earliest unwatched episode airing today or
 * earlier (viewer's day) — resolved in SQL (one row per show) instead of
 * fetching the whole backlog and de-duping in PHP. Oldest pending first,
 * finished shows last.
 */
function calendar_episodes(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT e.id, e.imdb_id, e.season, e.number, e.name AS ep_name, e.airdate, e.airstamp, e.finale,
                s.imdb_id AS show_imdb_id, s.name AS show_name, s.image_url, s.network, s.primary_network,
                s.original_name, s.original_language, s.premiered, s.watch_slug
         FROM user_shows us
         JOIN shows s ON s.imdb_id = us.show_imdb_id
         JOIN episodes e ON e.id = (
             SELECT e2.id FROM episodes e2
             LEFT JOIN watched_episodes we2 ON we2.episode_id = e2.id AND we2.user_id = us.user_id
             WHERE e2.show_imdb_id = us.show_imdb_id
               AND we2.episode_id IS NULL
               AND e2.airdate IS NOT NULL AND ' . airs_by_today_sql('e2') . '
             ORDER BY (e2.season = 0), e2.airdate, e2.season, e2.number LIMIT 1
         )
         WHERE us.user_id = ?
         ORDER BY ' . CALENDAR_FINISHED_LAST . ', e.airdate, e.season, e.number'
    );
    $stmt->execute([today_end_utc(), today(), $userId]);
    return $stmt->fetchAll();
}

/**
 * Caught-up fallback: one upcoming (not-yet-aired) episode per tracked show,
 * soonest first, finished shows last — so the calendar is useful instead of
 * just "all caught up".
 */
function calendar_upcoming(int $userId): array
{
    $stmt = db()->prepare(
        "SELECT e.season, e.number, e.name AS ep_name, e.airdate, e.airstamp, e.finale,
                s.imdb_id AS show_imdb_id, s.name AS show_name, s.image_url, s.network, s.primary_network
         FROM user_shows us
         JOIN shows s ON s.imdb_id = us.show_imdb_id
         JOIN episodes e ON e.id = (
             SELECT e2.id FROM episodes e2
             WHERE e2.show_imdb_id = us.show_imdb_id
               AND ((e2.airstamp IS NOT NULL AND e2.airstamp > UTC_TIMESTAMP())
                    OR (e2.airstamp IS NULL AND e2.airdate IS NOT NULL AND e2.airdate >= ?))
             ORDER BY (e2.season = 0), COALESCE(e2.airstamp, CONCAT(e2.airdate, ' 00:00:00')) ASC, e2.season ASC, e2.number ASC LIMIT 1
         )
         WHERE us.user_id = ?
         ORDER BY " . CALENDAR_FINISHED_LAST . ", COALESCE(e.airstamp, CONCAT(e.airdate, ' 00:00:00')) ASC, e.season ASC, e.number ASC
         LIMIT 12"
    );
    $stmt->execute([today(), $userId]);
    return $stmt->fetchAll();
}
