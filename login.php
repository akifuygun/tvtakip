<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Session expired, please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $stmt = db()->prepare('SELECT id, username, password_hash FROM users WHERE username = ? OR email = ?');
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['username'] = $user['username'];
            header('Location: index.php');
            exit;
        }
        $errors[] = 'Invalid username or password.';
    }
}

$pageTitle = 'Log in';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-card">
    <h1>Log in</h1>
    <?php foreach ($errors as $error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endforeach; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <label>Username or email
            <input type="text" name="username" required
                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
        </label>
        <label>Password
            <input type="password" name="password" required>
        </label>
        <button type="submit">Log in</button>
    </form>
    <p>No account yet? <a href="register.php">Register</a></p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
