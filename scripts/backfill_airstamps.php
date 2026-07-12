<?php
// Backfill episodes.airstamp (exact UTC air times from TVmaze) for shows that
// were imported before airstamp support existed.
// Usage: php scripts/backfill_airstamps.php

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../includes/importer.php';

$pdo = db();
$shows = $pdo->query(
    'SELECT s.imdb_id, s.name FROM shows s
     WHERE EXISTS (SELECT 1 FROM episodes e
                   WHERE e.show_imdb_id = s.imdb_id AND e.airstamp IS NULL)
     ORDER BY s.imdb_id'
)->fetchAll();
echo count($shows) . " shows with missing airstamps\n";

$upd = $pdo->prepare(
    'UPDATE episodes SET airstamp = ?
     WHERE show_imdb_id = ? AND season = ? AND number = ? AND airstamp IS NULL'
);

$n = 0;
foreach ($shows as $show) {
    $n++;
    $stamps = tvmaze_airstamps($show['imdb_id']);
    if (!$stamps) {
        echo "[$n/" . count($shows) . "] {$show['name']}: not on TVmaze / no stamps\n";
        usleep(250000);
        continue;
    }
    $set = 0;
    $pdo->beginTransaction();
    foreach ($stamps as $key => $stamp) {
        [$season, $number] = explode('-', $key, 2);
        $upd->execute([$stamp, $show['imdb_id'], (int) $season, (int) $number]);
        $set += $upd->rowCount();
    }
    $pdo->commit();
    echo "[$n/" . count($shows) . "] {$show['name']}: $set stamps\n";
    usleep(250000); // stay under TVmaze rate limits
}
echo "DONE\n";
