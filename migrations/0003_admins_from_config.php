<?php
// The admins used to be the emails listed in ADMIN_EMAILS (config.php). Their
// accounts get the admin role, after which the constant is unused and can be
// deleted from config.php. A config without it changes nothing: the first
// admin is then set with scripts/admin.php.
return function (PDO $pdo): void {
    $emails = defined('ADMIN_EMAILS')
        ? array_filter(array_map('trim', explode(',', ADMIN_EMAILS)), 'strlen')
        : [];
    $set = $pdo->prepare("UPDATE users SET role = 'admin' WHERE email = ?");
    foreach ($emails as $email) {
        $set->execute([$email]);
        // The old check made whoever registered with a listed email an admin;
        // the role exists only on an account, so say when there is none yet.
        echo $set->rowCount()
            ? "$email is an admin, "
            : "no account for $email (once it exists: php scripts/admin.php add $email), ";
    }
};
