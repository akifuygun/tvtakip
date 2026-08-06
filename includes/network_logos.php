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
        ['Disney', ['Disney+', 'Disney Channel', 'Disney XD']],
        ['Prime Video', ['Prime Video']],
        ['Apple TV', ['Apple TV']],
        ['HBO', ['HBO', 'HBO Max', 'HBO Latin America', 'BluTV', 'DC Universe']],
        ['Paramount', ['Paramount+', 'Paramount Network', 'Paramount+ with Showtime']],
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
        // Badge-only (deliberately in no filter group — these shows stay
        // under the "Others" chip but still get a poster badge):
        'Peacock' => '/assets/networks/peacock.png',
        'Adult Swim' => '/assets/networks/adult-swim.png',
    ];
    return $map[$name] ?? null;
}

/**
 * Logo for a network, falling back to its brand group's logo — so e.g. an
 * "HBO Max" show carries the HBO badge. Null when neither matches.
 */
function network_group_logo(string $name): ?string
{
    $direct = network_logo($name);
    if ($direct) {
        return $direct;
    }
    foreach (network_groups() as [$label, $members]) {
        if (in_array($name, $members, true)) {
            return network_logo($members[0]);
        }
    }
    return null;
}
