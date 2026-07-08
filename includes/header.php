<?php
require_once __DIR__ . '/auth.php';
$pageTitle = $pageTitle ?? 'tvtakip';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
    <meta name="tmdb-key" content="<?= htmlspecialchars(TMDB_API_KEY) ?>">
    <title><?= htmlspecialchars($pageTitle) ?> — tvtakip</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<header class="site-header">
    <a class="brand" href="index.php">📺 tvtakip</a>
    <nav>
        <?php if (is_logged_in()): ?>
            <a href="index.php">My Shows</a>
            <a href="search.php">Search</a>
            <span class="nav-user"><?= htmlspecialchars(current_username()) ?></span>
            <a href="logout.php">Log out</a>
        <?php else: ?>
            <a href="login.php">Log in</a>
            <a href="register.php">Register</a>
        <?php endif; ?>
    </nav>
</header>
<main class="container">
