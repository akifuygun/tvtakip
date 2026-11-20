<?php
// Replaces this machine's database with live's newest backup, then applies
// the migrations live hasn't had yet. Run it on the development machine, with
// the local MySQL running (start-dev.bat starts it).
//
// Usage: php scripts/load_live.php [--yes] [<dump>]
//   <dump>  a dump's name in the server's ~/dbdumps, or the path of a .sql.gz
//           here (the off-site copies, for one); without it: the server's
//           newest, the nightly one or one a deploy made before a migration
//   --yes   no question first
//
// The database config.php names is emptied first, so it ends up as live's was
// — its accounts and their password hashes included — plus this checkout's
// pending migrations, which is what a deploy would make of it. It refuses a
// database on another machine than this one: run on the server by mistake,
// it would otherwise empty live's.

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

require __DIR__ . '/../config.php';

// hostuno is a Host alias in ~/.ssh/config.
const SSH = ['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=20', 'hostuno'];

// The server's dumps, one "dump <name> <bytes> <modified> <sha256>" line each.
const REMOTE_LIST = <<<'BASH'
    set -euo pipefail
    cd "$HOME/dbdumps"
    for f in tvtakip-*.sql.gz; do
        if [ -f "$f" ]; then
            echo "dump $f $(stat -f '%z %m' "$f") $(sha256 -q "$f")"
        fi
    done
    BASH;

function fail(string $message): never
{
    fwrite(STDERR, "\nLOAD FAILED: $message\n");
    exit(1);
}

/**
 * Run a command without a shell in between: [exit code, its output]. With
 * $toFile its output goes into that file instead; with $passthru to ours, as
 * it comes. Its error output goes to ours. (A stream the child is not given
 * it inherits from this process.)
 */
function run(array $command, string $stdin = '', ?string $toFile = null, bool $passthru = false): array
{
    $streams = [['pipe', 'r']];
    if ($toFile !== null) {
        $streams[1] = ['file', $toFile, 'wb'];
    } elseif (!$passthru) {
        $streams[1] = ['pipe', 'w'];
    }
    $process = proc_open($command, $streams, $pipes);
    if (!is_resource($process)) {
        fail("could not start $command[0]");
    }
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $output = isset($pipes[1]) ? stream_get_contents($pipes[1]) : '';
    return [proc_close($process), $output];
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

/** The mysql client beside this PHP (C:\tvtakip-dev), else the one on the PATH. */
function mysql_client(): string
{
    $beside = dirname(PHP_BINARY, 2) . '/mysql/bin/mysql' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    return is_file($beside) ? $beside : 'mysql';
}

$args = array_slice($argv, 1);
$yes = in_array('--yes', $args, true);
$args = array_values(array_diff($args, ['--yes']));
if (count($args) > 1 || str_starts_with($args[0] ?? '', '-')) {
    fwrite(STDERR, "Usage: php scripts/load_live.php [--yes] [<dump>]\n");
    exit(1);
}

if (!in_array(strtolower(DB_HOST), ['localhost', '127.0.0.1', '::1'], true)) {
    fail('config.php names the database server ' . DB_HOST . ' — this replaces a database on this machine only.');
}
try {
    $server = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    fail('no MySQL server to load into (' . $e->getMessage() . '). start-dev.bat starts it.');
}
echo 'This machine: MySQL ' . $server->query('SELECT VERSION()')->fetchColumn() . ', database ' . DB_NAME . "\n";

// 1. The dump: a file here, or one of the server's, copied and compared.
$temporary = null;
if (isset($args[0]) && is_file($args[0])) {
    $dump = $args[0];
    $name = basename($dump);
} else {
    [$code, $list] = run([...SSH, 'bash -s'], str_replace("\r", '', REMOTE_LIST));
    preg_match_all('/^dump (tvtakip-[A-Za-z0-9._-]+\.sql\.gz) (\d+) (\d+) ([0-9a-f]{64})$/m', $list, $dumps, PREG_SET_ORDER);
    if ($code !== 0 || !$dumps) {
        fail("could not list the server's dumps over SSH (exit $code).");
    }
    if (isset($args[0])) {
        $dumps = array_values(array_filter($dumps, fn (array $d): bool => $d[1] === $args[0]));
        if (!$dumps) {
            fail("the server has no dump named $args[0] (nor is it a file here).");
        }
    }
    usort($dumps, fn (array $a, array $b): int => $b[3] <=> $a[3]);
    [, $name, $size, $modified, $sha256] = $dumps[0];
    echo "Copying $name (" . number_format($size / 1048576, 1) . ' MB, made ' . date('Y-m-d H:i', (int) $modified) . ") from the server ...\n";
    $dump = $temporary = tempnam(sys_get_temp_dir(), 'tvtakip-dump-');
    register_shutdown_function(fn () => @unlink($temporary));
    [$code] = run([...SSH, "cat dbdumps/$name"], toFile: $dump);
    clearstatcache();
    if ($code !== 0 || filesize($dump) !== (int) $size || hash_file('sha256', $dump) !== $sha256) {
        fail("$name did not arrive as the server has it.");
    }
}
if (!dump_complete($dump)) {
    fail("$name is not a whole dump: its last line is not mysqldump's \"Dump completed\".");
}

// 2. Ask first: everything in the local database goes.
$tables = (int) $server->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ' . $server->quote(DB_NAME))->fetchColumn();
if (!$yes) {
    echo 'Replace the database ' . DB_NAME . " ($tables tables) with $name? [y/N] ";
    if (strtolower(trim((string) fgets(STDIN))) !== 'y') {
        echo "Nothing changed.\n";
        exit(1);
    }
}

// 3. An empty database, set as live's (utf8mb4_unicode_ci is its default:
// what a table without a collation of its own gets), and the dump into it.
$quoted = '`' . str_replace('`', '``', DB_NAME) . '`';
$server->exec("DROP DATABASE IF EXISTS $quoted");
$server->exec("CREATE DATABASE $quoted CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

// The password in a file of its own, not on a command line others can list.
$options = tempnam(sys_get_temp_dir(), 'tvtakip-my-');
register_shutdown_function(fn () => @unlink($options));
file_put_contents($options, "[client]\nuser=\"" . addcslashes(DB_USER, '"\\') . "\"\npassword=\"" . addcslashes(DB_PASS, '"\\') . "\"\n");

echo "Loading $name into " . DB_NAME . " ...\n";
$started = microtime(true);
$process = proc_open(
    [mysql_client(), "--defaults-extra-file=$options", '--default-character-set=utf8mb4', '-h', DB_HOST, DB_NAME],
    [['pipe', 'r']],
    $pipes
);
if (!is_resource($process)) {
    fail('could not start ' . mysql_client());
}
// Byte for byte: PHP unpacks, the client reads its input as it is.
$gz = gzopen($dump, 'rb');
while (!gzeof($gz) && ($chunk = gzread($gz, 1 << 20)) !== false && $chunk !== '') {
    if (@fwrite($pipes[0], $chunk) === false) {
        break;
    }
}
gzclose($gz);
fclose($pipes[0]);
if (($code = proc_close($process)) !== 0) {
    fail("the mysql client stopped (exit $code) — its message is above. The database " . DB_NAME . ' is half loaded: run this again.');
}
printf("Loaded in %.0f s.\n", microtime(true) - $started);

// 4. What this checkout has and live hasn't had yet.
[$code] = run([PHP_BINARY, __DIR__ . '/migrate.php'], passthru: true);
if ($code !== 0) {
    fail("scripts/migrate.php failed (exit $code).");
}

$db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$counts = [];
foreach (['users', 'shows', 'episodes', 'movies', 'watched_episodes'] as $table) {
    $counts[] = number_format((int) $db->query("SELECT COUNT(*) FROM $table")->fetchColumn()) . " $table";
}
echo 'Done: ' . implode(', ', $counts) . ".\n";
