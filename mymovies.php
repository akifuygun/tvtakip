<?php
// My Movies — the user's single movie list, with on-page search to add.
// Watched state is a flag on the list row; cards split into two groups.
require_once __DIR__ . '/includes/auth.php';
require_login();

$stmt = db()->prepare(
    'SELECT m.imdb_id, m.name, m.image_url, m.status, m.released, um.watched
     FROM user_movies um JOIN movies m ON m.imdb_id = um.movie_imdb_id
     WHERE um.user_id = ? ORDER BY m.name'
);
$stmt->execute([current_user_id()]);
$movies = $stmt->fetchAll();

$toWatch = array_values(array_filter($movies, fn($m) => !$m['watched']));
$watched = array_values(array_filter($movies, fn($m) => (bool) $m['watched']));

/** One movie card; buttons are wired by class in app.js (initMovies). */
function movie_card_html(array $m): string
{
    $imdbId = htmlspecialchars($m['imdb_id']);
    $name = htmlspecialchars($m['name']);
    $year = $m['released'] ? ' (' . substr($m['released'], 0, 4) . ')' : '';
    $img = $m['image_url']
        ? '<img src="' . htmlspecialchars($m['image_url']) . '" alt="" loading="lazy">'
        : '<div class="no-poster">' . t('no_image') . '</div>';
    $isWatched = (bool) $m['watched'];
    $badge = '';
    if ($isWatched) {
        $badge = '<span class="next-ep">' . t('movie_watched_badge') . '</span>';
    } elseif ($label = movie_status_label($m['status'])) {
        $badge = '<span class="status status-' . htmlspecialchars((string) $m['status']) . '">' . $label . '</span>';
    }
    return '<div class="show-card' . ($isWatched ? ' watched' : '') . '" data-movie-id="' . $imdbId . '">'
        . '<a href="' . htmlspecialchars(movie_url($m['imdb_id'])) . '">' . $img . '<h3>' . $name . $year . '</h3></a>'
        . $badge
        . '<button class="button button-small movie-watch-btn" data-movie-id="' . $imdbId . '"'
        . ' data-watched="' . ($isWatched ? '1' : '0') . '">'
        . ($isWatched ? t('movie_mark_unwatched') : t('movie_mark_watched')) . '</button>'
        . '<button class="button button-small button-danger movie-remove-btn" data-movie-id="' . $imdbId . '">'
        . t('remove_movie') . '</button>'
        . '</div>';
}

$pageTitle = t('mymovies_title');
$noindex = true;
require __DIR__ . '/includes/header.php';
?>
<h1><?= t('mymovies_title') ?></h1>

<form id="movie-search-form" class="search-form">
    <input type="search" id="movie-search-input" placeholder="<?= t('movie_search_placeholder') ?>"
           aria-label="<?= t('mymovies_title') ?>">
    <button type="submit" class="button"><?= t('search_button') ?></button>
</form>
<div id="movie-search-results" class="show-grid"></div>

<?php if (!$movies): ?>
    <div class="hero">
        <h2><?= t('no_movies_yet') ?></h2>
        <p class="muted"><?= t('movies_empty_hint') ?></p>
    </div>
<?php else: ?>
    <details class="show-group" open>
        <summary><?= t('group_watchlist') ?> (<?= count($toWatch) ?>)</summary>
        <div class="show-grid" id="movies-towatch">
            <?php foreach ($toWatch as $m): ?>
                <?= movie_card_html($m) ?>
            <?php endforeach; ?>
        </div>
    </details>
    <details class="show-group" open>
        <summary><?= t('group_watched') ?> (<?= count($watched) ?>)</summary>
        <div class="show-grid" id="movies-watched">
            <?php foreach ($watched as $m): ?>
                <?= movie_card_html($m) ?>
            <?php endforeach; ?>
        </div>
    </details>
<?php endif; ?>
<script>window.MY_MOVIE_IDS = <?= json_encode(array_column($movies, 'imdb_id')) ?>;</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
