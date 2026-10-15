<?php
// PUBLIC upcoming-episodes page (no login) — fresh, regularly changing content.
// Pretty URL /upcoming rewrites here. Two views: a 30-day list and a month
// calendar (?month=YYYY-MM navigates it).
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/network_logos.php';

const UPCOMING_DAYS = 30;
const CAL_MONTHS_BACK = 12;   // navigable range, so crawlers can't walk months forever
const CAL_MONTHS_AHEAD = 12;
const CAL_DAY_LIMIT = 6;      // entries per day before the "+N more" expander

// Logged in: only the user's tracked shows (a personal upcoming list).
// Guests: the full public catalog (indexable SEO content).
$mine = is_logged_in();

/**
 * Episodes airing between two LOCAL dates (inclusive), grouped by local date.
 * The airdate range is padded by a day on both sides: the airstamp shifts a
 * US-evening episode into the next local day, which is what we group by.
 */
function upcoming_by_date(string $from, string $to, bool $mine): array
{
    $sql = 'SELECT e.season, e.number, e.name AS ep_name, e.airdate, e.airstamp, e.finale,
                   s.imdb_id, s.name AS show_name, s.network, s.rating,
                   (SELECT COUNT(*) FROM user_shows t WHERE t.show_imdb_id = s.imdb_id) AS trackers
            FROM episodes e JOIN shows s ON s.imdb_id = e.show_imdb_id ';
    $params = [
        date('Y-m-d', strtotime($from . ' -1 day')),
        date('Y-m-d', strtotime($to . ' +1 day')),
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
        if ($row['local_date'] < $from || $row['local_date'] > $to) {
            continue;
        }
        $byDate[$row['local_date']][] = $row;
    }
    ksort($byDate);
    return $byDate;
}

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

/**
 * One day's calendar entries: a show's same-day episodes of a season (a
 * streaming drop) fold into one entry. Returns [visible, hidden]: the
 * CAL_DAY_LIMIT most-tracked entries stay visible, the rest go behind
 * "+N more"; both halves keep air-time order (date-only ones first).
 */
function calendar_day_entries(array $rows): array
{
    $groups = [];
    foreach ($rows as $row) {
        $key = $row['imdb_id'] . '|' . $row['season'];
        if (!isset($groups[$key])) {
            $groups[$key] = ['first' => $row, 'last' => $row];
        } elseif ((int) $row['number'] < (int) $groups[$key]['first']['number']) {
            $groups[$key]['first'] = $row;
        } elseif ((int) $row['number'] > (int) $groups[$key]['last']['number']) {
            $groups[$key]['last'] = $row;
        }
    }
    $groups = array_values($groups);
    usort($groups, fn ($a, $b) => [$a['first']['local_time'] ?? '', $a['first']['show_name']]
        <=> [$b['first']['local_time'] ?? '', $b['first']['show_name']]);
    if (count($groups) <= CAL_DAY_LIMIT) {
        return [$groups, []];
    }
    $ranked = $groups;
    uasort($ranked, fn ($a, $b) => [(int) $b['first']['trackers'], (float) $b['first']['rating']]
        <=> [(int) $a['first']['trackers'], (float) $a['first']['rating']]);
    $top = array_slice($ranked, 0, CAL_DAY_LIMIT, true);
    return [array_values(array_intersect_key($groups, $top)), array_values(array_diff_key($groups, $top))];
}

function calendar_entry_html(array $g): string
{
    $first = $g['first'];
    $last = $g['last'];
    $season = (int) $first['season'];
    $single = $first['number'] === $last['number'];
    $code = episode_code($season, (int) $first['number']);
    if (!$single) {
        $code .= '–' . sprintf('E%02d', (int) $last['number']);
    }
    $title = $first['show_name'] . ' — ' . $code
        . ($single && $first['ep_name'] ? ' ' . $first['ep_name'] : '');
    $badges = array_unique(array_filter([
        episode_badge_html($season, (int) $first['number'], $first['finale']),
        episode_badge_html($season, (int) $last['number'], $last['finale']),
    ]));

    $html = '<li class="cal-ep" title="' . htmlspecialchars($title) . '">';
    $html .= '<a href="' . htmlspecialchars(series_url($first['imdb_id'])) . '">';
    if ($b = network_badge_info($first['network'])) {
        $html .= '<img class="net-inline" src="' . htmlspecialchars($b[1]) . '" alt="' . htmlspecialchars($b[0]) . '" loading="lazy">';
    }
    $html .= htmlspecialchars($first['show_name']) . '</a>';
    $html .= '<span class="cal-ep-meta">' . $code
        . ($first['local_time'] ? ' · ' . $first['local_time'] : '') . '</span>';
    if ($badges) {
        $html .= '<span class="cal-ep-badges">' . implode(' ', $badges) . '</span>';
    }
    return $html . '</li>';
}

// ---- List view: the next UPCOMING_DAYS days ----
$windowEnd = date('Y-m-d', strtotime(today() . ' +' . UPCOMING_DAYS . ' days'));
$byDate = upcoming_by_date(today(), $windowEnd, $mine);

// ---- Calendar view: one month, Monday-first weeks ----
$thisMonth = date('Y-m');
$monthMin = date('Y-m', strtotime($thisMonth . '-01 -' . CAL_MONTHS_BACK . ' months'));
$monthMax = date('Y-m', strtotime($thisMonth . '-01 +' . CAL_MONTHS_AHEAD . ' months'));
$monthParam = (string) ($_GET['month'] ?? '');
$calMonth = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthParam) === 1
    ? min(max($monthParam, $monthMin), $monthMax) : $thisMonth;
$monthStart = $calMonth . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));
// Pad to whole weeks; the padding days show their episodes too (dimmed).
$gridStart = date('Y-m-d', strtotime($monthStart . ' -' . (date('N', strtotime($monthStart)) - 1) . ' days'));
$gridEnd = date('Y-m-d', strtotime($monthEnd . ' +' . (7 - date('N', strtotime($monthEnd))) . ' days'));
$calByDate = upcoming_by_date($gridStart, $gridEnd, $mine);
$monthHasEpisodes = (bool) array_filter(array_keys($calByDate), fn ($d) => str_starts_with($d, $calMonth));
$prevMonth = date('Y-m', strtotime($monthStart . ' -1 month'));
$nextMonth = date('Y-m', strtotime($monthStart . ' +1 month'));
$monthNames = explode(',', t('month_names'));
$weekdays = explode(',', t('weekdays_short'));
$monthUrl = fn (string $m) => lang_path('/upcoming') . '?month=' . $m;

// An explicit month means the calendar; otherwise the remembered tab.
$view = $monthParam !== '' || ($_COOKIE['upcoming_view'] ?? '') === 'calendar' ? 'calendar' : 'list';

$pageTitle = t('pub_upcoming_title');
$canonicalUrl = seo_base() . lang_path('/upcoming');
$metaDescription = t('pub_upcoming_sub', UPCOMING_DAYS);
$subtitle = $mine ? t('upcoming_sub_mine') : t('pub_upcoming_sub', UPCOMING_DAYS);
$emptyMsg = $mine ? t('upcoming_none_mine') : t('pub_no_upcoming', UPCOMING_DAYS);

require __DIR__ . '/includes/header.php';
?>
<h1><?= t('pub_upcoming_title') ?></h1>

<div id="upcoming-tabs">
    <div class="filter-tabs" role="tablist">
        <button type="button" class="filter-tab<?= $view === 'list' ? ' active' : '' ?>" data-tab="list"><?= t('upcoming_view_list') ?></button>
        <button type="button" class="filter-tab<?= $view === 'calendar' ? ' active' : '' ?>" data-tab="calendar"><?= t('upcoming_view_calendar') ?></button>
    </div>

    <div class="filter-panel<?= $view === 'list' ? '' : ' hidden' ?>" data-panel="list">
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
                            <?php if ($b = network_badge_info($ep['network'])): ?>
                                <img class="net-inline" src="<?= htmlspecialchars($b[1]) ?>" alt="<?= htmlspecialchars($b[0]) ?>" loading="lazy">
                            <?php endif; ?>
                            <a class="cal-show" href="<?= htmlspecialchars(series_url($ep['imdb_id'])) ?>"><?= htmlspecialchars($ep['show_name']) ?></a>
                            <?= episode_code((int) $ep['season'], (int) $ep['number']) ?>
                            <?= episode_badge_html((int) $ep['season'], (int) $ep['number'], $ep['finale']) ?>
                            <?= htmlspecialchars($ep['ep_name'] ?? '') ?>
                            <?php if ($ep['local_time']): ?><span class="muted">· <?= $ep['local_time'] ?></span><?php endif; ?>
                        </span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="filter-panel<?= $view === 'calendar' ? '' : ' hidden' ?>" data-panel="calendar" data-month="<?= $calMonth ?>" data-this-month="<?= $thisMonth ?>">
        <p class="muted"><?= $mine ? t('cal_sub_mine') : t('cal_sub') ?></p>
        <div class="cal-nav">
            <?php if ($calMonth > $monthMin): ?>
                <a class="cal-nav-btn" href="<?= htmlspecialchars($monthUrl($prevMonth)) ?>" title="<?= t('cal_prev_month') ?>" aria-label="<?= t('cal_prev_month') ?>">‹</a>
            <?php else: ?>
                <span class="cal-nav-btn disabled" aria-hidden="true">‹</span>
            <?php endif; ?>
            <h2><?= htmlspecialchars($monthNames[(int) substr($calMonth, 5) - 1] . ' ' . substr($calMonth, 0, 4)) ?></h2>
            <?php if ($calMonth < $monthMax): ?>
                <a class="cal-nav-btn" href="<?= htmlspecialchars($monthUrl($nextMonth)) ?>" title="<?= t('cal_next_month') ?>" aria-label="<?= t('cal_next_month') ?>">›</a>
            <?php else: ?>
                <span class="cal-nav-btn disabled" aria-hidden="true">›</span>
            <?php endif; ?>
            <?php if ($calMonth !== $thisMonth): ?>
                <a class="button button-secondary button-small cal-nav-today" href="<?= htmlspecialchars($monthUrl($thisMonth)) ?>"><?= t('today_label') ?></a>
            <?php endif; ?>
        </div>
        <?php if (!$monthHasEpisodes): ?>
            <p class="muted"><?= t('cal_none_month') ?></p>
        <?php endif; ?>
        <div class="cal-month">
            <?php foreach ($weekdays as $wd): ?>
                <div class="cal-wd"><?= htmlspecialchars($wd) ?></div>
            <?php endforeach; ?>
            <?php for ($d = $gridStart; $d <= $gridEnd; $d = date('Y-m-d', strtotime($d . ' +1 day'))): ?>
                <?php
                [$visible, $hidden] = calendar_day_entries($calByDate[$d] ?? []);
                $classes = 'cal-day'
                    . (str_starts_with($d, $calMonth) ? '' : ' cal-out')
                    . ($d === today() ? ' cal-today' : '')
                    . ($visible ? '' : ' cal-empty');
                ?>
                <div class="<?= $classes ?>">
                    <div class="cal-head">
                        <span class="cal-dow"><?= htmlspecialchars($weekdays[date('N', strtotime($d)) - 1]) ?></span>
                        <span class="cal-num"><?= (int) substr($d, 8) ?></span>
                    </div>
                    <?php if ($visible): ?>
                        <ul class="cal-eps">
                            <?php foreach ($visible as $g): ?><?= calendar_entry_html($g) ?><?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($hidden): ?>
                        <details class="cal-more">
                            <summary><span class="cal-more-open"><?= t('cal_more', count($hidden)) ?></span><span class="cal-more-close"><?= t('cal_less') ?></span></summary>
                            <ul class="cal-eps">
                                <?php foreach ($hidden as $g): ?><?= calendar_entry_html($g) ?><?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endfor; ?>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
