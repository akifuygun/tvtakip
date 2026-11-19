<?php
// Copies the live site's backups off the server, into a folder on this
// machine. Run it on the development machine; a scheduled task does, once a
// day (README.md, "Backups and restoring").
//
// Usage: php scripts/backup_pull.php [<folder>]
//        (the folder defaults to Backups\hostuno in the user's profile)
//
// What the folder holds afterwards:
//   dbdumps\  every database dump in the server's ~/dbdumps — the nightly ones
//             and those a deploy makes before a migration — each compared with
//             the server's SHA-256. Kept KEEP_DAYS days; the server keeps 14.
//   files\    hostuno-YYYYMMDD.tar.gz, one a day: what exists on the server
//             alone. The live checkout's untracked files (config.php and the
//             like), the CV site, the mail rules, the crontab and the host's
//             settings as its tools list them. Kept as long as the dumps.
//   pull.log  one line per run (times in UTC, as in the file names)
//
// A run has a problem when it could not copy all of that, or when the server's
// newest nightly dump is missing, older than a day or not a whole dump. It
// then says so in a message box and in the log, and exits 1.
//
// The server watches from its side: a run without a problem leaves a note
// there (~/dbdumps/off-site.txt), and scripts/backup_check.php — cron runs it
// every night — mails when that note gets old, or when the nightly dump fails.

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

// hostuno is a Host alias in ~/.ssh/config. BatchMode: never a prompt — a
// scheduled run has nobody to answer one.
const SSH = ['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=20', 'hostuno'];
// From the SSH user's home: the live checkout, and the CV site's web root.
const LIVE_DIR = 'domains/tvtakip.akifuygun.com/app';
const CV_DIR = 'domains/akifuygun.com/public_html';
const KEEP_DAYS = 90;
// The nightly dump is a day apart from the one before; older than that plus
// some slack, a night was skipped.
const NIGHTLY_MAX_AGE = 24 * 3600 + 20 * 60;

// The server's dumps, one "dump <name> <bytes> <modified> <sha256>" line each,
// after the server's clock.
const REMOTE_LIST = <<<'BASH'
    set -euo pipefail
    cd "$HOME/dbdumps"
    echo "now $(date +%s)"
    for f in tvtakip-*.sql.gz; do
        if [ -f "$f" ]; then
            echo "dump $f $(stat -f '%z %m' "$f") $(sha256 -q "$f")"
        fi
    done
    BASH;

// What exists on the server alone, as a tar.gz on the output: $1 = LIVE_DIR,
// $2 = CV_DIR.
const REMOTE_FILES = <<<'BASH'
    set -euo pipefail
    cd "$HOME"
    stage=$(mktemp -d "$HOME/.backup-pull.XXXXXX")
    trap 'rm -rf "$stage"' EXIT
    mkdir "$stage/tvtakip" "$stage/mail"
    # What the live checkout holds and git doesn't — but not the worker's lock.
    (cd "$1" && git ls-files --others -z | tar -cf - --null --exclude '*.lock' -T -) | tar -xf - -C "$stage/tvtakip"
    cp -Rp "$2" "$stage/cv"
    # The mailboxes' Sieve rules (the forwarding).
    find mail -path '*/sieve/*.sieve' -print0 | tar -cf - --null -T - | tar -xf - -C "$stage"
    crontab -l > "$stage/crontab.txt"
    # The host's settings, to set an account up again by: sites and their
    # options, certificates, databases, and each DNS zone with its mailboxes.
    plain() { sed $'s/\x1b\\[[0-9;]*m//g'; }
    {
        for what in 'www list --verbose' 'ssl www list' 'mysql list'; do
            echo "### devil $what"
            devil $what || echo '(failed)'
        done
        for zone in $(devil dns list | plain | awk 'NF && $1 != "Domain" { print $1 }'); do
            for what in "dns list $zone" "mail list $zone"; do
                echo "### devil $what"
                devil $what || echo '(failed)'
            done
        done
    } 2>&1 | plain > "$stage/host.txt"
    tar -czf - --format ustar -C "$stage" tvtakip cv mail crontab.txt host.txt
    BASH;

/**
 * Run a command without a shell in between: [exit code, its output byte for
 * byte — or '' with the output written to $toFile — and its error output].
 * The error output is caught as well: a scheduled run has no console for it.
 */
function run(array $command, string $stdin = '', ?string $toFile = null): array
{
    $errors = tmpfile();
    $process = @proc_open($command, [['pipe', 'r'], $toFile === null ? ['pipe', 'w'] : ['file', $toFile, 'wb'], $errors], $pipes);
    if (!is_resource($process)) {
        return [-1, '', "could not start $command[0]"];
    }
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $output = '';
    if ($toFile === null) {
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
    }
    $code = proc_close($process);
    rewind($errors);
    return [$code, $output, trim(stream_get_contents($errors))];
}

/** Run one of the scripts above on the server, by bash there. */
function remote(string $script, string ...$args): array
{
    return run([...SSH, rtrim('bash -s -- ' . implode(' ', $args))], str_replace("\r", '', $script));
}

/** How a command failed, for a problem line. */
function failure(int $code, string $errors): string
{
    return "exit $code" . ($errors === '' ? '' : ': ' . preg_replace('/\s+/', ' ', $errors));
}

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

function megabytes(int $bytes): string
{
    return number_format($bytes / 1048576, 1) . ' MB';
}

/**
 * End the run: what was done and what went wrong, on the output and as one
 * line in the folder's log; problems in a message box too. Exits 1 on a problem.
 */
function finish(string $dir, array $done, array $problems): never
{
    foreach ($done as $line) {
        echo "$line\n";
    }
    foreach ($problems as $line) {
        echo "PROBLEM: $line\n";
    }
    if (is_dir($dir)) {
        $line = $problems ? 'PROBLEM  ' . implode('; ', $problems) : 'ok       ' . implode('; ', $done);
        file_put_contents("$dir/pull.log", gmdate('Y-m-d H:i\Z') . "  $line\n", FILE_APPEND);
    }
    if ($problems && PHP_OS_FAMILY === 'Windows') {
        // A box on the desktop of whoever is logged in. msg.exe returns at
        // once; the box stays until it is clicked away, a week at most.
        $text = "The backup copy from hostUNO has a problem:\n\n- " . implode("\n- ", $problems)
            . "\n\nFolder: " . str_replace('/', '\\', $dir) . "\nMore: README.md, \"Backups and restoring\".";
        run(['msg.exe', getenv('USERNAME') ?: '*', '/TIME:' . 7 * 86400, $text]);
    }
    exit($problems ? 1 : 0);
}

if ($argc > 2 || str_starts_with($argv[1] ?? '', '-')) {
    fwrite(STDERR, "Usage: php scripts/backup_pull.php [<folder>]\n");
    exit(1);
}
$dir = rtrim(str_replace('\\', '/', $argv[1] ?? (getenv('USERPROFILE') ?: getenv('HOME')) . '/Backups/hostuno'), '/');
$done = $problems = [];

foreach (["$dir/dbdumps", "$dir/files"] as $folder) {
    if (!is_dir($folder) && !@mkdir($folder, 0700, true)) {
        finish($dir, [], ["could not create $folder"]);
    }
}
// One run at a time: the scheduled one and one by hand would write the same files.
$lock = fopen("$dir/pull.lock", 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Another run is at work in $dir.\n";
    exit(0);
}

// 1. The dumps: every one the server has and this folder doesn't.
[$code, $list, $errors] = remote(REMOTE_LIST);
if ($code !== 0 || !preg_match('/^now (\d+)$/m', $list, $m)) {
    finish($dir, [], ['could not list the dumps on the server over SSH (' . failure($code, $errors) . ')']);
}
$now = (int) $m[1];
preg_match_all('/^dump (tvtakip-[A-Za-z0-9._-]+\.sql\.gz) (\d+) (\d+) ([0-9a-f]{64})$/m', $list, $dumps, PREG_SET_ORDER);
$copied = 0;
foreach ($dumps as [, $name, $size, $modified, $sha256]) {
    $file = "$dir/dbdumps/$name";
    if (is_file($file) && filesize($file) === (int) $size) {
        continue;
    }
    [$code, , $errors] = run([...SSH, "cat dbdumps/$name"], toFile: "$file.part");
    if ($code !== 0) {
        $problems[] = "could not copy $name (" . failure($code, $errors) . ')';
    } elseif (filesize("$file.part") !== (int) $size || hash_file('sha256', "$file.part") !== $sha256) {
        $problems[] = "$name arrived different from the server's file";
    } else {
        rename("$file.part", $file);
        touch($file, (int) $modified);
        $copied++;
        continue;
    }
    @unlink("$file.part");
}
$done[] = "$copied of " . count($dumps) . ' dumps on the server were new here';

// The newest nightly dump: it has to be last night's, and a whole dump.
$nightly = null;
foreach ($dumps as $dump) {
    if (preg_match('/^tvtakip-\d{8}\.sql\.gz$/', $dump[1]) && $dump[1] > ($nightly[1] ?? '')) {
        $nightly = $dump;
    }
}
if ($nightly === null) {
    $problems[] = 'the server has no nightly dump';
} elseif ($now - $nightly[3] > NIGHTLY_MAX_AGE) {
    $problems[] = "no nightly dump was made on the server: the newest, $nightly[1], is " . round(($now - $nightly[3]) / 3600) . ' hours old';
} elseif (is_file("$dir/dbdumps/$nightly[1]") && !dump_complete("$dir/dbdumps/$nightly[1]")) {
    $problems[] = "the nightly dump $nightly[1] is not a whole dump: mysqldump failed on the server, or the copy here got damaged";
} else {
    $done[] = "the newest nightly dump is $nightly[1] (" . megabytes((int) $nightly[2]) . ')';
}

// 2. What exists on the server alone, as one archive a day.
[$code, $archive, $errors] = remote(REMOTE_FILES, LIVE_DIR, CV_DIR);
$tar = $code === 0 ? @gzdecode($archive) : false;
if ($tar === false || !str_contains($tar, "tvtakip/config.php\0")) {
    $problems[] = "could not copy the server's own files" . ($code === 0 ? ': the archive is damaged or has no config.php in it' : ' (' . failure($code, $errors) . ')');
} else {
    $file = "$dir/files/hostuno-" . gmdate('Ymd') . '.tar.gz';
    file_put_contents("$file.part", $archive);
    rename("$file.part", $file);
    $done[] = 'server-only files in ' . basename($file) . ' (' . number_format(strlen($archive) / 1024) . ' KB)';
}

// 3. Only a run without a problem tells the server, and only then does
// anything old go — while the copies are not complete and current, the old
// ones may be the only ones.
if (!$problems) {
    [$code, , $errors] = run([...SSH, 'cat > dbdumps/off-site.txt'], "$nightly[1] and everything older copied off the server, " . gmdate('Y-m-d H:i\Z') . "\n");
    if ($code !== 0) {
        $problems[] = 'could not leave the note on the server (' . failure($code, $errors) . ')';
    }
}
if (!$problems) {
    foreach ([...glob("$dir/dbdumps/tvtakip-*.sql.gz"), ...glob("$dir/files/hostuno-*.tar.gz")] as $file) {
        if (filemtime($file) < time() - KEEP_DAYS * 86400) {
            unlink($file);
        }
    }
}
finish($dir, $done, $problems);
