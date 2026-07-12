<?php
// PUBLIC upcoming-episodes page (no login) — fresh, regularly changing content.
// Pretty URL /upcoming rewrites here.
require_once __DIR__ . '/includes/auth.php';

const UPCOMING_DAYS = 30;

// Logged in: only the user's tracked shows (a personal upcoming list).
// Guests: the full public catalog (indexable SEO content).
$mine = is_logged_in();

// Padded by a day on both sides: the airstamp shifts a US-evening episode
// into the next local (Istanbul) day, which is what we group and filter by.
$sql = 'SELECT e.season, e.number, e.name AS ep_name, e.airdate, e.airstamp,
               s.imdb_id, s.name AS show_name
        FROM episodes e JOIN shows s ON s.imdb_id = e.show_imdb_id ';
$params = [
    date('Y-m-d', strtotime(today() . ' -1 day')),
    date('Y-m-d', strtotime(today() . ' +' . (UPCOMING_DAYS + 1) . ' days')),
];
if ($mine) {
    $sql .= 'JOIN user_shows us ON us.show_imdb_id = s.imdb_id AND us.user_id = ? ';
    array_unshift($params, current_user_id());
}
$sql .= 'WHERE e.airdate >= ? AND e.airdate <= ?
         ORDER BY e.airstamp, e.airdate, s.name, e.season, e.number';
$stmt = db()->prepare($sql);
$stmt->execute($params);

$tz = new DateTimeZone(app_timezone());
$windowEnd = date('Y-m-d', strtotime(today() . ' +' . UPCOMING_DAYS . ' days'));
$byDate = [];
foreach ($stmt->fetchAll() as $row) {
    if ($row['airstamp'] !== null) {
        $dt = (new DateTime($row['airstamp'], new DateTimeZone('UTC')))->setTimezone($tz);
        $row['local_date'] = $dt->format('Y-m-d');
        $row['local_time'] = $dt->format('H:i');
    } else {
        $row['local_date'] = $row['airdate'];
        $row['local_time'] = null;
    }
    if ($row['local_date'] < today() || $row['local_date'] > $windowEnd) {
        continue;
    }
    $byDate[$row['local_date']][] = $row;
}
ksort($byDate);

$pageTitle = t('pub_upcoming_title');
$canonicalUrl = seo_base() . lang_path('/upcoming');
$metaDescription = t('pub_upcoming_sub', UPCOMING_DAYS);
$subtitle = $mine ? t('upcoming_sub_mine') : t('pub_upcoming_sub', UPCOMING_DAYS);
$emptyMsg = $mine ? t('upcoming_none_mine') : t('pub_no_upcoming', UPCOMING_DAYS);

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
<p class="muted"><?= htmlspecialchars($subtitle) ?></p>

<?php if (!$byDate): ?>
    <div class="hero">
        <p><?= htmlspecialchars($emptyMsg) ?></p>
        <?php if ($mine): ?><p><a class="button" href="/search.php"><?= t('search_for_show') ?></a></p><?php endif; ?>
    </div>
<?php else: ?>
    <?php foreach ($byDate as $date => $rows): ?>
        <h2 class="date-head"><?= htmlspecialchars(upcoming_date_label($date)) ?></h2>
        <ul class="episode-list episode-list-plain">
            <?php foreach ($rows as $ep): ?>
                <li><span class="ep-title">
                    <a class="cal-show" href="<?= htmlspecialchars(series_url($ep['imdb_id'])) ?>"><?= htmlspecialchars($ep['show_name']) ?></a>
                    <?= episode_code((int) $ep['season'], (int) $ep['number']) ?>
                    <?= htmlspecialchars($ep['ep_name'] ?? '') ?>
                    <?php if ($ep['local_time']): ?><span class="muted">· <?= $ep['local_time'] ?></span><?php endif; ?>
                </span></li>
            <?php endforeach; ?>
        </ul>
    <?php endforeach; ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
