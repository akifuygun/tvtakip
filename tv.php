<?php
// The calendar for a TV browser (/tv): the same episodes as index.php, big and
// remote-friendly, on a page of its own (no site header, nav or app.js).
// Marking watched is a plain form POST, so the page works with the TV
// browser's pointer even when the arrow-key helpers in tv.js don't get keys.
require_once __DIR__ . '/includes/calendar.php';

if (!is_logged_in()) {
    header('Location: /login.php?next=tv');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // The markable gate of api/watch.php: an episode airing later today isn't.
    $ok = verify_csrf($_POST['csrf_token'] ?? null);
    if ($ok) {
        $stmt = db()->prepare(
            'INSERT IGNORE INTO watched_episodes (user_id, episode_id)
             SELECT ?, id FROM episodes WHERE id = ? AND ' . aired_sql()
        );
        $stmt->execute([current_user_id(), (int) ($_POST['episode_id'] ?? 0), today()]);
    }
    // Back to the list, focused on the same show's next episode (or whatever
    // card took its place).
    header('Location: /tv?' . http_build_query([
        'show' => valid_imdb_id($_POST['show'] ?? null) ? $_POST['show'] : null,
        'pos' => (int) ($_POST['pos'] ?? 0),
        'expired' => $ok ? null : 1,
    ]), true, 303);
    exit;
}

$items = calendar_episodes(current_user_id());
$upcoming = $items ? [] : calendar_upcoming(current_user_id());

// Card that starts focused for the remote: the show just marked, else the
// position it was at, else the first card.
$focus = (int) ($_GET['pos'] ?? 0);
foreach ($items as $i => $ep) {
    if ($ep['show_imdb_id'] === ($_GET['show'] ?? null)) {
        $focus = $i;
        break;
    }
}
$focused = false;

/** Poster (or placeholder) with the network logo on it. */
function tv_poster(array $ep): string
{
    return network_badge_html($ep['network'], $ep['primary_network'])
        . ($ep['image_url']
            ? '<img class="tv-poster" src="' . htmlspecialchars($ep['image_url']) . '" alt="">'
            : '<div class="tv-poster tv-noposter">' . t('no_image') . '</div>');
}

/** A UTC airstamp in the viewer's timezone. */
function tv_local(string $airstamp, string $format): string
{
    return (new DateTimeImmutable($airstamp, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone(date_default_timezone_get()))->format($format);
}
?>
<!DOCTYPE html>
<html lang="<?= current_lang() ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, follow">
    <meta name="theme-color" content="#0f1218">
    <title><?= htmlspecialchars(app_name() . ' — ' . t('calendar_title')) ?></title>
    <link rel="stylesheet" href="/assets/css/tv.css?v=<?= ASSET_VERSION ?>">
    <link rel="icon" href="/favicon.ico" sizes="32x32">
</head>
<body>
<header class="tv-head">
    <h1>📅 <?= t('calendar_title') ?></h1>
    <span class="tv-date"><?= htmlspecialchars(format_date(today())) ?></span>
    <a class="tv-btn" href="/"><?= t('tv_full_site') ?></a>
</header>
<main class="tv-main">
    <?php if (isset($_GET['expired'])): ?>
        <p class="tv-notice"><?= t('session_expired') ?></p>
    <?php endif; ?>

    <?php if ($items): ?>
        <div class="tv-grid">
            <?php foreach ($items as $i => $ep): ?>
                <?php
                // Airs later today: not markable until its airstamp, so show the local time.
                $later = $ep['airstamp'] !== null && $ep['airstamp'] > gmdate('Y-m-d H:i:s');
                $watch = $later ? []
                    : watch_links(['name' => $ep['show_name']] + $ep, (int) $ep['season'], (int) $ep['number'], $ep['finale']);
                $autofocus = '';
                if (!$later && !$focused && $i >= $focus) {
                    $autofocus = ' autofocus';
                    $focused = true;
                }
                ?>
                <div class="tv-card" data-show="<?= htmlspecialchars($ep['show_imdb_id']) ?>">
                    <?= tv_poster($ep) ?>
                    <h2><?= htmlspecialchars($ep['show_name']) ?></h2>
                    <p class="tv-ep"><?= episode_code((int) $ep['season'], (int) $ep['number']) ?> <?= episode_badge_html((int) $ep['season'], (int) $ep['number'], $ep['finale']) ?></p>
                    <?php if ($later): ?>
                        <p class="tv-when"><?= htmlspecialchars(t('airs_on', tv_local($ep['airstamp'], 'H:i'))) ?></p>
                    <?php else: ?>
                        <div class="tv-actions">
                            <?php if ($watch): ?>
                                <div class="tv-play">
                                    <?php foreach ($watch as $w): ?>
                                        <a class="tv-btn tv-btn-play" href="<?= htmlspecialchars($w['url']) ?>" rel="noreferrer"<?= $autofocus ?>>▶ <?= htmlspecialchars($w['site']) ?></a>
                                        <?php $autofocus = ''; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <form method="post" action="/tv">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                <input type="hidden" name="episode_id" value="<?= (int) $ep['id'] ?>">
                                <input type="hidden" name="show" value="<?= htmlspecialchars($ep['show_imdb_id']) ?>">
                                <input type="hidden" name="pos" value="<?= $i ?>">
                                <button class="tv-btn" type="submit"<?= $autofocus ?>><?= t('mark_watched') ?></button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="tv-caught">
            <h2><?= t('all_caught_up') ?></h2>
            <p><?= $upcoming ? t('coming_up') : t('no_unwatched') ?></p>
        </div>
        <?php if ($upcoming): ?>
            <div class="tv-grid">
                <?php foreach ($upcoming as $ep): ?>
                    <div class="tv-card">
                        <?= tv_poster($ep) ?>
                        <h2><?= htmlspecialchars($ep['show_name']) ?></h2>
                        <p class="tv-ep"><?= episode_code((int) $ep['season'], (int) $ep['number']) ?> <?= episode_badge_html((int) $ep['season'], (int) $ep['number'], $ep['finale']) ?></p>
                        <p class="tv-when">📅 <?= htmlspecialchars(format_date($ep['airstamp'] ? tv_local($ep['airstamp'], 'Y-m-d') : $ep['airdate'])) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</main>
<footer class="tv-foot">
    <?= t('data_credit') ?>
    <a href="https://www.themoviedb.org" rel="noopener">TMDB</a> ·
    <a href="https://www.tvmaze.com" rel="noopener">TVmaze</a> ·
    <a href="https://thetvdb.com" rel="noopener">TheTVDB</a>
</footer>
<script src="/assets/js/tv.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>
