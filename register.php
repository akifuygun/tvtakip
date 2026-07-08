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
    }
    $displayName = trim($_POST['display_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (mb_strlen($displayName) < 2 || mb_strlen($displayName) > 100) {
        $errors[] = 'Please enter your name (2–100 characters).';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if (!$errors) {
        $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = 'That email is already registered.';
        } else {
            $stmt = db()->prepare('INSERT INTO users (email, display_name, password_hash) VALUES (?, ?, ?)');
            $stmt->execute([$email, $displayName, password_hash($password, PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) db()->lastInsertId();
            $_SESSION['display_name'] = $displayName;
            header('Location: index.php');
            exit;
        }
    }
}

$pageTitle = 'Register';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-card">
    <h1>Create an account</h1>
    <?php foreach ($errors as $error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endforeach; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <label>Name
            <input type="text" name="display_name" required minlength="2" maxlength="100"
                   placeholder="First and/or last name"
                   value="<?= htmlspecialchars($_POST['display_name'] ?? '') ?>">
        </label>
        <label>Email
            <input type="email" name="email" required
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </label>
        <label>Password
            <input type="password" name="password" required minlength="8">
        </label>
        <button type="submit" class="button">Register</button>
    </form>
    <p>Already have an account? <a href="login.php">Log in</a></p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
