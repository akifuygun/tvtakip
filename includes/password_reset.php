<?php
// "Forgot password": a link mailed to an account's address sets a new password
// (forgot-password.php asks for it, reset-password.php takes it). The link
// carries a random token; the database keeps only its sha256
// (password_resets). Whether an address has an account is never told.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mail.php';

const RESET_LIFETIME = 60;          // minutes a mailed link works
const RESET_MAX_PER_ACCOUNT = 3;    // links mailed to one account in an hour
const RESET_MAX_PER_CLIENT = 10;    // requests from one client address in an hour, whatever address they named
const RESET_KEEP_HOURS = 24;        // how long a request is kept, for those limits

/** The client a rate limit counts: its IP address — an IPv6 one as its /64
 *  network, which a single connection is usually given whole. */
function reset_client(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $bin = @inet_pton($ip);
    if ($bin !== false && strlen($bin) === 16) {
        return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
    return $ip;
}

/**
 * Note a request to reset the password of the account with this address.
 * Null when the client has made RESET_MAX_PER_CLIENT requests in the last
 * hour (nothing is noted then). Otherwise the mail to send, ['user' => [id,
 * email, display_name], 'token' => …], or [] when there is none to send: no
 * account has the address, or it was mailed RESET_MAX_PER_ACCOUNT links in
 * the last hour — the visitor is told neither.
 */
function password_reset_request(string $email): ?array
{
    $pdo = db();
    $pdo->exec('DELETE FROM password_resets WHERE created_at < NOW() - INTERVAL ' . RESET_KEEP_HOURS . ' HOUR');
    $client = reset_client();
    $count = $pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE ip = ? AND created_at > NOW() - INTERVAL 1 HOUR');
    $count->execute([$client]);
    if ((int) $count->fetchColumn() >= RESET_MAX_PER_CLIENT) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, email, display_name FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch() ?: null;
    if ($user) {
        $count = $pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 HOUR');
        $count->execute([$user['id']]);
        if ((int) $count->fetchColumn() >= RESET_MAX_PER_ACCOUNT) {
            $user = null;
        }
    }
    $token = $user ? bin2hex(random_bytes(32)) : null;
    $pdo->prepare('INSERT INTO password_resets (user_id, ip, token_hash) VALUES (?, ?, ?)')
        ->execute([$user['id'] ?? null, $client, $token === null ? null : hash('sha256', $token)]);
    return $token === null ? [] : ['user' => $user, 'token' => $token];
}

/** Mail the reset link, in the page's language. True once the mail system took it. */
function send_password_reset_mail(array $user, string $token): bool
{
    // ?lang= opens the page in the language the link was asked for in
    // (i18n.php: it sets the cookie and drops itself from the address).
    $link = seo_base() . '/reset-password.php?' . http_build_query(['token' => $token, 'lang' => current_lang()]);
    $body = implode("\n\n", [
        t('reset_mail_hello', $user['display_name']),
        t('reset_mail_intro', app_name(), $user['email'], RESET_LIFETIME),
        $link,
        t('reset_mail_once'),
        t('reset_mail_ignore'),
        '— ' . app_name() . "\n" . seo_base() . '/',
    ]) . "\n";
    return send_mail($user['email'], t('reset_mail_subject', app_name()), $body);
}

/** The account a reset token is still good for, [id, email, display_name], or null. */
function password_reset_user(string $token): ?array
{
    if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT u.id, u.email, u.display_name FROM password_resets r JOIN users u ON u.id = r.user_id
         WHERE r.token_hash = ? AND r.created_at > NOW() - INTERVAL ' . RESET_LIFETIME . ' MINUTE'
    );
    $stmt->execute([hash('sha256', $token)]);
    return $stmt->fetch() ?: null;
}

/** Make every reset link mailed to the account stop working (its password changed). */
function revoke_password_resets(int $userId): void
{
    db()->prepare('UPDATE password_resets SET token_hash = NULL WHERE user_id = ? AND token_hash IS NOT NULL')
        ->execute([$userId]);
}

/**
 * Set a new password with a reset token. All of the account's reset links stop
 * working, and its every session and remember-me token ends (logout_everywhere()).
 * False when the token is not good (anymore) — two requests with the same one
 * can't both get here: the row is locked until the first has cleared it.
 */
function reset_password(string $token, string $password): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT user_id FROM password_resets
             WHERE token_hash = ? AND created_at > NOW() - INTERVAL ' . RESET_LIFETIME . ' MINUTE FOR UPDATE'
        );
        $stmt->execute([preg_match('/^[0-9a-f]{64}$/', $token) === 1 ? hash('sha256', $token) : '']);
        $userId = $stmt->fetchColumn();
        if ($userId === false) {
            $pdo->rollBack();
            return false;
        }
        $userId = (int) $userId;
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
        revoke_password_resets($userId);
        if (!logout_everywhere($userId)) {
            throw new RuntimeException("The sessions of user #$userId could not be ended");
        }
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
