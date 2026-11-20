<?php
// POST {site: "DB", base: "https://www.example.com/"}
// Set one Watch site's origin (scheme + host), for when it moves domains —
// the footer ❤. Paths stay its WATCH_SITES template's. Saved in the settings
// table as "watch_base.<site>"; an empty base — or the template's own origin —
// clears it, so the site follows its template again.
require_once __DIR__ . '/../../includes/auth.php';
require_login_json();

if (!watch_enabled()) {
    json_response(['error' => 'Admins only'], 403);
}

$data = read_json_post();
$site = (string) ($data['site'] ?? '');
$input = trim((string) ($data['base'] ?? ''));
if (!isset(watch_sites()[$site])) {
    json_response(['error' => 'Unknown site'], 400);
}

$origin = $input === '' ? '' : input_origin($input);
if ($origin === null) {
    json_response(['error' => t('watch_base_invalid')], 400);
}
$template = url_origin(watch_sites()[$site]['url']);
set_setting(WATCH_BASE_SETTING . $site, $origin === '' || $origin === $template ? null : $origin);

json_response(['ok' => true, 'base' => watch_bases()[$site]]);
