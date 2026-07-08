<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$stmt = db()->prepare(
    'SELECT s.imdb_id, s.name, s.image_url, s.status
     FROM user_shows us JOIN shows s ON s.imdb_id = us.show_imdb_id
     WHERE us.user_id = ? ORDER BY s.name'
);
$stmt->execute([current_user_id()]);
$shows = $stmt->fetchAll();

$pageTitle = 'My Shows';
require __DIR__ . '/includes/header.php';
?>
<?php if (!$shows): ?>
    <div class="hero">
        <h1>You're not tracking any shows yet</h1>
        <p><a class="button" href="search.php">Search for a show</a></p>
    </div>
<?php else: ?>
    <h1>My Shows</h1>
    <div class="show-grid">
        <?php foreach ($shows as $show): ?>
            <?php $imdbId = htmlspecialchars($show['imdb_id']); ?>
            <div class="show-card" data-show-id="<?= $imdbId ?>">
                <a href="show.php?id=<?= $imdbId ?>">
                    <?php if ($show['image_url']): ?>
                        <img src="<?= htmlspecialchars($show['image_url']) ?>" alt="">
                    <?php else: ?>
                        <div class="no-poster">No image</div>
                    <?php endif; ?>
                    <h3><?= htmlspecialchars($show['name']) ?></h3>
                </a>
                <?php if (status_label($show['status'])): ?>
                    <span class="status status-<?= htmlspecialchars($show['status']) ?>"><?= status_label($show['status']) ?></span>
                <?php endif; ?>
                <button class="button button-small button-danger untrack-btn"
                        data-show-id="<?= $imdbId ?>">Untrack</button>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
