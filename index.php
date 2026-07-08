<?php
require_once __DIR__ . '/includes/auth.php';

$items = [];
$unsynced = [];
if (is_logged_in()) {
    // For each tracked show: its earliest aired episode that isn't watched yet.
    $stmt = db()->prepare(
        'SELECT e.id, e.imdb_id, e.season, e.number, e.name AS ep_name, e.airdate,
                s.imdb_id AS show_imdb_id, s.name AS show_name
         FROM user_shows us
         JOIN shows s ON s.imdb_id = us.show_imdb_id
         JOIN episodes e ON e.show_imdb_id = us.show_imdb_id
         LEFT JOIN watched_episodes we ON we.episode_id = e.id AND we.user_id = us.user_id
         WHERE us.user_id = ? AND we.episode_id IS NULL
           AND e.airdate IS NOT NULL AND e.airdate <= CURDATE()
         ORDER BY e.airdate, e.season, e.number'
    );
    $stmt->execute([current_user_id()]);
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

$pageTitle = 'Calendar';
require __DIR__ . '/includes/header.php';
?>
<?php if (!is_logged_in()): ?>
    <div class="hero">
        <h1>Track your TV series</h1>
        <p>Search for shows, follow them, and keep track of every episode you've watched.</p>
        <p>
            <a class="button" href="register.php">Get started</a>
            <a class="button button-secondary" href="login.php">Log in</a>
        </p>
    </div>
<?php else: ?>
    <h1>Calendar</h1>
    <p class="muted">The next unwatched episode of each show you track.</p>

    <?php if ($unsynced): ?>
        <div class="notice">
            No episode data yet for
            <?php foreach ($unsynced as $i => $s): ?>
                <a href="show.php?id=<?= htmlspecialchars($s['imdb_id']) ?>"><?= htmlspecialchars($s['name']) ?></a><?= $i < count($unsynced) - 1 ? ', ' : '' ?>
            <?php endforeach; ?>
            — open <?= count($unsynced) === 1 ? 'it' : 'each' ?> once to import episodes.
        </div>
    <?php endif; ?>

    <?php if (!$items): ?>
        <div class="hero">
            <h2>🎉 All caught up!</h2>
            <p>No unwatched aired episodes. <a href="search.php">Find more shows to track</a>.</p>
        </div>
    <?php else: ?>
        <div id="calendar">
            <ul class="episode-list">
                <?php foreach ($items as $ep): ?>
                    <li>
                        <span class="ep-title">
                            <a class="cal-show" href="show.php?id=<?= htmlspecialchars($ep['show_imdb_id']) ?>"><?= htmlspecialchars($ep['show_name']) ?></a>
                            S<?= str_pad((string) $ep['season'], 2, '0', STR_PAD_LEFT) ?>E<?= str_pad((string) $ep['number'], 2, '0', STR_PAD_LEFT) ?>
                            <?= htmlspecialchars($ep['ep_name'] ?? '') ?>
                            <span class="muted">— <?= date('j M Y', strtotime($ep['airdate'])) ?></span>
                            <?php if ($ep['imdb_id']): ?>
                                <a class="imdb-link" href="https://www.imdb.com/title/<?= htmlspecialchars($ep['imdb_id']) ?>/" target="_blank" rel="noopener">IMDB</a>
                            <?php endif; ?>
                        </span>
                        <button class="button button-small cal-watch-btn" data-episode-id="<?= (int) $ep['id'] ?>">✅ Mark Watched</button>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
