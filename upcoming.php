<?php
// PUBLIC upcoming-episodes page (no login) — fresh, regularly changing content.
// Pretty URL /upcoming rewrites here.
require_once __DIR__ . '/includes/auth.php';

const UPCOMING_DAYS = 30;

$stmt = db()->prepare(
    'SELECT e.season, e.number, e.name AS ep_name, e.airdate,
            s.imdb_id, s.name AS show_name
     FROM episodes e JOIN shows s ON s.imdb_id = e.show_imdb_id
     WHERE e.airdate >= ? AND e.airdate <= ?
     ORDER BY e.airdate, s.name, e.season, e.number'
);
$stmt->execute([today(), date('Y-m-d', strtotime(today() . ' +' . UPCOMING_DAYS . ' days'))]);

$byDate = [];
foreach ($stmt->fetchAll() as $row) {
    $byDate[$row['airdate']][] = $row;
}

$pageTitle = t('pub_upcoming_title');
$canonicalUrl = seo_base() . '/upcoming';
$metaDescription = t('pub_upcoming_sub', UPCOMING_DAYS);

function upcoming_date_label(string $date): string
{
    if ($date === today()) {
        return t('today_label') . ' — ' . format_date($date);
    }
    if ($date === date('Y-m-d', strtotime(today() . ' +1 day'))) {
        return t('tomorrow_label') . ' — ' . format_date($date);
    }
    return format_date($date);
}

require __DIR__ . '/includes/header.php';
?>
<h1><?= t('pub_upcoming_title') ?></h1>
<p class="muted"><?= t('pub_upcoming_sub', UPCOMING_DAYS) ?></p>

<?php if (!$byDate): ?>
    <div class="hero"><p><?= t('pub_no_upcoming', UPCOMING_DAYS) ?></p></div>
<?php else: ?>
    <?php foreach ($byDate as $date => $rows): ?>
        <h2 class="date-head"><?= htmlspecialchars(upcoming_date_label($date)) ?></h2>
        <ul class="episode-list episode-list-plain">
            <?php foreach ($rows as $ep): ?>
                <li><span class="ep-title">
                    <a class="cal-show" href="<?= htmlspecialchars(series_url($ep['imdb_id'])) ?>"><?= htmlspecialchars($ep['show_name']) ?></a>
                    <?= episode_code((int) $ep['season'], (int) $ep['number']) ?>
                    <?= htmlspecialchars($ep['ep_name'] ?? '') ?>
                </span></li>
            <?php endforeach; ?>
        </ul>
    <?php endforeach; ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
