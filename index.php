<?php
require_once __DIR__ . '/includes/auth.php';

$shows = [];
if (is_logged_in()) {
    $stmt = db()->prepare('SELECT tvmaze_id, name, image_url, status FROM user_shows WHERE user_id = ? ORDER BY name');
    $stmt->execute([current_user_id()]);
    $shows = $stmt->fetchAll();
}

$pageTitle = 'My Shows';
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
<?php elseif (!$shows): ?>
    <div class="hero">
        <h1>You're not tracking any shows yet</h1>
        <p><a class="button" href="search.php">Search for a show</a></p>
    </div>
<?php else: ?>
    <h1>My Shows</h1>
    <div class="show-grid">
        <?php foreach ($shows as $show): ?>
            <div class="show-card" data-show-id="<?= (int) $show['tvmaze_id'] ?>">
                <a href="show.php?id=<?= (int) $show['tvmaze_id'] ?>">
                    <?php if ($show['image_url']): ?>
                        <img src="<?= htmlspecialchars($show['image_url']) ?>" alt="">
                    <?php else: ?>
                        <div class="no-poster">No image</div>
                    <?php endif; ?>
                    <h3><?= htmlspecialchars($show['name']) ?></h3>
                </a>
                <?php if ($show['status']): ?>
                    <span class="status status-<?= strtolower(htmlspecialchars($show['status'])) ?>"><?= htmlspecialchars($show['status']) ?></span>
                <?php endif; ?>
                <button class="button button-small button-danger untrack-btn"
                        data-show-id="<?= (int) $show['tvmaze_id'] ?>">Untrack</button>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
