<?php
// PUBLIC show directory (no login) — the crawl hub linking every series page.
// Pretty URL /browse rewrites here.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/network_logos.php';

// Running first, then upcoming, then finished (canceled/ended); alphabetical within.
$shows = db()->query(
    "SELECT imdb_id, name, image_url, status, rating, network, genres FROM shows
     ORDER BY CASE status
         WHEN 'running' THEN 1
         WHEN 'upcoming' THEN 2
         WHEN 'canceled' THEN 3
         WHEN 'ended' THEN 3
         ELSE 4 END, name"
)->fetchAll();

// Filter bar: the curated brand groups (shared with the poster badges) live in
// includes/network_logos.php — a chip targets a network/producer and matches
// all of its member channels; only groups with shows render.
$NETWORK_GROUPS = network_groups();
// Chip totals + the Others complement, computed from each show's (comma-
// separated) network list: a show counts once per group any of its networks
// belongs to; Others = shows with no network in any group (incl. no network).
$memberToGroups = [];
foreach ($NETWORK_GROUPS as $gi => [$label, $members]) {
    foreach ($members as $m) {
        $memberToGroups[$m][] = $gi;
    }
}
$groupCounts = array_fill(0, count($NETWORK_GROUPS), 0);
$othersCount = 0;
foreach ($shows as $s) {
    $gids = [];
    foreach (network_names($s['network']) as $n) {
        foreach ($memberToGroups[$n] ?? [] as $gi) {
            $gids[$gi] = true;
        }
    }
    if (!$gids) {
        $othersCount++;
        continue;
    }
    foreach (array_keys($gids) as $gi) {
        $groupCounts[$gi]++;
    }
}
$networks = [];
foreach ($NETWORK_GROUPS as $gi => [$label, $members]) {
    if ($groupCounts[$gi] > 0) {
        $networks[] = ['label' => $label, 'logo' => network_logo($members[0]), 'members' => $members, 'n' => $groupCounts[$gi]];
    }
}

// Genre + status facets, derived from the already-loaded $shows (no extra query).
$genreCounts = [];
$statusCounts = [];
foreach ($shows as $s) {
    foreach (genre_list($s['genres']) as $g) {
        $genreCounts[$g] = ($genreCounts[$g] ?? 0) + 1;
    }
    if ($s['status']) {
        $statusCounts[$s['status']] = ($statusCounts[$s['status']] ?? 0) + 1;
    }
}
arsort($genreCounts); // most common first
// Fixed status order; only those present render.
$statuses = array_values(array_filter(
    ['running', 'upcoming', 'ended', 'canceled'],
    fn($st) => !empty($statusCounts[$st])
));

// Filter facets present, in tab order; the first is the default-open tab.
$facets = [];
if ($networks) {
    $facets[] = 'network';
}
if ($genreCounts) {
    $facets[] = 'genre';
}
if ($statuses) {
    $facets[] = 'status';
}
$activeFacet = $facets[0] ?? '';

$pageTitle = t('pub_browse_title');
$canonicalUrl = seo_base() . lang_path('/browse');
$metaDescription = t('pub_browse_sub', count($shows));

require __DIR__ . '/includes/header.php';
?>
<h1><?= t('pub_browse_title') ?></h1>
<p class="muted"><?= t('pub_browse_sub', count($shows)) ?></p>

<div class="filters">
    <div class="filter-tabs" role="tablist">
        <?php foreach ($facets as $f): ?>
            <button type="button" class="filter-tab<?= $f === $activeFacet ? ' active' : '' ?>" data-tab="<?= $f ?>"><?= t('flt_' . $f) ?></button>
        <?php endforeach; ?>
    </div>

    <?php if ($networks): ?>
        <div class="filter-panel<?= $activeFacet === 'network' ? '' : ' hidden' ?>" data-panel="network">
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
                <?php if ($othersCount > 0): ?>
                    <button type="button" class="net-chip net-others" data-others="1"
                            title="<?= t('network_others') ?> (<?= $othersCount ?>)" aria-label="<?= t('network_others') ?>"><?= t('network_others') ?></button>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($genreCounts): ?>
        <div class="filter-panel<?= $activeFacet === 'genre' ? '' : ' hidden' ?>" data-panel="genre">
            <div id="genre-filter" class="net-filter" aria-label="<?= t('flt_genre') ?>">
                <button type="button" class="net-chip net-all selected"><?= t('all_networks') ?></button>
                <?php foreach ($genreCounts as $g => $gn): ?>
                    <button type="button" class="net-chip net-text" data-genre="<?= htmlspecialchars($g) ?>"
                            title="<?= htmlspecialchars($g) ?> (<?= (int) $gn ?>)"><?= htmlspecialchars($g) ?></button>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($statuses): ?>
        <div class="filter-panel<?= $activeFacet === 'status' ? '' : ' hidden' ?>" data-panel="status">
            <div id="status-filter" class="net-filter" aria-label="<?= t('flt_status') ?>">
                <button type="button" class="net-chip net-all selected"><?= t('all_networks') ?></button>
                <?php foreach ($statuses as $st): ?>
                    <button type="button" class="net-chip net-text" data-status="<?= htmlspecialchars($st) ?>"
                            title="<?= htmlspecialchars(status_label($st)) ?> (<?= (int) $statusCounts[$st] ?>)"><?= htmlspecialchars(status_label($st)) ?></button>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="show-grid">
    <?php foreach ($shows as $show): ?>
        <?= show_card_html($show, series_url($show['imdb_id'])) ?>
    <?php endforeach; ?>
</div>
<div class="show-more-wrap">
    <button type="button" id="show-more" class="button button-secondary" style="display:none"><?= t('show_more') ?></button>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
