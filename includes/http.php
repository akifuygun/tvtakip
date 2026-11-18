<?php
// Shared HTTP helpers for provider requests (TMDB, TVmaze, TheTVDB).
// One place for retry/redirect/timeout policy — used by the web importer
// and the CLI scripts alike.

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

/**
 * Concurrent GETs, order-preserving; null for any URL that failed. At most
 * $limit run at once, and $perSecond (0 = no cap) caps how many start per
 * second — evenly spaced, so a provider's rate limit is never met in a burst.
 * Transient failures (anything but 401/404) get one retry pass.
 */
function http_multi_json(array $urls, int $limit = 12, float $perSecond = 0, bool $retry = true): array
{
    $results = array_fill(0, count($urls), null);
    if (!$urls) {
        return $results;
    }

    // Some shared hosts disable curl_multi_* even though
    // curl_multi_init exists. Fall back to sequential fetches — slower but
    // functionally identical.
    if (!function_exists('curl_multi_exec')) {
        foreach ($urls as $i => $url) {
            $results[$i] = http_get_json($url, 2);
        }
        return $results;
    }

    $mh = curl_multi_init();
    $active = [];      // handle => index into $urls
    $failed = [];      // indexes worth another try
    $throttled = false;
    $next = 0;
    $gap = $perSecond > 0 ? 1 / $perSecond : 0.0;
    $startAt = 0.0;    // earliest moment the next request may start
    do {
        while ($next < count($urls) && count($active) < $limit && ($now = microtime(true)) >= $startAt) {
            $ch = curl_init($urls[$next]);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            curl_multi_add_handle($mh, $ch);
            $active[(int) $ch] = $next++;
            $startAt = $now + $gap;
        }
        curl_multi_exec($mh, $running);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $i = $active[(int) $ch];
            $body = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $decoded = ($code === 200 && $body) ? json_decode($body, true) : null;
            if (is_array($decoded)) {
                $results[$i] = $decoded;
            } elseif ($code !== 401 && $code !== 404) {
                $failed[] = $i;
                $throttled = $throttled || $code === 429;
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($active[(int) $ch]);
        }
        if ($active) {
            // Until a response arrives — or the next start slot, when one is free.
            $slotFree = $next < count($urls) && count($active) < $limit;
            if (curl_multi_select($mh, $slotFree ? max(0.0, $startAt - microtime(true)) : 0.2) === -1) {
                usleep(5000);
            }
        } elseif ($next < count($urls)) {
            usleep((int) (max(0.0, $startAt - microtime(true)) * 1000000));
        }
    } while ($active || $next < count($urls));
    curl_multi_close($mh);

    // One retry pass, after a pause — a longer one when the provider said it
    // was being asked too fast.
    if ($retry && $failed) {
        usleep($throttled ? 2000000 : 400000);
        $again = http_multi_json(array_map(fn($i) => $urls[$i], $failed), $limit, $perSecond, false);
        foreach ($failed as $k => $i) {
            $results[$i] = $again[$k];
        }
    }
    return $results;
}
