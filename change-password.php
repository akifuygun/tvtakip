<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = t('session_expired');
    } else {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([current_user_id()]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($current, $user['password_hash'])) {
            $errors[] = t('err_current_wrong');
        } elseif (strlen($new) < 8) {
            $errors[] = t('err_new_short');
        } elseif ($new !== $confirm) {
            $errors[] = t('err_no_match');
        } else {
            $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($new, PASSWORD_DEFAULT), current_user_id()]);
            session_regenerate_id(true);
            // Revoke every remember-me token so old or stolen cookies die on a
            // password change (the canonical compromise response). Best-effort.
            try {
                db()->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([current_user_id()]);
            } catch (Throwable $e) {
                // remember_tokens may be absent on older installs — ignore
            }
            $success = true;
        }
    }
}

$pageTitle = t('cp_title');
$noindex = true;
require __DIR__ . '/includes/header.php';
?>
<div class="auth-card">
    <h1><?= t('cp_title') ?></h1>
    <?php foreach ($errors as $error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endforeach; ?>
    <?php if ($success): ?>
        <p class="success"><?= t('cp_success') ?></p>
    <?php endif; ?>
    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <label><?= t('current_password') ?>
            <input type="password" name="current_password" required>
        </label>
        <label><?= t('new_password') ?>
            <input type="password" name="new_password" required minlength="8">
        </label>
        <label><?= t('confirm_new_password') ?>
            <input type="password" name="confirm_password" required minlength="8">
        </label>
        <button type="submit" class="button"><?= t('cp_title') ?></button>
    </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
