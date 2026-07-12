<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$showId = $_GET['id'] ?? '';
if (!valid_imdb_id($showId)) {
    header('Location: index.php');
    exit;
}

$stmt = db()->prepare('SELECT 1 FROM user_shows WHERE user_id = ? AND show_imdb_id = ?');
$stmt->execute([current_user_id(), $showId]);
$isTracked = (bool) $stmt->fetch();

$pageTitle = app_name();
$noindex = true;
require __DIR__ . '/includes/header.php';
?>
<div id="show-detail" data-show-id="<?= htmlspecialchars($showId) ?>" data-tracked="<?= $isTracked ? '1' : '0' ?>">
    <p class="loading"><?= t('loading_show') ?></p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
