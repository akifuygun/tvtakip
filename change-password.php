<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Session expired, please try again.';
    } else {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([current_user_id()]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($current, $user['password_hash'])) {
            $errors[] = 'Current password is incorrect.';
        } elseif (strlen($new) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $errors[] = 'New passwords do not match.';
        } else {
            $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($new, PASSWORD_DEFAULT), current_user_id()]);
            session_regenerate_id(true);
            $success = true;
        }
    }
}

$pageTitle = 'Change Password';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-card">
    <h1>Change password</h1>
    <?php foreach ($errors as $error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endforeach; ?>
    <?php if ($success): ?>
        <p class="success">Your password has been changed.</p>
    <?php endif; ?>
    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <label>Current password
            <input type="password" name="current_password" required>
        </label>
        <label>New password
            <input type="password" name="new_password" required minlength="8">
        </label>
        <label>Confirm new password
            <input type="password" name="confirm_password" required minlength="8">
        </label>
        <button type="submit" class="button">Change password</button>
    </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
