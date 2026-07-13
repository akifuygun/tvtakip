<?php
// PUBLIC show directory (no login) — the crawl hub linking every series page.
// Pretty URL /browse rewrites here.
require_once __DIR__ . '/includes/auth.php';

// Upcoming first, then running, then finished shows; alphabetical within.
$shows = db()->query(
    "SELECT imdb_id, name, image_url, status, rating FROM shows
     ORDER BY CASE status
         WHEN 'upcoming' THEN 1
         WHEN 'running' THEN 2
         WHEN 'canceled' THEN 3
         WHEN 'ended' THEN 4
         ELSE 5 END, name"
)->fetchAll();

$pageTitle = t('pub_browse_title');
$canonicalUrl = seo_base() . lang_path('/browse');
$metaDescription = t('pub_browse_sub', count($shows));

require __DIR__ . '/includes/header.php';
?>
<h1><?= t('pub_browse_title') ?></h1>
<p class="muted"><?= t('pub_browse_sub', count($shows)) ?></p>

<div class="show-grid">
    <?php foreach ($shows as $show): ?>
        <?= show_card_html($show, series_url($show['imdb_id'])) ?>
    <?php endforeach; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
