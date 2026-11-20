<?php
require_once __DIR__ . '/../includes/password_reset.php';

// The link in the mail opens this page; only saving the form uses its token,
// so a mail scanner that follows the link changes nothing.
$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$user = password_reset_user($token);
$errors = [];
if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $new = (string) ($_POST['new_password'] ?? '');
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = t('session_expired');
    } elseif (strlen($new) < 8) {
        $errors[] = t('err_new_short');
    } elseif ($new !== ($_POST['confirm_password'] ?? '')) {
        $errors[] = t('err_no_match');
    } elseif (reset_password($token, $new)) {
        // Every login of the account has ended; this browser's ends too,
        // whoever it was logged in as.
        end_session();
        header('Location: /login.php?reset=done', true, 303);
        exit;
    } else {
        $user = null;  // used or expired since the page was opened
    }
}

$pageTitle = t('reset_title');
$noindex = true;
require __DIR__ . '/../includes/header.php';
?>
<div class="auth-card">
    <h1><?= t('reset_title') ?></h1>
    <?php if (!$user): ?>
        <p class="error"><?= htmlspecialchars(t('reset_invalid', RESET_LIFETIME)) ?></p>
        <p><a class="button" href="/forgot-password.php"><?= t('reset_new_link') ?></a></p>
    <?php else: ?>
        <?php foreach ($errors as $error): ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php endforeach; ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            <label><?= t('email') ?>
                <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>"
                       autocomplete="username" readonly>
            </label>
            <label><?= t('new_password') ?>
                <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
            </label>
            <label><?= t('confirm_new_password') ?>
                <input type="password" name="confirm_password" required minlength="8" autocomplete="new-password">
            </label>
            <p class="muted"><?= t('reset_hint') ?></p>
            <button type="submit" class="button"><?= t('reset_submit') ?></button>
        </form>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
