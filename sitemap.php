<?php
// Dynamic sitemap: the public pages plus every cached show's series page.
// Served as /sitemap.xml via rewrite.
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/xml; charset=utf-8');
$base = seo_base();

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

$static = [
    ['/', 'weekly', '1.0'],
    ['/browse', 'weekly', '0.8'],
    ['/upcoming', 'daily', '0.8'],
];
foreach ($static as [$path, $freq, $prio]) {
    echo "  <url><loc>{$base}{$path}</loc><changefreq>{$freq}</changefreq><priority>{$prio}</priority></url>\n";
}

// Only synced shows — never-imported rows are thin, episode-less pages.
$shows = db()->query(
    'SELECT imdb_id, synced_at FROM shows WHERE synced_at IS NOT NULL ORDER BY imdb_id'
)->fetchAll();
foreach ($shows as $show) {
    $loc = htmlspecialchars($base . series_url($show['imdb_id']), ENT_XML1);
    $lastmod = '<lastmod>' . date('Y-m-d', strtotime($show['synced_at'])) . '</lastmod>';
    echo "  <url><loc>{$loc}</loc>{$lastmod}<changefreq>weekly</changefreq><priority>0.6</priority></url>\n";
}

echo "</urlset>\n";
