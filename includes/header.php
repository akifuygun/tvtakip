<?php
require_once __DIR__ . '/auth.php';
$pageTitle = $pageTitle ?? 'TVTakip';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
    <meta name="tmdb-key" content="<?= htmlspecialchars(TMDB_API_KEY) ?>">
    <title><?= htmlspecialchars($pageTitle) ?> — TVTakip</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<header class="site-header">
    <a class="brand" href="index.php">📺 TVTakip</a>
    <nav>
        <?php if (is_logged_in()): ?>
            <span class="nav-user">Welcome <?= htmlspecialchars(current_display_name()) ?></span>
            <a href="index.php">📅 Calendar</a>
            <a href="myshows.php">🎬 My Shows</a>
            <a href="search.php">🔍 Search</a>
            <span class="nav-sep">|</span>
            <a href="logout.php" title="Log out" class="logout-link">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-label="Log out">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <polyline points="16 17 21 12 16 7"/>
                    <line x1="21" y1="12" x2="9" y2="12"/>
                </svg>
            </a>
        <?php else: ?>
            <a href="login.php">Log in</a>
            <a href="register.php">Register</a>
        <?php endif; ?>
    </nav>
</header>
<main class="container">
