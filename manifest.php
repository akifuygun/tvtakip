<?php
// Localized PWA manifest: name/description follow the viewer's language
// (TVTrack / TVTakip) rather than the old static English-only manifest.
// Only i18n is needed — avoid auth.php so a manifest fetch doesn't start a
// session or run the remember-me flow.
require_once __DIR__ . '/includes/i18n.php';

header('Content-Type: application/manifest+json; charset=utf-8');

$name = app_name();
echo json_encode([
    'name' => $name,
    'short_name' => $name,
    'description' => t('tagline'),
    'start_url' => '/index.php',
    'scope' => '/',
    'display' => 'standalone',
    'orientation' => 'portrait-primary',
    'background_color' => '#0f1218',
    'theme_color' => '#1a1f2a',
    'icons' => [
        ['src' => '/assets/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => '/assets/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ['src' => '/assets/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
