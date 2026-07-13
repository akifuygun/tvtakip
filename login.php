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
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        $stmt = db()->prepare('SELECT id, display_name, password_hash FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            login_user((int) $user['id'], $user['display_name'], $email);
            if (!empty($_POST['remember'])) {
                remember_user((int) $user['id']);
            }
            header('Location: index.php');
            exit;
        }
        $errors[] = t('invalid_login');
    }
}

$pageTitle = t('login_title');
$noindex = true;
require __DIR__ . '/includes/header.php';
?>
<div class="auth-card">
    <h1><?= t('login_title') ?></h1>
    <?php foreach ($errors as $error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endforeach; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <label><?= t('email') ?>
            <input type="email" name="email" required
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </label>
        <label><?= t('password') ?>
            <input type="password" name="password" required>
        </label>
        <label class="remember">
            <input type="checkbox" name="remember" value="1" checked> <?= t('remember_me') ?>
        </label>
        <button type="submit" class="button"><?= t('login_title') ?></button>
    </form>
    <p><?= t('no_account') ?> <a href="/register.php"><?= t('register') ?></a></p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
