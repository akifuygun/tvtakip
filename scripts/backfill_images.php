<?php
// Fill missing show posters by combining providers: TMDB first, TVmaze second.
// Usage: php scripts/backfill_images.php

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../includes/auth.php';

function http_json(string $url): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => true]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ($body !== false && $code === 200) ? json_decode($body, true) : null;
}

$pdo = db();
$shows = $pdo->query("SELECT imdb_id, name FROM shows WHERE image_url IS NULL OR image_url = ''")->fetchAll();
echo count($shows) . " shows without an image\n";

$update = $pdo->prepare('UPDATE shows SET image_url = ? WHERE imdb_id = ?');

foreach ($shows as $show) {
    $imdbId = $show['imdb_id'];
    $image = null;
    $source = null;

    $find = http_json('https://api.themoviedb.org/3/find/' . $imdbId . '?' . http_build_query([
        'external_source' => 'imdb_id',
        'api_key' => TMDB_API_KEY,
    ]));
    $poster = $find['tv_results'][0]['poster_path'] ?? null;
    if ($poster) {
        $image = 'https://image.tmdb.org/t/p/w342' . $poster;
        $source = 'TMDB';
    }

    if (!$image) {
        $tvmaze = http_json('https://api.tvmaze.com/lookup/shows?imdb=' . urlencode($imdbId));
        if (!empty($tvmaze['image']['medium'])) {
            $image = $tvmaze['image']['medium'];
            $source = 'TVmaze';
        }
        usleep(300000);
    }

    if ($image) {
        $update->execute([$image, $imdbId]);
        echo "{$show['name']} ($imdbId): image from $source\n";
    } else {
        echo "{$show['name']} ($imdbId): no image on either provider\n";
    }
}
