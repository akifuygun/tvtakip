<?php
// Who is an admin: users.role, which only this script changes. Admins get the
// shared-cache controls (posters, a show's primary network) and the Watch
// links. A change applies at the account's next request — no new login.
//
// Usage: php scripts/admin.php                 list the admins
//        php scripts/admin.php add <email>     make that account an admin
//        php scripts/admin.php remove <email>  back to a regular user

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../includes/db.php';

const USAGE = "Usage: php scripts/admin.php [add <email> | remove <email>]\n";

$args = array_slice($argv, 1);
$action = $args[0] ?? 'list';
$roles = ['add' => 'admin', 'remove' => 'user'];
if (!($action === 'list' && count($args) <= 1) && !(isset($roles[$action]) && count($args) === 2)) {
    fwrite(STDERR, USAGE);
    exit(1);
}

try {
    $pdo = db();
    if (isset($roles[$action])) {
        $email = trim($args[1]);
        $find = $pdo->prepare('SELECT id, email, role FROM users WHERE email = ?');
        $find->execute([$email]);
        $user = $find->fetch();
        if (!$user) {
            fwrite(STDERR, "No account with the email $email: it has to register first.\n");
            exit(1);
        }
        $what = $action === 'add' ? 'an admin' : 'a regular user';
        if ($user['role'] === $roles[$action]) {
            echo "{$user['email']} is already $what.\n";
        } else {
            $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$roles[$action], $user['id']]);
            echo "{$user['email']} is now $what.\n";
        }
    }

    $admins = $pdo->query("SELECT id, email, display_name FROM users WHERE role = 'admin' ORDER BY id")->fetchAll();
    echo $admins ? "Admins:\n" : "There is no admin.\n";
    foreach ($admins as $a) {
        printf("  #%d  %s  (%s)\n", $a['id'], $a['email'], $a['display_name']);
    }
} catch (PDOException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
