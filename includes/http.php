<?php
// Shared HTTP helpers for provider requests (TMDB, TVmaze, TheTVDB) and
// page fetches. One place for retry/redirect/timeout policy — used by the
// web importer, the API and the CLI scripts alike.

/** GET a JSON document. Retries transient failures, follows redirects
 *  (TVmaze /lookup answers with a 301). Returns null on failure. */
function http_get_json(string $url, int $retries = 3, array $headers = []): ?array
{
    for ($try = 0; $try < $retries; $try++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body !== false && $code === 200) {
            $data = json_decode($body, true);
            if (is_array($data)) {
                return $data;
            }
        }
        if ($code === 404 || $code === 401) {
            return null; // definitive, don't retry
        }
        usleep($code === 429 ? 1500000 : 400000);
    }
    return null;
}

// ---- Web pages (the Watch sites' episode pages) ----

// They answer browsers; curl's own User-Agent gets bot walls instead.
const HTTP_PAGE_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
// Bytes of a page worth reading: HTML, not a media file a frame may point at.
const HTTP_PAGE_MAX = 2097152;

/**
 * GET a web page as a browser would: ['code' => status, 'body' => the first
 * HTTP_PAGE_MAX bytes, 'url' => the URL that answered], or null when nothing
 * came back within $timeout seconds in all. http(s) only. Redirects (5 at
 * most) are followed one at a time; with $allow, a target it refuses is not
 * fetched and the redirect answer itself is returned. $referer is sent when
 * given.
 */
function http_get_page(string $url, string $referer = '', int $timeout = 10, ?callable $allow = null): ?array
{
    $deadline = microtime(true) + $timeout;
    for ($redirects = 0; $redirects <= 5; $redirects++) {
        $left = $deadline - microtime(true);
        if ($left < 0.5 || !preg_match('#^https?://#i', $url)) {
            return null;
        }
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT_MS => (int) ($left * 1000),
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => HTTP_PAGE_UA,
            CURLOPT_HTTPHEADER => array_merge([
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: tr,en;q=0.8',
            ], $referer !== '' ? ['Referer: ' . $referer] : []),
            // Past the cap, 0 tells curl to stop; the body so far is kept.
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$body): int {
                $body .= $chunk;
                return strlen($body) > HTTP_PAGE_MAX ? 0 : strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $next = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $stopped = curl_errno($ch) === CURLE_WRITE_ERROR;
        curl_close($ch);
        if ($ok === false && !$stopped) {
            return null;
        }
        if ($next === '' || !in_array($code, [301, 302, 303, 307, 308], true) || ($allow !== null && !$allow($next))) {
            return ['code' => $code, 'body' => $body, 'url' => $url];
        }
        $url = $next;
    }
    return null;
}

/** The URL $href points to from page $base: absolute, scheme-relative,
 *  root-relative or relative; HTML entities decoded. */
function absolute_url(string $href, string $base): string
{
    $href = trim(html_entity_decode($href, ENT_QUOTES));
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) {
        return $href;
    }
    $b = parse_url($base);
    $scheme = $b['scheme'] ?? 'https';
    $origin = $scheme . '://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($href, '//')) {
        return $scheme . ':' . $href;
    }
    if (str_starts_with($href, '/')) {
        return $origin . $href;
    }
    return $origin . preg_replace('#[^/]*$#', '', $b['path'] ?? '/') . $href;
}

/** An http(s) URL on a named public host (no IP literals, no localhost). */
function public_http_url(string $url): bool
{
    $p = parse_url($url);
    return in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true)
        && preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/i', (string) ($p['host'] ?? '')) === 1;
}

/** The registrable part of a URL's host: "www.example.com" -> "example.com",
 *  "www.example.com.tr" -> "example.com.tr" (a short generic label before a
 *  two-letter country code is part of the public suffix). */
function site_domain(string $url): string
{
    $labels = explode('.', strtolower((string) parse_url($url, PHP_URL_HOST)));
    $n = count($labels);
    $keep = $n >= 3 && strlen($labels[$n - 1]) === 2
        && preg_match('/^(?:com|net|org|gov|edu|co|ac|biz|info|gen|web|av|bel|k12|pol|tsk|mil|name|tv|dr)$/', $labels[$n - 2])
        ? 3 : 2;
    return implode('.', array_slice($labels, -$keep));
}

/** The address of a page's player frame: the first iframe from the first
 *  element whose id mentions video or player (the frame itself, maybe), else
 *  the first iframe at all (a lazy-loading one keeps it in data-src); ''
 *  when there is none. Made absolute against $pageUrl. */
function page_player_frame(string $html, string $pageUrl): string
{
    $from = preg_match('/<[a-z][^>]*?(?<![\w-])id\s*=\s*["\'][^"\']*(?:video|player)[^"\']*["\']/i', $html, $m, PREG_OFFSET_CAPTURE)
        ? $m[0][1] : 0;
    if (!preg_match('/<iframe\b[^>]*>/i', $html, $m, 0, $from) && !preg_match('/<iframe\b[^>]*>/i', $html, $m)) {
        return '';
    }
    if (!preg_match('/\sdata-src\s*=\s*["\']([^"\']+)["\']/i', $m[0], $src)
        && !preg_match('/\ssrc\s*=\s*["\']([^"\']+)["\']/i', $m[0], $src)) {
        return '';
    }
    return absolute_url($src[1], $pageUrl);
}

/** The first .m3u8/.mp4 URL a page states (markup or script), '' when none. */
function page_media_url(string $html): string
{
    $text = str_replace('\\/', '/', $html); // JSON-escaped URLs in scripts
    return preg_match('#https?://[^\s"\'<>\\\\]+?\.(?:m3u8|mp4)(?:\?[^\s"\'<>\\\\]*)?(?=[\s"\'<>\\\\),;]|$)#i', $text, $m)
        ? $m[0] : '';
}

/** POST a JSON body, decode a JSON 200 answer. One attempt; null on failure. */
function http_post_json(string $url, array $payload): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $data = ($body !== false && $code === 200) ? json_decode($body, true) : null;
    return is_array($data) ? $data : null;
}

/** Concurrent GETs, order-preserving; null for any URL that failed.
 *  A single retry pass re-fetches failures sequentially. */
function http_multi_json(array $urls, int $limit = 12): array
{
    $results = array_fill(0, count($urls), null);
    if (!$urls) {
        return $results;
    }

    // Some shared hosts (InfinityFree) disable curl_multi_* even though
    // curl_multi_init exists. Fall back to sequential fetches — slower but
    // functionally identical.
    if (!function_exists('curl_multi_exec')) {
        foreach ($urls as $i => $url) {
            $results[$i] = http_get_json($url, 2);
        }
        return $results;
    }

    $mh = curl_multi_init();
    $active = [];
    $next = 0;
    $add = function () use (&$next, $urls, $mh, &$active) {
        if ($next >= count($urls)) {
            return;
        }
        $i = $next++;
        $ch = curl_init($urls[$i]);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        curl_multi_add_handle($mh, $ch);
        $active[(int) $ch] = $i;
    };
    for ($i = 0; $i < $limit; $i++) {
        $add();
    }
    do {
        curl_multi_exec($mh, $running);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $i = $active[(int) $ch];
            $body = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $decoded = ($code === 200 && $body) ? json_decode($body, true) : null;
            $results[$i] = is_array($decoded) ? $decoded : null;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($active[(int) $ch]);
            $add();
        }
        if ($running) {
            curl_multi_select($mh, 0.2);
        }
    } while ($running || $active);
    curl_multi_close($mh);

    // One sequential retry pass for transient failures.
    foreach ($results as $i => $result) {
        if ($result === null) {
            $results[$i] = http_get_json($urls[$i], 2);
        }
    }
    return $results;
}
