<?php
// PUBLIC, server-rendered movie page (no login) — indexable content.
// Pretty URL /movie/ttNNNNNNN rewrites here (.htaccess / router.php).
// Unlike series.php there is nothing to hydrate client-side (no episodes), so
// the page is fully server-rendered for everyone; logged-in users just get an
// actions row (#movie-actions) whose buttons app.js wires.
require_once __DIR__ . '/includes/auth.php';

// The pretty-URL path id always wins over ?id= (same reasoning as series.php).
$movieId = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
if (preg_match('#^(?:/tr)?/movie/(tt\d{6,10})#', strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?'), $m)) {
    $movieId = $m[1];
}
if (!valid_imdb_id($movieId)) {
    header('Location: /browse');
    exit;
}

$stmt = db()->prepare(
    'SELECT imdb_id, name, image_url, backdrop_url, status, overview, released,
            genres, studio, rating, runtime
     FROM movies WHERE imdb_id = ?'
);
$stmt->execute([$movieId]);
$movie = $stmt->fetch();

if (!$movie) {
    http_response_code(404);
    $pageTitle = t('movie_not_found');
    $noindex = true;
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="hero">
        <h1><?= t('movie_not_found') ?></h1>
        <p><a class="button" href="/browse"><?= t('browse_all_shows') ?></a></p>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$pageTitle = $movie['name'];
$canonicalUrl = seo_base() . movie_url($movieId);
$metaDescription = text_excerpt($movie['name'] . ' — ' . t('movie_meta_suffix') . '. ' . (string) $movie['overview'], 158);
// Thin pages (no overview) stay out of the index until they have real content.
if (!$movie['overview']) {
    $noindex = true;
}
// Social preview: wide backdrop earns a large card; portrait poster otherwise.
if ($movie['backdrop_url'] && str_starts_with($movie['backdrop_url'], 'https://')) {
    $ogImage = $movie['backdrop_url'];
    $twitterCard = 'summary_large_image';
} elseif ($movie['image_url'] && str_starts_with($movie['image_url'], 'https://')) {
    $ogImage = $movie['image_url'];
    $twitterCard = 'summary';
}

$genreList = array_values(array_filter(array_map('trim', explode(',', (string) $movie['genres']))));
$movieLd = array_filter([
    '@type' => 'Movie',
    'name' => $movie['name'],
    'url' => $canonicalUrl,
    'description' => text_excerpt($movie['overview'] ?? '', 300) ?: null,
    'image' => $movie['image_url'] ?: null,
    'datePublished' => $movie['released'] ?: null,
    'genre' => $genreList ?: null,
    'duration' => $movie['runtime'] ? 'PT' . (int) $movie['runtime'] . 'M' : null,
    'sameAs' => 'https://www.imdb.com/title/' . $movieId . '/',
]);
$breadcrumbLd = [
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => t('breadcrumb_home'), 'item' => seo_base() . lang_path('/')],
        ['@type' => 'ListItem', 'position' => 2, 'name' => $movie['name'], 'item' => $canonicalUrl],
    ],
];
$jsonLd = json_encode([
    '@context' => 'https://schema.org',
    '@graph' => [$movieLd, $breadcrumbLd],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

// The viewer's list/watched state (drives the actions row).
$inList = false;
$isWatched = false;
if (is_logged_in()) {
    $s = db()->prepare('SELECT watched FROM user_movies WHERE user_id = ? AND movie_imdb_id = ?');
    $s->execute([current_user_id(), $movieId]);
    $row = $s->fetch();
    if ($row) {
        $inList = true;
        $isWatched = (bool) $row['watched'];
    }
}

require __DIR__ . '/includes/header.php';
?>
<article class="show-header">
    <?php if ($movie['image_url']): ?>
        <img src="<?= htmlspecialchars($movie['image_url']) ?>" alt="<?= htmlspecialchars($movie['name']) ?>"
             class="poster-zoom" data-backdrop="<?= htmlspecialchars($movie['backdrop_url'] ?? '') ?>">
    <?php else: ?>
        <div class="no-poster"><?= t('no_image') ?></div>
    <?php endif; ?>
    <div class="show-info">
        <h1><?= htmlspecialchars($movie['name']) ?>
            <a class="imdb-link" href="https://www.imdb.com/title/<?= htmlspecialchars($movieId) ?>/"
               target="_blank" rel="noopener">IMDB</a></h1>
        <p class="muted"><?= htmlspecialchars(implode(' · ', array_filter([
            $movie['rating'] ? '⭐ ' . number_format((float) $movie['rating'], 1) : null,
            $movie['released'] ? substr($movie['released'], 0, 4) : null,
            movie_status_label($movie['status']) ?: null,
            $movie['studio'] ?: null,
            $movie['runtime'] ? t('runtime_min', (int) $movie['runtime']) : null,
        ]))) ?></p>
        <?php if ($genreList): ?>
            <p class="genres"><?php foreach ($genreList as $g): ?><span class="genre-chip"><?= htmlspecialchars($g) ?></span><?php endforeach; ?></p>
        <?php endif; ?>
        <?php if ($movie['released'] && $movie['released'] > today()): ?>
            <p class="next-ep">📅 <?= t('movie_release_label', format_date($movie['released'])) ?></p>
        <?php endif; ?>
        <?php if ($movie['overview']): ?>
            <p class="show-summary"><?= htmlspecialchars($movie['overview']) ?></p>
        <?php endif; ?>
        <?php if (is_logged_in()): ?>
            <p id="movie-actions" data-movie-id="<?= htmlspecialchars($movieId) ?>"
               data-in-list="<?= $inList ? '1' : '0' ?>" data-watched="<?= $isWatched ? '1' : '0' ?>">
                <button type="button" class="button" id="movie-list-btn"><?= $inList ? t('remove_from_list') : t('add_to_list') ?></button>
                <button type="button" class="button button-secondary" id="movie-watch-btn"><?= $isWatched ? t('movie_mark_unwatched') : t('movie_mark_watched') ?></button>
            </p>
        <?php else: ?>
            <p class="muted"><?= t('movie_cta') ?></p>
            <p><a class="button" href="/register.php"><?= t('get_started') ?></a>
               <a class="button button-secondary" href="/login.php"><?= t('login') ?></a></p>
        <?php endif; ?>
    </div>
</article>
<?php require __DIR__ . '/includes/footer.php'; ?>
