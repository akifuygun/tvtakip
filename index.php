<?php
require_once __DIR__ . '/includes/auth.php';

$items = [];
$unsynced = [];
if (is_logged_in()) {
    // For each tracked show: its earliest aired episode that isn't watched yet.
    $stmt = db()->prepare(
        'SELECT e.id, e.imdb_id, e.season, e.number, e.name AS ep_name, e.airdate,
                s.imdb_id AS show_imdb_id, s.name AS show_name, s.image_url
         FROM user_shows us
         JOIN shows s ON s.imdb_id = us.show_imdb_id
         JOIN episodes e ON e.show_imdb_id = us.show_imdb_id
         LEFT JOIN watched_episodes we ON we.episode_id = e.id AND we.user_id = us.user_id
         WHERE us.user_id = ? AND we.episode_id IS NULL
           AND e.airdate IS NOT NULL AND e.airdate <= ?
         ORDER BY e.airdate, e.season, e.number'
    );
    $stmt->execute([current_user_id(), today()]);
    foreach ($stmt->fetchAll() as $row) {
        if (!isset($items[$row['show_imdb_id']])) {
            $items[$row['show_imdb_id']] = $row;
        }
    }
    // Oldest pending episode first.
    usort($items, static fn($a, $b) => strcmp($a['airdate'], $b['airdate']));

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
            <a class="button" href="register.php"><?= t('get_started') ?></a>
            <a class="button button-secondary" href="login.php"><?= t('login') ?></a>
        </p>
    </div>
<?php else: ?>
    <h1><?= t('calendar_title') ?></h1>
    <p class="muted"><?= t('calendar_sub') ?></p>

    <?php if ($unsynced): ?>
        <div class="notice">
            <?= t('notice_no_data') ?>
            <?php foreach ($unsynced as $i => $s): ?>
                <a href="show.php?id=<?= htmlspecialchars($s['imdb_id']) ?>"><?= htmlspecialchars($s['name']) ?></a><?= $i < count($unsynced) - 1 ? ', ' : '' ?>
            <?php endforeach; ?>
            <?= count($unsynced) === 1 ? t('notice_open_one') : t('notice_open_many') ?>
        </div>
    <?php endif; ?>

    <?php if (!$items): ?>
        <div class="hero">
            <h2><?= t('all_caught_up') ?></h2>
            <p><?= t('no_unwatched') ?> <a href="search.php"><?= t('find_more') ?></a>.</p>
        </div>
    <?php else: ?>
        <div id="calendar" class="cal-grid">
            <?php foreach ($items as $ep): ?>
                <?php
                $showUrl = 'show.php?id=' . htmlspecialchars($ep['show_imdb_id']);
                $code = 'S' . str_pad((string) $ep['season'], 2, '0', STR_PAD_LEFT)
                      . 'E' . str_pad((string) $ep['number'], 2, '0', STR_PAD_LEFT);
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
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
