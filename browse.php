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

// Filter bar: MANUALLY CURATED brand groups, in display order. A chip targets a
// network/producer and matches all of its channels — [display label, [member
// channel names (exact TMDB strings)]]. The first member supplies the logo
// (includes/network_logos.php). Edit here; only groups with shows render.
$NETWORK_GROUPS = [
    ['Netflix', ['Netflix']],
    ['Disney', ['Disney+', 'Disney Channel', 'Disney XD']],
    ['Prime Video', ['Prime Video']],
    ['Apple TV', ['Apple TV']],
    ['HBO', ['HBO', 'HBO Max']],
    ['Hulu', ['Hulu']],
    ['Paramount', ['Paramount+', 'Paramount Network', 'Paramount+ with Showtime']],
    ['Peacock', ['Peacock']],
    ['AMC', ['AMC']],
    ['FX', ['FX', 'FXX']],
    ['Showtime', ['Showtime']],
    ['STARZ', ['STARZ']],
    ['ABC', ['ABC', 'ABC Family', 'ABC Kids', 'ABC.com']],
    ['NBC', ['NBC']],
    ['CBS', ['CBS', 'CBS All Access']],
    ['FOX', ['FOX']],
    ['The CW', ['The CW']],
    ['BBC', ['BBC One', 'BBC Two', 'BBC Three', 'BBC America']],
];
$counts = db()->query(
    "SELECT network, COUNT(*) AS n FROM shows
     WHERE network IS NOT NULL AND network <> '' GROUP BY network"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$networks = [];
foreach ($NETWORK_GROUPS as [$label, $members]) {
    $total = 0;
    foreach ($members as $m) {
        $total += (int) ($counts[$m] ?? 0);
    }
    if ($total > 0) {
        $networks[] = ['label' => $label, 'logo' => network_logo($members[0]), 'members' => $members, 'n' => $total];
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
            <button type="button" class="net-chip"
                    data-networks="<?= htmlspecialchars(json_encode($net['members'])) ?>"
                    title="<?= htmlspecialchars($net['label']) ?> (<?= (int) $net['n'] ?>)"
                    aria-label="<?= htmlspecialchars($net['label']) ?>">
                <?php if ($net['logo']): ?>
                    <img src="<?= htmlspecialchars($net['logo']) ?>" alt="<?= htmlspecialchars($net['label']) ?>" loading="lazy">
                <?php else: ?>
                    <span><?= htmlspecialchars($net['label']) ?></span>
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
