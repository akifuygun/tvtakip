<?php
// The Watch sites' admin-set origins used to be a file api/watch_base.php
// wrote, includes/watch_base.php (`return [label => origin];`, not in git).
// They move into the settings table as "watch_base.<label>". The file is left
// in place: the code that reads it is still the live one while this runs.
// Once the new code is deployed, nothing reads it and it can be deleted.
return function (PDO $pdo): void {
    $file = dirname(__DIR__) . '/includes/watch_base.php';
    $saved = is_file($file) ? include $file : null;
    if (!is_array($saved)) {
        return;
    }
    $set = $pdo->prepare(
        'INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)'
    );
    foreach ($saved as $site => $origin) {
        // What the old reader accepted: "scheme://host/" and nothing more.
        if (is_string($origin) && preg_match('#^https?://[^/]+/$#i', $origin)) {
            $set->execute(["watch_base.$site", $origin]);
            echo "$site = $origin, ";
        } else {
            echo "$site skipped (not an origin), ";
        }
    }
};
