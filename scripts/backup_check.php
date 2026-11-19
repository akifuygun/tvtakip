<?php
// Checks the live database's backups and mails when one is missing or old.
// Run it on the server; cron does, every night half an hour after the nightly
// dump (README.md, "Backups and restoring").
//
// Usage: php scripts/backup_check.php [<address>]
//        prints what it finds; with an address, a problem is mailed there
//
// Two things are looked at, both in ~/dbdumps:
//   - the nightly dump: the newest tvtakip-YYYYMMDD.sql.gz has to be last
//     night's and a whole dump. Mailed every night it isn't.
//   - the copy off the server: scripts/backup_pull.php, run daily on the
//     owner's machine, rewrites off-site.txt at the end of each run that copied
//     everything. OFFSITE_MAX_DAYS after the last one the dumps have been on
//     this server alone for too long: mailed that night, then once a week —
//     that machine may simply be switched off for a while.
// Exits 1 on a problem, mailed or not.

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

// The same slack as in backup_pull.php: a day plus less than the half hour
// cron leaves between the dump and this check, so a skipped night shows at once.
const NIGHTLY_MAX_AGE = 24 * 3600 + 20 * 60;
const OFFSITE_MAX_DAYS = 4;

/**
 * Whether a .sql.gz is a whole dump: mysqldump's last line says so. One that
 * failed half-way, or a file cut short, ends elsewhere.
 */
function dump_complete(string $path): bool
{
    $tail = '';
    if ($gz = @gzopen($path, 'rb')) {
        while (!gzeof($gz) && ($chunk = gzread($gz, 1 << 20)) !== false && $chunk !== '') {
            $tail = substr($tail . $chunk, -200);
        }
        gzclose($gz);
    }
    return preg_match('/\n-- Dump completed on [^\n]+\n$/', $tail) === 1;
}

$address = $argv[1] ?? null;
if ($argc > 2 || ($address !== null && !filter_var($address, FILTER_VALIDATE_EMAIL))) {
    fwrite(STDERR, "Usage: php scripts/backup_check.php [<address>]\n");
    exit(1);
}
$dir = getenv('HOME') . '/dbdumps';
$fine = [];
$problems = [];  // [what is wrong, in a few words for the subject => the whole of it]
$mail = false;

$nightly = glob("$dir/tvtakip-" . str_repeat('[0-9]', 8) . '.sql.gz');
$newest = end($nightly);  // glob sorts by name, and the name is the date
if ($newest === false) {
    $problems['no nightly dump'] = "There is no nightly dump in $dir.";
    $mail = true;
} elseif (time() - filemtime($newest) > NIGHTLY_MAX_AGE) {
    $problems['no nightly dump'] = 'No nightly dump was made: the newest, ' . basename($newest) . ', is '
        . round((time() - filemtime($newest)) / 3600) . ' hours old. Is the cron job still there (crontab -l)?';
    $mail = true;
} elseif (!dump_complete($newest)) {
    $problems['the nightly dump failed'] = 'The nightly dump ' . basename($newest) . ' (' . filesize($newest)
        . ' bytes) is not a whole dump: mysqldump failed. The newest whole one is older.';
    $mail = true;
} else {
    $fine[] = 'nightly dump ' . basename($newest) . ' is whole (' . number_format(filesize($newest) / 1048576, 1) . ' MB)';
}

$note = "$dir/off-site.txt";
$days = is_file($note) ? intdiv(time() - filemtime($note), 86400) : null;
if ($days === null) {
    $problems['no copy off the server'] = 'The dumps were never copied off this server: there is no off-site.txt beside them.';
    $mail = true;
} elseif ($days >= OFFSITE_MAX_DAYS) {
    $problems["no copy off the server for $days days"] = "The last complete copy off this server was made $days days ago,"
        . ' so the newer dumps exist here alone. scripts/backup_pull.php makes it, daily, on the machine it is scheduled on.';
    $mail = $mail || ($days - OFFSITE_MAX_DAYS) % 7 === 0;
} else {
    $fine[] = 'last copy off the server ' . ($days === 0 ? 'less than a day ago' : "$days day" . ($days === 1 ? '' : 's') . ' ago');
}

$stamp = gmdate('Y-m-d H:i\Z');
if (!$problems) {
    echo "$stamp ok: " . implode(', ', $fine) . "\n";
    exit(0);
}
foreach ($problems as $text) {
    echo "$stamp PROBLEM: $text\n";
}
if ($address !== null && $mail) {
    $body = 'The backup check on ' . php_uname('n') . " found:\n\n- " . implode("\n- ", $problems)
        . "\n\nWhat to do about it: README.md, \"Backups and restoring\".\n";
    $sent = mail($address, 'TVTrack backups: ' . implode(', ', array_keys($problems)), $body, "From: TVTrack backups <$address>");
    echo "$stamp " . ($sent ? "mailed to $address" : "COULD NOT MAIL $address") . "\n";
}
exit(1);
