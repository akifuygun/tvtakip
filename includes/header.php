<?php
require_once __DIR__ . '/auth.php';
$pageTitle = $pageTitle ?? app_name();
$fullTitle = app_name() . ' — ' . $pageTitle;
$metaDescription = $metaDescription ?? t('meta_description');
$noindex = $noindex ?? false;

$seoBase = seo_base();
// Pages may override: $canonicalUrl (full URL), $ogImage (absolute image URL),
// $twitterCard, $jsonLd (pre-encoded JSON replacing the WebApplication blob).
$seoPath = strtok($_SERVER['REQUEST_URI'], '?');
if (in_array($seoPath, ['/index.php', '/tr', '/tr/index.php'], true)) {
    // one canonical per language for the landing page
    $seoPath = str_starts_with($seoPath, '/tr') ? '/tr/' : '/';
}
$seoCanonical = $canonicalUrl ?? $seoBase . $seoPath;
// hreflang alternates for the public pages (each language has its own URL).
$seoBarePath = bare_path(substr($seoCanonical, strlen($seoBase)) ?: '/');
$seoAlternates = !$noindex && is_public_path($seoBarePath);
$seoImage = $ogImage ?? $seoBase . '/assets/icons/og.png';
$seoTwitterCard = $twitterCard ?? 'summary_large_image';
$seoLocale = current_lang() === 'tr' ? 'tr_TR' : 'en_US';
$seoJsonLd = $jsonLd ?? json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'WebApplication',
    'name' => app_name(),
    'url' => $seoBase . '/',
    'applicationCategory' => 'EntertainmentApplication',
    'operatingSystem' => 'Web',
    'description' => $metaDescription,
    'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
?>
<!DOCTYPE html>
<html lang="<?= current_lang() ?>" data-theme="<?= app_theme() ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
    <?php if (is_admin()): ?><meta name="is-admin" content="1"><?php endif; ?>
    <title><?= htmlspecialchars($fullTitle) ?></title>
    <meta name="description" content="<?= htmlspecialchars($metaDescription) ?>">
    <meta name="robots" content="<?= $noindex ? 'noindex, follow' : 'index, follow' ?>">
    <link rel="canonical" href="<?= htmlspecialchars($seoCanonical) ?>">
    <?php if ($seoAlternates): ?>
    <link rel="alternate" hreflang="en" href="<?= htmlspecialchars($seoBase . $seoBarePath) ?>">
    <link rel="alternate" hreflang="tr" href="<?= htmlspecialchars($seoBase . lang_path($seoBarePath, 'tr')) ?>">
    <link rel="alternate" hreflang="x-default" href="<?= htmlspecialchars($seoBase . $seoBarePath) ?>">
    <?php endif; ?>

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= app_name() ?>">
    <meta property="og:title" content="<?= htmlspecialchars($fullTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($metaDescription) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($seoCanonical) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($seoImage) ?>">
    <meta property="og:locale" content="<?= $seoLocale ?>">
    <meta name="twitter:card" content="<?= htmlspecialchars($seoTwitterCard) ?>">
    <meta name="twitter:title" content="<?= htmlspecialchars($fullTitle) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($metaDescription) ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($seoImage) ?>">

    <link rel="stylesheet" href="/assets/css/style.css?v=<?= ASSET_VERSION ?>">
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="icon" type="image/svg+xml" href="/assets/icons/favicon.svg">
    <link rel="manifest" href="/manifest.php">
    <meta name="theme-color" content="<?= app_theme() === 'light' ? '#ffffff' : '#1a1f2a' ?>">
    <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= app_name() ?>">
    <script type="application/ld+json"><?= $seoJsonLd ?></script>
    <script>window.I18N = <?= i18n_js() ?>;</script>
</head>
<body>
<header class="site-header">
    <a class="brand" href="<?= is_logged_in() ? '/' : lang_path('/') ?>" title="<?= app_name() ?>" aria-label="<?= app_name() ?> home">
        <svg class="brand-logo" viewBox="0 0 108 48" role="img" aria-label="<?= app_name() ?>">
            <defs>
                <linearGradient id="tvt-check" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stop-color="#5aa8ff"/>
                    <stop offset="1" stop-color="#2563eb"/>
                </linearGradient>
            </defs>
            <g fill="none" stroke-width="9" stroke-linecap="round" stroke-linejoin="round">
                <g stroke="currentColor">
                    <path d="M6 8H34M20 8V40"/>
                    <path d="M74 8H102M88 8V40"/>
                </g>
                <path d="M40 18L52 40L72 6" stroke="url(#tvt-check)"/>
            </g>
        </svg>
    </a>
    <button class="nav-toggle" aria-label="<?= t('menu') ?>" aria-expanded="false" aria-controls="site-nav">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round">
            <line x1="4" y1="7" x2="20" y2="7"/>
            <line x1="4" y1="12" x2="20" y2="12"/>
            <line x1="4" y1="17" x2="20" y2="17"/>
        </svg>
    </button>
    <nav id="site-nav">
        <?php if (is_logged_in()): ?>
            <span class="nav-user"><?= t('welcome') ?> <a href="/change-password.php" title="<?= t('change_password') ?>"><?= htmlspecialchars(current_display_name()) ?></a></span>
            <a href="/">📅 <?= t('nav_calendar') ?></a>
            <a href="/upcoming">🔜 <?= t('nav_upcoming') ?></a>
            <a href="/myshows.php">🎬 <?= t('nav_myshows') ?></a>
            <a href="/browse">📺 <?= t('nav_browse') ?></a>
            <form class="nav-search" action="/search.php" method="get" role="search">
                <input type="search" name="q" placeholder="🔍 <?= t('nav_search') ?>"
                       aria-label="<?= t('nav_search') ?>">
            </form>
            <a class="nav-search-link" href="/search.php">🔍 <?= t('nav_search') ?></a>
            <span class="nav-sep">|</span>
            <form method="post" action="/logout.php" class="logout-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <button type="submit" title="<?= t('logout') ?>" class="logout-link">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-label="<?= t('logout') ?>">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                        <polyline points="16 17 21 12 16 7"/>
                        <line x1="21" y1="12" x2="9" y2="12"/>
                    </svg>
                    <span class="logout-label"><?= t('logout') ?></span>
                </button>
            </form>
        <?php else: ?>
            <a href="<?= lang_path('/browse') ?>">📺 <?= t('nav_browse') ?></a>
            <a href="<?= lang_path('/upcoming') ?>">📅 <?= t('nav_upcoming') ?></a>
            <span class="nav-sep">|</span>
            <a href="/login.php"><?= t('login') ?></a>
            <a href="/register.php"><?= t('register') ?></a>
        <?php endif; ?>
    </nav>
</header>
<main class="container">
