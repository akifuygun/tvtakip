<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$stmt = db()->prepare(
    'SELECT s.imdb_id, s.name, s.image_url, s.status,
            (SELECT MIN(e.airdate) FROM episodes e
             WHERE e.show_imdb_id = s.imdb_id AND e.airdate >= ?) AS next_airdate
     FROM user_shows us JOIN shows s ON s.imdb_id = us.show_imdb_id
     WHERE us.user_id = ? ORDER BY s.name'
);
$stmt->execute([today(), current_user_id()]);
$shows = $stmt->fetchAll();

/** "Airs today" / "N days remaining" label for a coming airdate. */
function next_episode_label(?string $airdate): string
{
    if (!$airdate) {
        return '';
    }
    $days = (int) (new DateTimeImmutable('today'))->diff(new DateTimeImmutable($airdate))->format('%r%a');
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
        <p><a class="button" href="search.php"><?= t('search_for_show') ?></a></p>
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
                        <?php if ($label = next_episode_label($show['next_airdate'])): ?>
                            <span class="next-ep">📅 <?= $label ?></span>
                        <?php endif; ?>
                        <button class="button button-small button-danger untrack-btn"
                                data-show-id="<?= $imdbId ?>"><?= t('untrack') ?></button>
                    </div>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endforeach; ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
