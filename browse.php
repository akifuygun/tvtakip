<?php
// PUBLIC show directory (no login) — the crawl hub linking every series page.
// Pretty URL /browse rewrites here.
require_once __DIR__ . '/includes/auth.php';

$shows = db()->query('SELECT imdb_id, name, image_url, status FROM shows ORDER BY name')->fetchAll();

$pageTitle = t('pub_browse_title');
$canonicalUrl = seo_base() . '/browse';
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
