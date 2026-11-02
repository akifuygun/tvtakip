<?php
require_once __DIR__ . '/includes/auth.php';

// State-changing, so POST + CSRF only — a cross-site GET can't log anyone out.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? null)) {
    header('Location: index.php');
    exit;
}

$target = '/';
// "Log out on all devices" (change-password page): every session and
// remember-me token of the account goes, this browser's included.
if (!empty($_POST['all']) && is_logged_in()) {
    $target = '/login.php?out=' . (logout_everywhere(current_user_id()) ? 'all' : 'err');
}

end_session();

header('Location: ' . $target);
exit;
