<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = t('session_expired');
    }
    $displayName = trim($_POST['display_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (mb_strlen($displayName) < 2 || mb_strlen($displayName) > 100) {
        $errors[] = t('err_name');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = t('err_email');
    }
    if (strlen($password) < 8) {
        $errors[] = t('err_password');
    }

    if (!$errors) {
        $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = t('err_email_taken');
        } else {
            $stmt = db()->prepare('INSERT INTO users (email, display_name, password_hash) VALUES (?, ?, ?)');
            $stmt->execute([$email, $displayName, password_hash($password, PASSWORD_DEFAULT)]);
            $userId = (int) db()->lastInsertId();
            login_user($userId, $displayName, $email);
            remember_user($userId);
            header('Location: index.php');
            exit;
        }
    }
}

$pageTitle = t('register_title');
require __DIR__ . '/includes/header.php';
?>
<div class="auth-card">
    <h1><?= t('register_title') ?></h1>
    <?php foreach ($errors as $error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endforeach; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <label><?= t('name') ?>
            <input type="text" name="display_name" required minlength="2" maxlength="100"
                   placeholder="<?= t('name_placeholder') ?>"
                   value="<?= htmlspecialchars($_POST['display_name'] ?? '') ?>">
        </label>
        <label><?= t('email') ?>
            <input type="email" name="email" required
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </label>
        <label><?= t('password') ?>
            <input type="password" name="password" required minlength="8">
        </label>
        <button type="submit" class="button"><?= t('register') ?></button>
    </form>
    <p><?= t('have_account') ?> <a href="login.php"><?= t('login') ?></a></p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
