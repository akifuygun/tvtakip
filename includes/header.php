<?php
require_once __DIR__ . '/auth.php';
$pageTitle = $pageTitle ?? 'TVTrack';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
    <?php if (is_admin()): ?><meta name="is-admin" content="1"><?php endif; ?>
    <title><?= htmlspecialchars($pageTitle) ?> — TVTrack</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#1a1f2a">
    <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="TVTrack">
</head>
<body>
<header class="site-header">
    <a class="brand" href="index.php" aria-label="TVTrack home">
        <svg class="brand-logo" viewBox="0 0 108 48" role="img" aria-label="TVTrack">
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
    <button class="nav-toggle" aria-label="Menu" aria-expanded="false" aria-controls="site-nav">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round">
            <line x1="4" y1="7" x2="20" y2="7"/>
            <line x1="4" y1="12" x2="20" y2="12"/>
            <line x1="4" y1="17" x2="20" y2="17"/>
        </svg>
    </button>
    <nav id="site-nav">
        <?php if (is_logged_in()): ?>
            <span class="nav-user">Welcome <?= htmlspecialchars(current_display_name()) ?></span>
            <a href="index.php">📅 Calendar</a>
            <a href="myshows.php">🎬 My Shows</a>
            <a href="search.php">🔍 Search</a>
            <a href="change-password.php">🔑 Password</a>
            <span class="nav-sep">|</span>
            <form method="post" action="logout.php" class="logout-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <button type="submit" title="Log out" class="logout-link">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-label="Log out">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                        <polyline points="16 17 21 12 16 7"/>
                        <line x1="21" y1="12" x2="9" y2="12"/>
                    </svg>
                    <span class="logout-label">Logout</span>
                </button>
            </form>
        <?php else: ?>
            <a href="login.php">Log in</a>
            <a href="register.php">Register</a>
        <?php endif; ?>
    </nav>
</header>
<main class="container">
