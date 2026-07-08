<?php
// Shared HTTP helpers for provider requests (TMDB, TVmaze).
// One place for retry/redirect/timeout policy — used by the web importer
// and the CLI scripts alike.

/** GET a JSON document. Retries transient failures, follows redirects
 *  (TVmaze /lookup answers with a 301). Returns null on failure. */
function http_get_json(string $url, int $retries = 3): ?array
{
    for ($try = 0; $try < $retries; $try++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
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
        if ($code === 404) {
            return null; // definitive, don't retry
        }
        usleep($code === 429 ? 1500000 : 400000);
    }
    return null;
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
