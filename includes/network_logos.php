<?php
// Network-name -> logo path map, served statically from assets/networks/
// (self-hosted; originally sourced from TMDB's logo CDN). Keys are the FIRST
// member name of each $NETWORK_GROUPS entry in browse.php — the only names
// whose logos render. To add a network: drop a PNG into assets/networks/ and
// add its entry here; names without an entry fall back to a text chip.
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
    ];
    return $map[$name] ?? null;
}
