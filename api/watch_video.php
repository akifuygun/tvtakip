<?php
// GET ?episode_id=N&site=DB
// The video behind one Watch site's page for an episode, for the TV view's
// ▶ badge (admins only, like the links). Only the site's own pages are read:
// the episode page — wherever the configured address lands, the site may
// have moved — then the player page its video container frames, while that
// stays on the site; 3 hops and 25 s at most, redirects only to named public
// hosts. The first off-site frame — the video host's embed — is reported,
// not fetched; a .m3u8/.mp4 the pages state is reported too.
//   -> {page, player, embed, video, text}   text = the video, else the embed, else the player
//   -> {error, page, player, embed, video} with 502 when the episode page gave nothing
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/http.php';
require_login_json();

if (!watch_enabled()) {
    json_response(['error' => 'Admins only'], 403);
}
$episodeId = (int) ($_GET['episode_id'] ?? 0);
$site = (string) ($_GET['site'] ?? '');
if ($episodeId < 1 || !isset(watch_sites()[$site])) {
    json_response(['error' => 'Invalid episode or site'], 400);
}

$stmt = db()->prepare(
    'SELECT e.season, e.number, e.finale,
            s.name, s.original_name, s.original_language, s.premiered, s.watch_slug
     FROM episodes e JOIN shows s ON s.imdb_id = e.show_imdb_id WHERE e.id = ?'
);
$stmt->execute([$episodeId]);
$row = $stmt->fetch();
if (!$row) {
    json_response(['error' => 'Episode not found'], 404);
}
$page = '';
foreach (watch_links($row, (int) $row['season'], (int) $row['number'], $row['finale']) as $link) {
    if ($link['site'] === $site) {
        $page = $link['url'];
    }
}
if ($page === '') {
    json_response(['error' => 'No link for this episode'], 404); // specials have none
}

// The fetches take seconds; don't hold the viewer's session lock meanwhile.
// 25 s for all of them together: the host kills requests at about 30.
session_write_close();
@set_time_limit(40);
$deadline = microtime(true) + 25;

$out = ['page' => $page, 'player' => '', 'embed' => '', 'video' => ''];
$url = $page;
$referer = '';
$domain = ''; // the site's domain: where the configured address lands
for ($hop = 0; $hop < 3; $hop++) {
    $left = (int) ($deadline - microtime(true));
    // Redirects stay on named public hosts and, after the first page, on the site.
    $res = $left > 0
        ? http_get_page($url, $referer, $left, fn (string $to) => public_http_url($to) && ($domain === '' || site_domain($to) === $domain))
        : null;
    if ($res === null) {
        if ($hop === 0) {
            json_response(['error' => t('tv_video_unreachable')] + $out, 502);
        }
        break; // the player page gave nothing; its address is still an answer
    }
    if ($domain === '') {
        $domain = site_domain($res['url']);
    }
    if ($out['video'] === '') {
        $out['video'] = page_media_url($res['body']);
        if ($out['video'] !== '') {
            break;
        }
    }
    $next = page_player_frame($res['body'], $res['url']);
    if ($next === '' || !public_http_url($next)) {
        if ($hop === 0) {
            json_response(['error' => $res['code'] === 200 ? t('tv_video_none') : t('tv_video_http', $res['code'])] + $out, 502);
        }
        break;
    }
    if (site_domain($next) !== $domain) {
        $out['embed'] = $next; // the video host: reported, not read
        break;
    }
    $out['player'] = $next;
    $referer = $res['url'];
    $url = $next;
}

$out['text'] = $out['video'] !== '' ? $out['video'] : ($out['embed'] !== '' ? $out['embed'] : $out['player']);
if ($out['text'] === '') {
    json_response(['error' => t('tv_video_none')] + $out, 502);
}
json_response($out);
