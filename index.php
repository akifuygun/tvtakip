<?php
require_once __DIR__ . '/includes/calendar.php';

$items = [];
$unsynced = [];
$popular = [];
if (!is_logged_in()) {
    // Landing page: most-tracked running/upcoming shows with posters,
    // linking to the public series pages.
    $popular = db()->query(
        "SELECT s.imdb_id, s.name, s.image_url, s.status, s.rating, s.network, s.primary_network, COUNT(us.user_id) AS trackers
         FROM shows s LEFT JOIN user_shows us ON us.show_imdb_id = s.imdb_id
         WHERE s.image_url IS NOT NULL AND s.image_url <> ''
           AND s.status IN ('running', 'upcoming')
         GROUP BY s.imdb_id, s.name, s.image_url, s.status, s.rating, s.network, s.primary_network
         ORDER BY trackers DESC, s.name
         LIMIT 10"
    )->fetchAll();
}
if (is_logged_in()) {
    $items = calendar_episodes(current_user_id());
    // Nothing left to watch? Fall back to what's coming up next.
    $upcoming = $items ? [] : calendar_upcoming(current_user_id());

    // Unwatched movies from the user's list — released ones first (watchable
    // now, with a Watched button), then not-yet-released ones with their
    // release date (movies with no known date stay off the calendar).
    $stmt = db()->prepare(
        'SELECT m.imdb_id, m.name, m.image_url, m.released
         FROM user_movies um JOIN movies m ON m.imdb_id = um.movie_imdb_id
         WHERE um.user_id = ? AND um.watched = 0 AND m.released IS NOT NULL
         ORDER BY (m.released > ?), um.added_at, m.name'
    );
    $stmt->execute([current_user_id(), today()]);
    $moviesToWatch = $stmt->fetchAll();

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
    <p class="muted"><?= t('calendar_sub') ?> <a href="/tv"><?= t('tv_view') ?></a></p>
    <p id="cal-refresh" class="muted" hidden><?= t('cal_checking') ?></p>

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
                // Admin-only Watch links, only once the episode is markable (not airing later today).
                $watch = $ep['airstamp'] !== null && $ep['airstamp'] > gmdate('Y-m-d H:i:s') ? []
                    : watch_links(['name' => $ep['show_name']] + $ep, (int) $ep['season'], (int) $ep['number'], $ep['finale']);
                ?>
                <div class="show-card">
                    <?= network_badge_html($ep['network'], $ep['primary_network']) ?>
                    <?php if ($watch): ?>
                        <div class="watch-links">
                            <?php foreach ($watch as $w): ?>
                                <a class="watch-link" href="<?= htmlspecialchars($w['url']) ?>" target="_blank" rel="noopener noreferrer">▶ <?= htmlspecialchars($w['site']) ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <a href="<?= $showUrl ?>">
                        <?php if ($ep['image_url']): ?>
                            <img src="<?= htmlspecialchars($ep['image_url']) ?>" alt="">
                        <?php else: ?>
                            <div class="no-poster"><?= t('no_image') ?></div>
                        <?php endif; ?>
                    </a>
                    <h3><a href="<?= $showUrl ?>"><?= htmlspecialchars($ep['show_name']) ?></a></h3>
                    <span class="muted"><?= $code ?> <?= episode_badge_html((int) $ep['season'], (int) $ep['number'], $ep['finale']) ?></span>
                    <?php if ($ep['airstamp'] !== null && $ep['airstamp'] > gmdate('Y-m-d H:i:s')): ?>
                        <?php
                        // Airs later today: not markable until its airstamp, so show the local time.
                        $at = (new DateTimeImmutable($ep['airstamp'], new DateTimeZone('UTC')))
                            ->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('H:i');
                        ?>
                        <span class="next-ep"><?= htmlspecialchars(t('airs_on', $at)) ?></span>
                    <?php else: ?>
                        <button class="button button-small cal-watch-btn" data-episode-id="<?= (int) $ep['id'] ?>"><?= t('mark_watched') ?></button>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php elseif ($upcoming): ?>
        <div class="hero">
            <h2><?= t('all_caught_up') ?></h2>
            <p class="muted"><?= t('coming_up') ?></p>
        </div>
        <h2><?= t('pub_upcoming_title') ?></h2>
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
                    <?= network_badge_html($ep['network'], $ep['primary_network']) ?>
                    <a href="<?= $showUrl ?>">
                        <?php if ($ep['image_url']): ?>
                            <img src="<?= htmlspecialchars($ep['image_url']) ?>" alt="">
                        <?php else: ?>
                            <div class="no-poster"><?= t('no_image') ?></div>
                        <?php endif; ?>
                    </a>
                    <h3><a href="<?= $showUrl ?>"><?= htmlspecialchars($ep['show_name']) ?></a></h3>
                    <span class="muted"><?= $code ?> <?= episode_badge_html((int) $ep['season'], (int) $ep['number'], $ep['finale']) ?></span>
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

    <?php if ($moviesToWatch): ?>
        <div id="cal-movies-section">
            <h2><?= t('cal_movies_title') ?></h2>
            <div class="cal-grid">
                <?php foreach ($moviesToWatch as $mv): ?>
                    <?php $mvUrl = htmlspecialchars(movie_url($mv['imdb_id'])); ?>
                    <div class="show-card">
                        <a href="<?= $mvUrl ?>">
                            <?php if ($mv['image_url']): ?>
                                <img src="<?= htmlspecialchars($mv['image_url']) ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <div class="no-poster"><?= t('no_image') ?></div>
                            <?php endif; ?>
                        </a>
                        <h3><a href="<?= $mvUrl ?>"><?= htmlspecialchars($mv['name']) ?></a></h3>
                        <span class="muted"><?= substr($mv['released'], 0, 4) ?></span>
                        <?php if ($mv['released'] <= today()): ?>
                            <button class="button button-small cal-movie-watch-btn"
                                    data-movie-id="<?= htmlspecialchars($mv['imdb_id']) ?>"><?= t('mark_watched') ?></button>
                        <?php else: ?>
                            <span class="next-ep">📅 <?= htmlspecialchars(format_date($mv['released'])) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
