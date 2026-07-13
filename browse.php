<?php
// PUBLIC show directory (no login) — the crawl hub linking every series page.
// Pretty URL /browse rewrites here.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/network_logos.php';

// Running first, then upcoming, then finished (canceled/ended); alphabetical within.
$shows = db()->query(
    "SELECT imdb_id, name, image_url, status, rating, network FROM shows
     ORDER BY CASE status
         WHEN 'running' THEN 1
         WHEN 'upcoming' THEN 2
         WHEN 'canceled' THEN 3
         WHEN 'ended' THEN 3
         ELSE 4 END, name"
)->fetchAll();

// Filter bar: a MANUALLY CURATED list of major networks, in display order.
// Edit this list to control which chips appear (exact TMDB name strings).
// Only networks that actually have shows are shown; logos come from the static
// map (includes/network_logos.php).
$TOP_NETWORKS = [
    'Netflix', 'Disney+', 'Prime Video', 'Apple TV', 'HBO', 'HBO Max', 'Hulu',
    'Paramount+', 'Peacock', 'AMC', 'FX', 'Showtime', 'STARZ',
    'ABC', 'NBC', 'CBS', 'FOX', 'The CW', 'BBC One',
];
$counts = db()->query(
    "SELECT network, COUNT(*) AS n FROM shows
     WHERE network IS NOT NULL AND network <> '' GROUP BY network"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$networks = [];
foreach ($TOP_NETWORKS as $name) {
    if (!empty($counts[$name])) {
        $networks[] = ['network' => $name, 'n' => (int) $counts[$name]];
    }
}

$pageTitle = t('pub_browse_title');
$canonicalUrl = seo_base() . lang_path('/browse');
$metaDescription = t('pub_browse_sub', count($shows));

require __DIR__ . '/includes/header.php';
?>
<h1><?= t('pub_browse_title') ?></h1>
<p class="muted"><?= t('pub_browse_sub', count($shows)) ?></p>

<?php if ($networks): ?>
    <div id="network-filter" class="net-filter" aria-label="<?= t('filter_by_network') ?>">
        <button type="button" class="net-chip net-all selected"><?= t('all_networks') ?></button>
        <?php foreach ($networks as $net): ?>
            <?php $logo = network_logo($net['network']); ?>
            <button type="button" class="net-chip" data-network="<?= htmlspecialchars($net['network']) ?>"
                    title="<?= htmlspecialchars($net['network']) ?> (<?= (int) $net['n'] ?>)">
                <?php if ($logo): ?>
                    <img src="<?= htmlspecialchars($logo) ?>" alt="<?= htmlspecialchars($net['network']) ?>" loading="lazy">
                <?php else: ?>
                    <span><?= htmlspecialchars($net['network']) ?></span>
                <?php endif; ?>
            </button>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="show-grid">
    <?php foreach ($shows as $show): ?>
        <?= show_card_html($show, series_url($show['imdb_id'])) ?>
    <?php endforeach; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
