<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

// IDs of already-tracked shows so search results can show the right button state.
$stmt = db()->prepare('SELECT tvmaze_id FROM user_shows WHERE user_id = ?');
$stmt->execute([current_user_id()]);
$trackedIds = array_map('intval', array_column($stmt->fetchAll(), 'tvmaze_id'));

$pageTitle = 'Search';
require __DIR__ . '/includes/header.php';
?>
<h1>Search shows</h1>
<form id="search-form" class="search-form" autocomplete="off">
    <input type="search" id="search-input" placeholder="e.g. Breaking Bad" required>
    <button type="submit" class="button">Search</button>
</form>
<div id="search-results" class="show-grid"></div>
<script>window.TRACKED_IDS = <?= json_encode($trackedIds) ?>;</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
