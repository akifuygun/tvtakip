<?php
require_once __DIR__ . '/../includes/password_reset.php';

// Open to a logged-in visitor too: remember-me can keep someone logged in who
// no longer knows the password that change-password.php asks for.
$errors = [];
$status = 200;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = t('session_expired');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = t('err_email');
    } elseif (($mail = password_reset_request($email)) === null) {
        $errors[] = t('reset_too_many');
        $status = 429;
    } else {
        // The same answer whether or not the address has an account, and it
        // is sent before the mail: how long it takes tells nothing either.
        $_SESSION['reset_sent_to'] = $email;
        header('Location: /forgot-password.php?sent=1', true, 303);
        if ($mail) {
            session_write_close();
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            if (!send_password_reset_mail($mail['user'], $mail['token'])) {
                error_log('[tvtrack] The mail system did not take the password reset mail for user #' . $mail['user']['id']);
            }
        }
        exit;
    }
}
// The address it went to, after the redirect above.
$sentTo = isset($_GET['sent']) ? ($_SESSION['reset_sent_to'] ?? null) : null;

http_response_code($status);
$pageTitle = t('forgot_title');
$noindex = true;
require __DIR__ . '/../includes/header.php';
?>
<div class="auth-card">
    <h1><?= t('forgot_title') ?></h1>
    <?php if ($sentTo !== null): ?>
        <p class="success"><?= htmlspecialchars(t('reset_sent', $sentTo, RESET_LIFETIME)) ?></p>
        <p><a href="/login.php"><?= t('login') ?></a> · <a href="/forgot-password.php"><?= t('reset_try_other') ?></a></p>
    <?php else: ?>
        <?php foreach ($errors as $error): ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php endforeach; ?>
        <p class="muted"><?= t('forgot_intro') ?></p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <label><?= t('email') ?>
                <input type="email" name="email" required autocomplete="username"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </label>
            <button type="submit" class="button"><?= t('forgot_send') ?></button>
        </form>
        <p><a href="/login.php"><?= t('login') ?></a></p>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
