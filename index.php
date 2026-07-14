<?php
require_once __DIR__ . '/includes/auth.php';

$items = [];
$unsynced = [];
$popular = [];
if (!is_logged_in()) {
    // Landing page: most-tracked running/upcoming shows with posters,
    // linking to the public series pages.
    $popular = db()->query(
        "SELECT s.imdb_id, s.name, s.image_url, s.status, s.rating, COUNT(us.user_id) AS trackers
         FROM shows s LEFT JOIN user_shows us ON us.show_imdb_id = s.imdb_id
         WHERE s.image_url IS NOT NULL AND s.image_url <> ''
           AND s.status IN ('running', 'upcoming')
         GROUP BY s.imdb_id, s.name, s.image_url, s.status, s.rating
         ORDER BY trackers DESC, s.name
         LIMIT 10"
    )->fetchAll();
}
if (is_logged_in()) {
    // For each tracked show, its earliest aired-but-unwatched episode — resolved
    // in SQL (one row per show) instead of fetching the whole aired backlog and
    // de-duping in PHP. Oldest pending episode first.
    $stmt = db()->prepare(
        'SELECT e.id, e.imdb_id, e.season, e.number, e.name AS ep_name, e.airdate,
                s.imdb_id AS show_imdb_id, s.name AS show_name, s.image_url
         FROM user_shows us
         JOIN shows s ON s.imdb_id = us.show_imdb_id
         JOIN episodes e ON e.id = (
             SELECT e2.id FROM episodes e2
             LEFT JOIN watched_episodes we2 ON we2.episode_id = e2.id AND we2.user_id = us.user_id
             WHERE e2.show_imdb_id = us.show_imdb_id
               AND we2.episode_id IS NULL
               AND e2.airdate IS NOT NULL AND ' . aired_sql('e2') . '
             ORDER BY e2.airdate, e2.season, e2.number LIMIT 1
         )
         WHERE us.user_id = ?
         ORDER BY e.airdate, e.season, e.number'
    );
    $stmt->execute([today(), current_user_id()]);
    $items = $stmt->fetchAll();

    // Nothing left to watch? Fall back to what's coming up next — one upcoming
    // (not-yet-aired) episode per tracked show, soonest first — so the calendar
    // is useful instead of just "all caught up".
    $upcoming = [];
    if (!$items) {
        $stmt = db()->prepare(
            "SELECT e.season, e.number, e.airdate, e.airstamp,
                    s.imdb_id AS show_imdb_id, s.name AS show_name, s.image_url
             FROM user_shows us
             JOIN shows s ON s.imdb_id = us.show_imdb_id
             JOIN episodes e ON e.id = (
                 SELECT e2.id FROM episodes e2
                 WHERE e2.show_imdb_id = us.show_imdb_id
                   AND ((e2.airstamp IS NOT NULL AND e2.airstamp > UTC_TIMESTAMP())
                        OR (e2.airstamp IS NULL AND e2.airdate IS NOT NULL AND e2.airdate > ?))
                 ORDER BY COALESCE(e2.airstamp, CONCAT(e2.airdate, ' 00:00:00')) ASC LIMIT 1
             )
             WHERE us.user_id = ?
             ORDER BY COALESCE(e.airstamp, CONCAT(e.airdate, ' 00:00:00')) ASC
             LIMIT 12"
        );
        $stmt->execute([today(), current_user_id()]);
        $upcoming = $stmt->fetchAll();
    }

    // Tracked shows whose episodes were never imported (show page never opened).
    $stmt = db()->prepare(
        'SELECT s.imdb_id, s.name FROM user_shows us
         JOIN shows s ON s.imdb_id = us.show_imdb_id
         WHERE us.user_id = ? AND s.synced_at IS NULL ORDER BY s.name'
    );
    $stmt->execute([current_user_id()]);
    $unsynced = $stmt->fetchAll();
}

if (is_logged_in()) {
    $pageTitle = t('calendar_title');
    $noindex = true; // personal, gated content
} else {
    $pageTitle = t('welcome_h1'); // public landing — the one indexable page
}
require __DIR__ . '/includes/header.php';
?>
<?php if (!is_logged_in()): ?>
    <div class="hero">
        <h1><?= t('welcome_h1') ?></h1>
        <p><?= t('welcome_p') ?></p>
        <p>
            <a class="button" href="/register.php"><?= t('get_started') ?></a>
            <a class="button button-secondary" href="/login.php"><?= t('login') ?></a>
        </p>
    </div>

    <section class="landing-section">
        <h2><?= t('features_title') ?></h2>
        <div class="features">
            <?php foreach ([
                ['📅', 'feat_calendar_t', 'feat_calendar_d'],
                ['✅', 'feat_track_t', 'feat_track_d'],
                ['🔍', 'feat_search_t', 'feat_search_d'],
                ['🎬', 'feat_imdb_t', 'feat_imdb_d'],
                ['🌍', 'feat_lang_t', 'feat_lang_d'],
                ['📱', 'feat_pwa_t', 'feat_pwa_d'],
            ] as [$icon, $tKey, $dKey]): ?>
                <div class="feature-card">
                    <span class="feat-icon"><?= $icon ?></span>
                    <h3><?= t($tKey) ?></h3>
                    <p><?= t($dKey) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ($popular): ?>
        <section class="landing-section">
            <h2><?= t('popular_title') ?></h2>
            <div class="show-grid">
                <?php foreach ($popular as $show): ?>
                    <?= show_card_html($show, series_url($show['imdb_id'])) ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <div class="cta-row">
        <a class="button button-secondary" href="<?= lang_path('/browse') ?>"><?= t('browse_all_shows') ?></a>
        <a class="button button-secondary" href="<?= lang_path('/upcoming') ?>"><?= t('see_upcoming') ?></a>
    </div>
<?php else: ?>
    <h1><?= t('calendar_title') ?></h1>
    <p class="muted"><?= t('calendar_sub') ?></p>

    <?php if ($unsynced): ?>
        <div class="notice">
            <?= t('notice_no_data') ?>
            <?php foreach ($unsynced as $i => $s): ?>
                <a href="<?= htmlspecialchars(series_url($s['imdb_id'])) ?>"><?= htmlspecialchars($s['name']) ?></a><?= $i < count($unsynced) - 1 ? ', ' : '' ?>
            <?php endforeach; ?>
            <?= count($unsynced) === 1 ? t('notice_open_one') : t('notice_open_many') ?>
        </div>
    <?php endif; ?>

    <?php if ($items): ?>
        <div id="calendar" class="cal-grid">
            <?php foreach ($items as $ep): ?>
                <?php
                $showUrl = htmlspecialchars(series_url($ep['show_imdb_id']));
                $code = episode_code((int) $ep['season'], (int) $ep['number']);
                ?>
                <div class="show-card">
                    <a href="<?= $showUrl ?>">
                        <?php if ($ep['image_url']): ?>
                            <img src="<?= htmlspecialchars($ep['image_url']) ?>" alt="">
                        <?php else: ?>
                            <div class="no-poster"><?= t('no_image') ?></div>
                        <?php endif; ?>
                    </a>
                    <h3><a href="<?= $showUrl ?>"><?= htmlspecialchars($ep['show_name']) ?></a></h3>
                    <span class="muted"><?= $code ?></span>
                    <button class="button button-small cal-watch-btn" data-episode-id="<?= (int) $ep['id'] ?>"><?= t('mark_watched') ?></button>
                </div>
            <?php endforeach; ?>
        </div>
    <?php elseif ($upcoming): ?>
        <div class="hero">
            <h2><?= t('all_caught_up') ?></h2>
            <p class="muted"><?= t('coming_up') ?></p>
        </div>
        <div class="cal-grid">
            <?php foreach ($upcoming as $ep): ?>
                <?php
                $showUrl = htmlspecialchars(series_url($ep['show_imdb_id']));
                $code = episode_code((int) $ep['season'], (int) $ep['number']);
                // Show the air day in the viewer's timezone when an exact time is known.
                $when = $ep['airstamp']
                    ? (new DateTimeImmutable($ep['airstamp'], new DateTimeZone('UTC')))
                        ->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d')
                    : $ep['airdate'];
                ?>
                <div class="show-card">
                    <a href="<?= $showUrl ?>">
                        <?php if ($ep['image_url']): ?>
                            <img src="<?= htmlspecialchars($ep['image_url']) ?>" alt="">
                        <?php else: ?>
                            <div class="no-poster"><?= t('no_image') ?></div>
                        <?php endif; ?>
                    </a>
                    <h3><a href="<?= $showUrl ?>"><?= htmlspecialchars($ep['show_name']) ?></a></h3>
                    <span class="muted"><?= $code ?></span>
                    <span class="next-ep">📅 <?= htmlspecialchars(format_date($when)) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="cta-row">
            <a class="button button-secondary" href="/upcoming"><?= t('see_upcoming') ?></a>
        </div>
    <?php else: ?>
        <div class="hero">
            <h2><?= t('all_caught_up') ?></h2>
            <p><?= t('no_unwatched') ?> <a href="/search.php"><?= t('find_more') ?></a>.</p>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
