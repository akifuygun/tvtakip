<?php
// PUBLIC movie catalog (no login) — every cached movie, newest release first,
// plus an on-page TMDB search. Search results link straight to /movie/ttNNN,
// which imports on first visit for anyone. Pretty URL /movies rewrites here.
require_once __DIR__ . '/includes/auth.php';

$movies = db()->query(
    'SELECT imdb_id, name, image_url, status, released, rating FROM movies
     ORDER BY (released IS NULL), released DESC, name'
)->fetchAll();

$pageTitle = t('pub_movies_title');
$canonicalUrl = seo_base() . lang_path('/movies');
$metaDescription = t('pub_movies_sub', count($movies));

require __DIR__ . '/includes/header.php';
?>
<h1><?= t('pub_movies_title') ?></h1>
<p class="muted"><?= t('pub_movies_sub', count($movies)) ?></p>

<form id="pub-movie-search-form" class="search-form">
    <input type="search" id="pub-movie-search-input" placeholder="<?= t('movie_search_placeholder') ?>"
           aria-label="<?= t('pub_movies_title') ?>">
    <button type="submit" class="button"><?= t('search_button') ?></button>
</form>
<div id="pub-movie-search-results" class="show-grid"></div>

<div class="show-grid">
    <?php foreach ($movies as $m): ?>
        <?php
        $url = htmlspecialchars(movie_url($m['imdb_id']));
        $year = $m['released'] ? ' (' . substr($m['released'], 0, 4) . ')' : '';
        $label = movie_status_label($m['status']);
        ?>
        <div class="show-card">
            <a href="<?= $url ?>">
                <?php if ($m['image_url']): ?>
                    <img src="<?= htmlspecialchars($m['image_url']) ?>" alt="<?= htmlspecialchars($m['name']) ?>" loading="lazy">
                <?php else: ?>
                    <div class="no-poster"><?= t('no_image') ?></div>
                <?php endif; ?>
                <h3><?= htmlspecialchars($m['name'] . $year) ?></h3>
            </a>
            <?php if (!empty($m['rating']) || $label): ?>
                <div class="card-meta">
                    <?php if (!empty($m['rating'])): ?>
                        <span class="card-rating">⭐ <?= number_format((float) $m['rating'], 1) ?></span>
                    <?php endif; ?>
                    <?php if ($label): ?>
                        <span class="status status-<?= htmlspecialchars((string) $m['status']) ?>"><?= $label ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
