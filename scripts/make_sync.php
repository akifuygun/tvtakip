<?php
// Generate deploy/sync_data.sql: the local shows+episodes cache as
// INSERT ... ON DUPLICATE KEY UPDATE statements (one per line), safe to run
// on the live DB — inserts new rows, refreshes provider fields on existing
// ones, never deletes (user data untouched). Consumed by the resumable web
// runner (sync_run.php).
//
// Usage: php scripts/make_sync.php

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../includes/auth.php';

$pdo = db();
$out = fopen(__DIR__ . '/../deploy/sync_data.sql', 'w');
$q = fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v);

fwrite($out, "SET NAMES utf8mb4;\n");

// Shows
$rows = $pdo->query(
    'SELECT imdb_id, name, image_url, status, overview, premiered, synced_at FROM shows ORDER BY imdb_id'
)->fetchAll();
foreach (array_chunk($rows, 100) as $chunk) {
    $values = [];
    foreach ($chunk as $r) {
        $values[] = '(' . implode(',', [
            $q($r['imdb_id']), $q($r['name']), $q($r['image_url']), $q($r['status']),
            $q($r['overview']), $q($r['premiered']), $q($r['synced_at']),
        ]) . ')';
    }
    fwrite($out,
        'INSERT INTO shows (imdb_id,name,image_url,status,overview,premiered,synced_at) VALUES '
        . implode(',', $values)
        . ' ON DUPLICATE KEY UPDATE name=VALUES(name),'
        . ' image_url=COALESCE(VALUES(image_url),image_url),'
        . ' status=COALESCE(VALUES(status),status),'
        . ' overview=COALESCE(VALUES(overview),overview),'
        . ' premiered=COALESCE(VALUES(premiered),premiered),'
        . ' synced_at=COALESCE(VALUES(synced_at),synced_at);' . "\n");
}
echo count($rows) . " shows\n";

// Episodes
$stmt = $pdo->query(
    'SELECT show_imdb_id, imdb_id, season, number, name, airdate, airstamp FROM episodes ORDER BY show_imdb_id, season, number'
);
$total = 0;
$chunk = [];
$flush = function () use (&$chunk, $out) {
    if (!$chunk) {
        return;
    }
    fwrite($out,
        'INSERT INTO episodes (show_imdb_id,imdb_id,season,number,name,airdate,airstamp) VALUES '
        . implode(',', $chunk)
        . ' ON DUPLICATE KEY UPDATE imdb_id=COALESCE(VALUES(imdb_id),imdb_id),'
        . ' name=VALUES(name), airdate=VALUES(airdate),'
        . ' airstamp=COALESCE(VALUES(airstamp),airstamp);' . "\n");
    $chunk = [];
};
foreach ($stmt as $r) {
    $chunk[] = '(' . implode(',', [
        $q($r['show_imdb_id']), $q($r['imdb_id']), (int) $r['season'], (int) $r['number'],
        $q($r['name']), $q($r['airdate']), $q($r['airstamp']),
    ]) . ')';
    $total++;
    if (count($chunk) >= 250) {
        $flush();
    }
}
$flush();
fclose($out);
echo "$total episodes\n";
echo 'sync_data.sql: ' . round(filesize(__DIR__ . '/../deploy/sync_data.sql') / 1048576, 2) . " MB\n";
