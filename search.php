<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

// IMDB ids of already-tracked shows so search results can show the right button state.
$stmt = db()->prepare('SELECT show_imdb_id FROM user_shows WHERE user_id = ?');
$stmt->execute([current_user_id()]);
$trackedIds = array_column($stmt->fetchAll(), 'show_imdb_id');

$pageTitle = t('search_title');
$noindex = true;
require __DIR__ . '/includes/header.php';
?>
<h1><?= t('search_title') ?></h1>
<form id="search-form" class="search-form" autocomplete="off">
    <input type="search" id="search-input" placeholder="<?= t('search_placeholder') ?>" required
           value="<?= htmlspecialchars(is_string($_GET['q'] ?? null) ? $_GET['q'] : '') ?>">
    <button type="submit" class="button"><?= t('search_button') ?></button>
</form>
<div id="search-results" class="show-grid"></div>
<script>window.TRACKED_IDS = <?= json_encode($trackedIds) ?>;</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
