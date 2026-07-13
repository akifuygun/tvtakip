<?php
// PUBLIC, server-rendered show page (no login) — the site's indexable content.
// Pretty URL /series/ttNNNNNNN rewrites here (.htaccess / router.php).
require_once __DIR__ . '/includes/auth.php';

// The pretty-URL path id always wins: Apache's QSA rewrite appends any
// visitor-supplied ?id= after the path's id (PHP would keep the last one),
// and router.php resolves it the other way — the path is the one truth.
$showId = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
if (preg_match('#^(?:/tr)?/series/(tt\d{6,10})#', strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?'), $m)) {
    $showId = $m[1];
}
if (!valid_imdb_id($showId)) {
    header('Location: /browse');
    exit;
}

$stmt = db()->prepare(
    'SELECT imdb_id, name, image_url, backdrop_url, status, overview, premiered,
            genres, network, rating, runtime
     FROM shows WHERE imdb_id = ?'
);
$stmt->execute([$showId]);
$show = $stmt->fetch();

if (!$show) {
    http_response_code(404);
    $pageTitle = t('series_not_found');
    $noindex = true;
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="hero">
        <h1><?= t('series_not_found') ?></h1>
        <p><a class="button" href="/browse"><?= t('browse_all_shows') ?></a></p>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$stmt = db()->prepare(
    'SELECT imdb_id, season, number, name, airdate FROM episodes
     WHERE show_imdb_id = ? ORDER BY season, number'
);
$stmt->execute([$showId]);
$episodes = $stmt->fetchAll();

$seasons = [];
foreach ($episodes as $ep) {
    $seasons[$ep['season']][] = $ep;
}
krsort($seasons); // newest season first, Specials (0) last

$pageTitle = $show['name'];
$canonicalUrl = seo_base() . series_url($showId);
$metaDescription = text_excerpt($show['name'] . ' — ' . t('series_meta_suffix') . '. ' . (string) $show['overview'], 158);
// Thin pages (no episodes, no overview — e.g. announced-only shows) stay out
// of the index until they have real content.
if (!$episodes && !$show['overview']) {
    $noindex = true;
}
// Social preview: a wide backdrop earns a large card; otherwise the portrait
// poster on the compact card. Require https so the preview isn't rejected for
// mixed content.
if ($show['backdrop_url'] && str_starts_with($show['backdrop_url'], 'https://')) {
    $ogImage = $show['backdrop_url'];
    $twitterCard = 'summary_large_image';
} elseif ($show['image_url'] && str_starts_with($show['image_url'], 'https://')) {
    $ogImage = $show['image_url'];
    $twitterCard = 'summary';
}

$genreList = array_values(array_filter(array_map('trim', explode(',', (string) $show['genres']))));
// containsSeason nodes (real seasons only, ascending) for the TVSeries graph.
$seasonNodes = [];
foreach (array_reverse($seasons, true) as $sNum => $eps) {
    if ($sNum > 0) {
        $seasonNodes[] = [
            '@type' => 'TVSeason',
            'seasonNumber' => (int) $sNum,
            'numberOfEpisodes' => count($eps),
        ];
    }
}
// aggregateRating is intentionally omitted: Google requires ratingCount, which
// we don't store. The rating is shown in the UI instead. genre/season graph
// and a breadcrumb are safe, high-value structured data.
$tvSeriesLd = array_filter([
    '@type' => 'TVSeries',
    'name' => $show['name'],
    'url' => $canonicalUrl,
    'description' => text_excerpt($show['overview'] ?? '', 300) ?: null,
    'image' => $show['image_url'] ?: null,
    'startDate' => $show['premiered'] ?: null,
    'genre' => $genreList ?: null,
    'numberOfEpisodes' => count($episodes) ?: null,
    'numberOfSeasons' => count($seasonNodes) ?: null,
    'containsSeason' => $seasonNodes ?: null,
    'sameAs' => 'https://www.imdb.com/title/' . $showId . '/',
]);
$breadcrumbLd = [
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => t('breadcrumb_home'), 'item' => seo_base() . lang_path('/')],
        ['@type' => 'ListItem', 'position' => 2, 'name' => t('nav_browse'), 'item' => seo_base() . lang_path('/browse')],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $show['name'], 'item' => $canonicalUrl],
    ],
];
$jsonLd = json_encode([
    '@context' => 'https://schema.org',
    '@graph' => [$tvSeriesLd, $breadcrumbLd],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

// Whether the viewer already tracks this show (drives the interactive header).
$isTracked = false;
if (is_logged_in()) {
    $t = db()->prepare('SELECT 1 FROM user_shows WHERE user_id = ? AND show_imdb_id = ?');
    $t->execute([current_user_id(), $showId]);
    $isTracked = (bool) $t->fetch();
}

require __DIR__ . '/includes/header.php';
?>
<?php if (is_logged_in()): ?>
    <!-- Interactive app view — app.js fills this. The static markup below is
         wrapped in <noscript> so it only renders as the no-JS fallback. -->
    <div id="show-detail" data-show-id="<?= htmlspecialchars($showId) ?>" data-tracked="<?= $isTracked ? '1' : '0' ?>">
        <p class="loading"><?= t('loading_show') ?></p>
    </div>
    <noscript>
<?php endif; ?>
    <article class="show-header">
        <?php if ($show['image_url']): ?>
            <img src="<?= htmlspecialchars($show['image_url']) ?>" alt="<?= htmlspecialchars($show['name']) ?>"
                 class="poster-zoom" data-backdrop="<?= htmlspecialchars($show['backdrop_url'] ?? '') ?>">
        <?php else: ?>
            <div class="no-poster"><?= t('no_image') ?></div>
        <?php endif; ?>
        <div class="show-info">
            <h1><?= htmlspecialchars($show['name']) ?>
                <a class="imdb-link" href="https://www.imdb.com/title/<?= htmlspecialchars($showId) ?>/"
                   target="_blank" rel="noopener">IMDB</a></h1>
            <p class="muted"><?= htmlspecialchars(implode(' · ', array_filter([
                $show['rating'] ? '⭐ ' . number_format((float) $show['rating'], 1) : null,
                $show['premiered'] ? substr($show['premiered'], 0, 4) : null,
                status_label($show['status']) ?: null,
                $show['network'] ?: null,
                $show['runtime'] ? t('runtime_min', (int) $show['runtime']) : null,
            ]))) ?></p>
            <?php if ($genreList): ?>
                <p class="genres"><?php foreach ($genreList as $g): ?><span class="genre-chip"><?= htmlspecialchars($g) ?></span><?php endforeach; ?></p>
            <?php endif; ?>
            <?php if ($show['overview']): ?>
                <p class="show-summary"><?= htmlspecialchars($show['overview']) ?></p>
            <?php endif; ?>
            <?php if (!is_logged_in()): ?>
                <p class="muted"><?= t('series_cta') ?></p>
                <p><a class="button" href="/register.php"><?= t('get_started') ?></a>
                   <a class="button button-secondary" href="/login.php"><?= t('login') ?></a></p>
            <?php endif; ?>
        </div>
    </article>

    <?php if ($episodes): ?>
        <h2><?= t('episode_guide') ?></h2>
        <?php $first = true; ?>
        <?php foreach ($seasons as $season => $eps): ?>
            <details class="season"<?= $first ? ' open' : '' ?>>
                <summary><?= $season === 0 ? t('specials') : t('season_n', $season) ?> <?= count($eps) === 1 ? t('episodes_count_one') : t('episodes_count', count($eps)) ?></summary>
                <ul class="episode-list episode-list-plain">
                    <?php foreach ($eps as $ep): ?>
                        <li><span class="ep-title">
                            <?= episode_code((int) $ep['season'], (int) $ep['number']) ?>
                            <?= htmlspecialchars($ep['name'] ?? '') ?><?= $ep['airdate'] ? ' — ' . htmlspecialchars(format_date($ep['airdate'])) : '' ?>
                            <?php if ($ep['imdb_id']): ?>
                                <a class="imdb-link" href="https://www.imdb.com/title/<?= htmlspecialchars($ep['imdb_id']) ?>/"
                                   target="_blank" rel="noopener">IMDB</a>
                            <?php endif; ?>
                        </span></li>
                    <?php endforeach; ?>
                </ul>
            </details>
            <?php $first = false; ?>
        <?php endforeach; ?>
    <?php endif; ?>
<?php if (is_logged_in()): ?>
    </noscript>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
