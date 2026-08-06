<?php
// Curated network branding: the brand groups (browse filter chips) and the
// self-hosted logo map. To add a network: drop a PNG into assets/networks/,
// add a map entry below, and (if it's a new brand) a group entry.

/**
 * MANUALLY CURATED brand groups, in display order: [display label, [member
 * channel names (exact TMDB strings)]]. Browse builds its filter chips from
 * this (first member supplies the logo); poster badges resolve any member
 * name to the group's logo via network_group_logo().
 */
function network_groups(): array
{
    return [
        ['Netflix', ['Netflix']],
        ['Disney', ['Disney+', 'Disney Channel', 'Disney XD', 'Hulu']],
        ['Prime Video', ['Prime Video']],
        ['Apple TV', ['Apple TV']],
        ['HBO', ['HBO', 'HBO Max', 'Max', 'HBO Latin America', 'BluTV', 'DC Universe']],
        ['Paramount', ['Paramount+', 'Paramount Network', 'Paramount+ with Showtime', 'Showtime']],
        ['FX', ['FX', 'FXX']],
        ['STARZ', ['STARZ']],
        ['ABC', ['ABC', 'ABC Family', 'ABC Kids', 'ABC.com']],
        ['NBC', ['NBC']],
        ['CBS', ['CBS', 'CBS All Access']],
        ['FOX', ['FOX']],
        ['The CW', ['The CW']],
        ['BBC', ['BBC One', 'BBC Two', 'BBC Three', 'BBC America']],
        ['tabii', ['tabii']],
        ['GAİN', ['GAİN']],
        ['Exxen', ['Exxen']],
        ['YouTube', ['YouTube', 'YouTube Premium']],
    ];
}

/** Exact network-name -> self-hosted logo path; null when we have no image. */
function network_logo(string $name): ?string
{
    static $map = [
        'Netflix' => '/assets/networks/netflix.png',
        'Disney+' => '/assets/networks/disney-plus.png',
        'Prime Video' => '/assets/networks/prime-video.png',
        'Apple TV' => '/assets/networks/apple-tv.png',
        'HBO' => '/assets/networks/hbo.png',
        'Paramount+' => '/assets/networks/paramount-plus.png',
        'FX' => '/assets/networks/fx.png',
        'STARZ' => '/assets/networks/starz.png',
        'ABC' => '/assets/networks/abc.png',
        'NBC' => '/assets/networks/nbc.png',
        'CBS' => '/assets/networks/cbs.png',
        'FOX' => '/assets/networks/fox.png',
        'The CW' => '/assets/networks/the-cw.png',
        'BBC One' => '/assets/networks/bbc-one.png',
        'tabii' => '/assets/networks/tabii.png',
        'GAİN' => '/assets/networks/gain.png',
        'Exxen' => '/assets/networks/exxen.png',
        'YouTube' => '/assets/networks/youtube.png',
        // Group members with their own channel branding (badge shows the
        // channel's logo; the direct match wins over the group fallback):
        'Max' => '/assets/networks/max.png',
        'Showtime' => '/assets/networks/showtime.png',
        'Hulu' => '/assets/networks/hulu.png',
        // Badge-only (deliberately in no filter group — these shows stay
        // under the "Others" chip but still get a poster badge):
        'Peacock' => '/assets/networks/peacock.png',
        'Adult Swim' => '/assets/networks/adult-swim.png',
        'Cartoon Network' => '/assets/networks/cartoon-network.png',
        'AMC' => '/assets/networks/amc.png',
        'Syfy' => '/assets/networks/syfy.png',
        'USA Network' => '/assets/networks/usa-network.png',
        'Nickelodeon' => '/assets/networks/nickelodeon.png',
        'ITV1' => '/assets/networks/itv1.png',
        'Tokyo MX' => '/assets/networks/tokyo-mx.png',
        'TV Tokyo' => '/assets/networks/tv-tokyo.png',
        'AT-X' => '/assets/networks/at-x.png',
        'MBS' => '/assets/networks/mbs.png',
        'Fuji TV' => '/assets/networks/fuji-tv.png',
        'Nippon TV' => '/assets/networks/nippon-tv.png',
    ];
    return $map[$name] ?? null;
}

/** Split a comma-separated network list ("FOX, Netflix") into trimmed names. */
function network_names(?string $list): array
{
    return array_values(array_filter(array_map('trim', explode(',', (string) $list))));
}

/**
 * Badge for a show's (possibly multi-valued) network list: [name, logo] of the
 * first listed network with its own logo, else the first whose brand group has
 * one (e.g. "HBO Max" -> HBO's logo). Null when nothing matches.
 */
function network_badge_info(?string $list): ?array
{
    $names = network_names($list);
    foreach ($names as $n) {
        if ($logo = network_logo($n)) {
            return [$n, $logo];
        }
    }
    foreach ($names as $n) {
        foreach (network_groups() as [$label, $members]) {
            if (in_array($n, $members, true) && ($logo = network_logo($members[0]))) {
                return [$n, $logo];
            }
        }
    }
    return null;
}
