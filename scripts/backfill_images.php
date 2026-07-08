<?php
// Fill missing show posters by combining providers: TMDB first, TVmaze second.
// Usage: php scripts/backfill_images.php

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../includes/importer.php';

$pdo = db();
$shows = $pdo->query("SELECT imdb_id, name FROM shows WHERE image_url IS NULL OR image_url = ''")->fetchAll();
echo count($shows) . " shows without an image\n";

$update = $pdo->prepare('UPDATE shows SET image_url = ? WHERE imdb_id = ?');

foreach ($shows as $show) {
    $imdbId = $show['imdb_id'];
    $image = null;
    $source = null;

    $found = http_get_json(tmdb_api_url("/find/$imdbId", ['external_source' => 'imdb_id']));
    $poster = $found['tv_results'][0]['poster_path'] ?? null;
    if ($poster) {
        $image = TMDB_IMG_BASE . $poster;
        $source = 'TMDB';
    }

    if (!$image) {
        $tvmaze = http_get_json(TVMAZE_BASE . '/lookup/shows?imdb=' . urlencode($imdbId));
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
