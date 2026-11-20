<?php
// Mails what is new in the site's error logs. Run it on the server; cron does,
// every morning (README.md, "Monitoring").
//
// Usage: php scripts/error_report.php [<address>]
//        prints what the logs gained since the last report; with an address
//        that is mailed there instead, and counts as reported once the mail
//        was handed over
//
// Two logs are read:
//   - PHP's (error_log_file(), includes/errors.php): the errors, warnings and
//     notices of the pages, the API and the command-line scripts, and the
//     [tvtrack] lines the code writes itself — an uncaught exception, a failed
//     import of the refresh worker, a /health that found the database down;
//   - the web server's for this domain, less the lines that report the site
//     working as meant (EXPECTED_LINES).
// Lines saying the same thing are one entry with a count. How far each log was
// read is kept beside PHP's log (error-report.json) together with the file's
// inode, so a log rotated since ("<log>.1") is first read to its end there.
// PHP's log is rotated here, once it is past ROTATE_BYTES.
// Exits 1 when there was something new, mailed or not.

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../includes/errors.php';

// Lines of the web server's log that report the site working as meant.
const EXPECTED_LINES = [
    '/ AH01276: /',  // a directory's file list, refused (Options -Indexes): each deploy check asks for two
];
const MAX_ENTRIES = 100;      // different entries listed per log; further ones are only counted
const MAX_ENTRY_LINES = 25;   // lines of one entry (a stack trace)
const MAX_LINE_BYTES = 2000;
const ROTATE_BYTES = 1 << 20;

/**
 * A file's whole lines from a byte offset on; the generator returns the offset
 * after the last of them. A line still being written (no line end yet) is
 * left for the next time.
 */
function lines_from(string $file, int $offset): Generator
{
    $handle = @fopen($file, 'rb');
    if ($handle === false) {
        throw new RuntimeException("$file can't be read.");
    }
    fseek($handle, $offset);
    while (($line = fgets($handle)) !== false && str_ends_with($line, "\n")) {
        $offset += strlen($line);
        yield rtrim($line, "\r\n");
    }
    fclose($handle);
    return $offset;
}

/**
 * The lines a log gained since the last report. The generator returns how far
 * the log is read now, ['inode' => …, 'offset' => …] — null when there is no
 * such file. $seen is that value as the last report left it; null for a log
 * never read, all of which is new.
 */
function new_lines(string $log, ?array $seen): Generator
{
    clearstatcache();
    $offset = 0;
    if ($seen !== null && is_file($log) && fileinode($log) === $seen['inode']) {
        // The same file. Shorter than it was read: emptied, so new from its start.
        $offset = filesize($log) >= $seen['offset'] ? $seen['offset'] : 0;
    } elseif ($seen !== null && is_file("$log.1") && fileinode("$log.1") === $seen['inode']) {
        // Rotated since: what the old file gained after the last report goes first.
        yield from lines_from("$log.1", $seen['offset']);
    }
    if (!is_file($log)) {
        return null;
    }
    $inode = fileinode($log);
    return ['inode' => $inode, 'offset' => yield from lines_from($log, $offset)];
}

/**
 * [time, text] of a line that starts a log entry — PHP's "[20-Nov-2026
 * 08:41:55 Europe/Istanbul] …", the web server's "[Fri Nov 20 08:33:15.293024
 * 2026] …" — and null for any other line: the next line of a stack trace. The
 * text is without what tells two reports of the same thing apart in the web
 * server's log, the process and the client.
 */
function entry_start(string $line): ?array
{
    if (!preg_match('/^\[(\d{2}-[A-Za-z]{3}-\d{4} [^\]]+|[A-Za-z]{3} [A-Za-z]{3} [ \d]\d [^\]]+)\] (.*)$/s', $line, $m)) {
        return null;
    }
    return [$m[1], preg_replace('/\[(?:pid|client|remote) [^\]]*\] /', '', $m[2])];
}

/**
 * A log's new lines as entries, by what they say:
 *   'entries'  text => ['count', 'first' and 'last' (the times), 'more' (the
 *              further lines of the first one)] — MAX_ENTRIES at most
 *   'total'    all new entries, 'unlisted' those beyond MAX_ENTRIES
 *   'seen'     how far the log is read now (new_lines())
 * or ['problem' => why the log could not be read].
 */
function collect(string $log, ?array $seen): array
{
    $entries = [];
    $total = $unlisted = 0;
    $current = null;  // the entry further lines belong to; false = one that isn't listed
    $lines = new_lines($log, $seen);
    try {
        foreach ($lines as $line) {
            [$time, $text] = entry_start($line) ?? [null, $line];
            if ($time === null && $current !== null) {
                if ($current !== false && $entries[$current]['count'] === 1 && count($entries[$current]['more']) < MAX_ENTRY_LINES) {
                    $entries[$current]['more'][] = $line;
                }
                continue;
            }
            $current = false;
            foreach (EXPECTED_LINES as $pattern) {
                if (preg_match($pattern, $line)) {
                    continue 2;
                }
            }
            $total++;
            if (isset($entries[$text])) {
                $entries[$text]['count']++;
                $entries[$text]['last'] = $time;
            } elseif (count($entries) < MAX_ENTRIES) {
                $entries[$text] = ['count' => 1, 'first' => $time, 'last' => $time, 'more' => []];
            } else {
                $unlisted++;
                continue;
            }
            $current = $text;
        }
    } catch (RuntimeException $e) {
        return ['problem' => $e->getMessage()];
    }
    return ['entries' => $entries, 'total' => $total, 'unlisted' => $unlisted, 'seen' => $lines->getReturn()];
}

/** A line of the report, cut where a log line is longer than a mail should carry. */
function shortened(string $line): string
{
    if (strlen($line) <= MAX_LINE_BYTES) {
        return $line;
    }
    return (function_exists('mb_strcut') ? mb_strcut($line, 0, MAX_LINE_BYTES, 'UTF-8') : substr($line, 0, MAX_LINE_BYTES)) . ' […]';
}

/** "3 errors" / "1 error". */
function errors(int $n): string
{
    return $n . ' error' . ($n === 1 ? '' : 's');
}

/** What collect() found in one log, as the report's text. */
function describe_log(string $name, string $log, array $found): string
{
    $out = ucfirst($name) . ", $log\n";
    if (isset($found['problem'])) {
        return $out . $found['problem'] . "\n";
    }
    $out .= errors($found['total']) . ', ' . count($found['entries']) . ($found['unlisted'] ? '+' : '') . " different:\n";
    foreach ($found['entries'] as $text => $entry) {
        $out .= sprintf("\n%5d x  %s\n", $entry['count'], shortened((string) $text));
        foreach ($entry['more'] as $line) {
            $out .= '         ' . shortened($line) . "\n";
        }
        // A line that came without a time has none to show.
        if ($entry['first'] !== null) {
            $out .= '         ' . ($entry['count'] === 1 ? $entry['first'] : "first {$entry['first']}, last {$entry['last']}") . "\n";
        }
    }
    if ($found['unlisted']) {
        $out .= "\n… and " . errors($found['unlisted']) . ' that say something else again: only the first ' . MAX_ENTRIES . " different ones are listed.\n";
    }
    return $out;
}

$address = $argv[1] ?? null;
if ($argc > 2 || ($address !== null && !filter_var($address, FILTER_VALIDATE_EMAIL))) {
    fwrite(STDERR, "Usage: php scripts/error_report.php [<address>]\n");
    exit(1);
}
$phpLog = error_log_file();
if ($phpLog === '') {
    fwrite(STDERR, "config.php names no SESSION_DIR, so PHP's errors are not kept in a file here.\n");
    exit(1);
}
// hostUNO keeps a domain's logs beside the directory it serves, and the
// checkout is beside that too: ~/domains/<domain>/{app, logs, public_html}.
$serverLog = dirname(__DIR__, 2) . '/logs/error.log';
$stateFile = dirname($phpLog) . '/error-report.json';
$state = is_file($stateFile) ? (json_decode((string) file_get_contents($stateFile), true) ?: []) : [];

$stamp = gmdate('Y-m-d H:i\Z');
$sections = $counts = [];
foreach (["PHP's log" => $phpLog, "the web server's log" => $serverLog] as $name => $log) {
    $found = collect($log, $state['logs'][$log] ?? null);
    if (isset($found['problem'])) {
        $counts[] = "$name can't be read";
        $sections[] = describe_log($name, $log, $found);
        continue;
    }
    // Nothing to tell does not mean nothing was read: expected lines were.
    $state['logs'][$log] = $found['seen'];
    if ($found['total']) {
        $counts[] = "{$found['total']} in $name";
        $sections[] = describe_log($name, $log, $found);
    }
}

$sent = true;
if (!$sections) {
    echo "$stamp ok: nothing new\n";
} else {
    $since = isset($state['at']) ? 'since ' . gmdate('Y-m-d H:i', $state['at']) . ' UTC' : 'so far';
    $body = 'New in the error logs of ' . php_uname('n') . " $since:\n\n" . implode("\n", $sections)
        . "\nWhat the logs are and how to read them: README.md, \"Monitoring\".\n";
    if ($address === null) {
        echo $body;
    } else {
        // Quoted-printable: a log line may be longer than a mail's lines may be, and hold any byte.
        $sent = mail(
            $address,
            'TVTrack errors: ' . implode(', ', $counts),
            quoted_printable_encode(str_replace("\n", "\r\n", $body)),
            "From: TVTrack errors <$address>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable"
        );
        echo "$stamp " . implode(', ', $counts) . ' — ' . ($sent ? "mailed to $address" : "COULD NOT MAIL $address") . "\n";
    }
}

// Only a report that went out moves on: what was merely printed, or could not
// be mailed, is new again the next time.
if ($address !== null && $sent) {
    $state['at'] = time();
    file_put_contents("$stateFile.tmp", json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    rename("$stateFile.tmp", $stateFile);
    // The old file keeps its inode under its new name, so what is still
    // written to it is found there by the next run.
    clearstatcache();
    if (is_file($phpLog) && filesize($phpLog) > ROTATE_BYTES) {
        rename($phpLog, "$phpLog.1");
    }
}
exit($sections ? 1 : 0);
