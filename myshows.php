<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

// The next unaired episode's exact airstamp (and airdate) — same not-yet-aired
// test the show-page countdown uses, so the "days remaining" label agrees with
// it. Both subqueries select from the same row (identical WHERE + ORDER).
$nextEpisodeWhere =
    '((e.airstamp IS NOT NULL AND e.airstamp > UTC_TIMESTAMP())
      OR (e.airstamp IS NULL AND e.airdate IS NOT NULL AND e.airdate >= ?))';
$nextEpisodeOrder = "ORDER BY COALESCE(e.airstamp, CONCAT(e.airdate, ' 00:00:00')) ASC, e.season ASC, e.number ASC LIMIT 1";
$stmt = db()->prepare(
    "SELECT s.imdb_id, s.name, s.image_url, s.status,
            (SELECT e.airstamp FROM episodes e
             WHERE e.show_imdb_id = s.imdb_id AND $nextEpisodeWhere $nextEpisodeOrder) AS next_airstamp,
            (SELECT e.airdate FROM episodes e
             WHERE e.show_imdb_id = s.imdb_id AND $nextEpisodeWhere $nextEpisodeOrder) AS next_airdate,
            (SELECT COUNT(*) FROM episodes e
             WHERE e.show_imdb_id = s.imdb_id AND " . certainly_aired_sql('e') . ") AS aired_count,
            (SELECT COUNT(*) FROM watched_episodes we
             JOIN episodes e2 ON e2.id = we.episode_id
             WHERE e2.show_imdb_id = s.imdb_id AND we.user_id = ? AND " . certainly_aired_sql('e2') . ") AS watched_count
     FROM user_shows us JOIN shows s ON s.imdb_id = us.show_imdb_id
     WHERE us.user_id = ? ORDER BY s.name"
);
$stmt->execute([today(), today(), today(), current_user_id(), today(), current_user_id()]);
$shows = $stmt->fetchAll();

/** Progress bar markup for a show: watched/aired aired episodes + "N behind". */
function progress_html(int $watched, int $aired): string
{
    if ($aired < 1) {
        return '';
    }
    $watched = min($watched, $aired);
    $pct = (int) round($watched / $aired * 100);
    $behind = $aired - $watched;
    $note = $behind === 0
        ? t('caught_up_show')
        : ($behind === 1 ? t('one_behind') : t('n_behind', $behind));
    return '<div class="progress"><div class="progress-bar">'
        . '<div class="progress-fill" style="width:' . $pct . '%"></div></div>'
        . '<div class="progress-label muted">' . $watched . '/' . $aired . ' · ' . htmlspecialchars($note) . '</div></div>';
}

/**
 * "Airs today" / "N days remaining" for the next episode. Uses the exact UTC
 * airstamp converted to the user's timezone (so the day count matches the
 * show-page countdown), falling back to the date-only airdate when unknown.
 */
function next_episode_label(?string $airstamp, ?string $airdate): string
{
    if ($airstamp) {
        $target = (new DateTimeImmutable($airstamp, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()));
    } elseif ($airdate) {
        $target = new DateTimeImmutable($airdate); // default tz = app_timezone()
    } else {
        return '';
    }
    // Whole calendar days between today and the episode's local air day.
    $days = (int) (new DateTimeImmutable('today'))->diff($target->setTime(0, 0))->format('%r%a');
    if ($days < 0) {
        return '';
    }
    if ($days === 0) {
        return t('airs_today');
    }
    return $days === 1 ? t('day_remaining', 1) : t('days_remaining', $days);
}

// Group by status: running | upcoming (incl. unknown) | ended+canceled.
$groups = [
    'running' => ['title' => t('group_running'), 'open' => true, 'shows' => []],
    'upcoming' => ['title' => t('group_upcoming'), 'open' => true, 'shows' => []],
    'finished' => ['title' => t('group_finished'), 'open' => false, 'shows' => []],
];
foreach ($shows as $show) {
    $key = match ($show['status']) {
        'running' => 'running',
        'ended', 'canceled' => 'finished',
        default => 'upcoming',
    };
    $groups[$key]['shows'][] = $show;
}

// Running and Upcoming: soonest next episode / premiere first;
// shows without a scheduled date last.
$byNextAirdate = static function ($a, $b) {
    if ($a['next_airdate'] === $b['next_airdate']) {
        return strcmp($a['name'], $b['name']);
    }
    if ($a['next_airdate'] === null) {
        return 1;
    }
    if ($b['next_airdate'] === null) {
        return -1;
    }
    return strcmp($a['next_airdate'], $b['next_airdate']);
};
usort($groups['running']['shows'], $byNextAirdate);
usort($groups['upcoming']['shows'], $byNextAirdate);

// With nothing running or upcoming, the finished group is all there is — open it.
if (!$groups['running']['shows'] && !$groups['upcoming']['shows']) {
    $groups['finished']['open'] = true;
}

$pageTitle = t('myshows_title');
$noindex = true;
require __DIR__ . '/includes/header.php';
?>
<?php if (!$shows): ?>
    <div class="hero">
        <h1><?= t('no_shows_yet') ?></h1>
        <p><a class="button" href="/search.php"><?= t('search_for_show') ?></a></p>
    </div>
<?php else: ?>
    <h1><?= t('myshows_title') ?></h1>
    <?php foreach ($groups as $group): ?>
        <?php if (!$group['shows']) continue; ?>
        <details class="show-group"<?= $group['open'] ? ' open' : '' ?>>
            <summary><?= $group['title'] ?> (<?= count($group['shows']) ?>)</summary>
            <div class="show-grid">
                <?php foreach ($group['shows'] as $show): ?>
                    <?php $imdbId = htmlspecialchars($show['imdb_id']); ?>
                    <div class="show-card" data-show-id="<?= $imdbId ?>">
                        <a href="<?= htmlspecialchars(series_url($show['imdb_id'])) ?>">
                            <?php if ($show['image_url']): ?>
                                <img src="<?= htmlspecialchars($show['image_url']) ?>" alt="">
                            <?php else: ?>
                                <div class="no-poster"><?= t('no_image') ?></div>
                            <?php endif; ?>
                            <h3><?= htmlspecialchars($show['name']) ?></h3>
                        </a>
                        <?php if (status_label($show['status'])): ?>
                            <span class="status status-<?= htmlspecialchars($show['status']) ?>"><?= status_label($show['status']) ?></span>
                        <?php endif; ?>
                        <?php if ($label = next_episode_label($show['next_airstamp'], $show['next_airdate'])): ?>
                            <span class="next-ep">📅 <?= $label ?></span>
                        <?php endif; ?>
                        <?= progress_html((int) $show['watched_count'], (int) $show['aired_count']) ?>
                        <button class="button button-small button-danger untrack-btn"
                                data-show-id="<?= $imdbId ?>"><?= t('untrack') ?></button>
                    </div>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endforeach; ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
