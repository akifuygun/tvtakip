<?php
// Mail from the site to its users, handed to the server's own mail system by
// PHP's mail(). On hostUNO that is Exim, which signs a mail with the domain's
// DKIM key and sends it from an address the domain's SPF record names — so
// config MAIL_FROM must be an address of that domain.
require_once __DIR__ . '/i18n.php';

/**
 * Send a plain-text mail from MAIL_FROM, under the site's name. True once the
 * mail system has taken it, which is not that it arrived. Without MAIL_FROM
 * (a development machine) nothing is sent: the whole mail goes to PHP's error
 * log, the dev server's console.
 */
function send_mail(string $to, string $subject, string $body): bool
{
    $from = defined('MAIL_FROM') ? MAIL_FROM : '';
    if ($from === '') {
        error_log("[tvtrack] Not sent (config.php has no MAIL_FROM) — To: $to, Subject: $subject\n$body");
        return true;
    }
    $headers = [
        'From: ' . app_name() . " <$from>",
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        // A line of any length, holding any byte, arrives as it was.
        'Content-Transfer-Encoding: quoted-printable',
        // Not a person's mail: no out-of-office replies to it (RFC 3834).
        'Auto-Submitted: auto-generated',
    ];
    return mail(
        $to,
        mb_encode_mimeheader($subject, 'UTF-8', 'Q'),
        quoted_printable_encode(str_replace("\n", "\r\n", $body)),
        implode("\r\n", $headers)
    );
}
