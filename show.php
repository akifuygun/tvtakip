<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$showId = (int) ($_GET['id'] ?? 0);
if ($showId <= 0) {
    header('Location: index.php');
    exit;
}

$stmt = db()->prepare('SELECT 1 FROM user_shows WHERE user_id = ? AND tvmaze_id = ?');
$stmt->execute([current_user_id(), $showId]);
$isTracked = (bool) $stmt->fetch();

$pageTitle = 'Show';
require __DIR__ . '/includes/header.php';
?>
<div id="show-detail" data-show-id="<?= $showId ?>" data-tracked="<?= $isTracked ? '1' : '0' ?>">
    <p class="loading">Loading show…</p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
