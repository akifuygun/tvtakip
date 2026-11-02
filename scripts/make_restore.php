<?php
// Generate deploy/restore_data_NN.sql: the whole LOCAL database (users, lists
// and watched history included) as one-statement-per-line SQL that loads into
// *_new staging tables on live. Every statement is idempotent
// (CREATE TABLE IF NOT EXISTS + INSERT IGNORE), so the resumable web runner
// (deploy/restore.php) can re-run from its last byte offset after the host
// kills a request, then swap the staging tables in with one RENAME TABLE.
// The output is split at statement boundaries into parts under 8 MB because
// the host silently discards FTP uploads over 10 MB; the runner reads the
// parts as one stream. Nothing here touches live; the files delete nothing.
//
// Usage: php scripts/make_restore.php
//   then paste the printed DATA_PARTS line into deploy/restore.php

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../includes/db.php';

// Parents before children, so the rows load with FK checks on or off alike.
const RESTORE_TABLES = ['users', 'shows', 'movies', 'episodes', 'user_shows', 'user_movies', 'watched_episodes', 'remember_tokens'];
// Login state belongs to the live environment: the runner refills it from live.
const RESTORE_DDL_ONLY = ['remember_tokens'];
// Rows per INSERT: wide rows (galleries, overviews) in small chunks.
const RESTORE_CHUNK = ['shows' => 40, 'movies' => 40, 'episodes' => 500, 'watched_episodes' => 1000];
// "Latest activity" columns the runner compares with live before swapping.
const RESTORE_LATEST = [
    'users' => ['created_at'], 'shows' => ['synced_at'], 'movies' => ['synced_at'],
    'user_shows' => ['added_at'], 'user_movies' => ['added_at', 'watched_at'],
    'watched_episodes' => ['watched_at'],
];

/** SHOW CREATE TABLE output rewritten for the staging copy: one line,
 *  `name_new`, IF NOT EXISTS, FKs pointing at the other staging tables and
 *  unnamed (the runner names them per run so nothing clashes with live). */
function staging_ddl(string $create, string $table): string
{
    $sql = preg_replace('/\s+/', ' ', $create);
    $sql = preg_replace('/^CREATE TABLE `' . preg_quote($table, '/') . '`/', "CREATE TABLE IF NOT EXISTS `{$table}_new`", $sql);
    $sql = preg_replace('/ AUTO_INCREMENT=\d+/', '', $sql);
    $sql = preg_replace('/CONSTRAINT `[^`]+` FOREIGN KEY/', 'FOREIGN KEY', $sql);
    $sql = preg_replace('/REFERENCES `([^`]+)`/', 'REFERENCES `$1_new`', $sql);
    return $sql . ';';
}

const RESTORE_PART_MAX = 8_000_000;

/** Writes lines into deploy/restore_data_NN.sql, rotating before a line would
 *  push the current part past RESTORE_PART_MAX. */
final class PartWriter
{
    /** @var array<string,int> file name => bytes */
    public array $parts = [];
    private $fh = null;
    private int $size = 0;

    public function __construct(private string $dir)
    {
        foreach (glob("$dir/restore_data*.sql") ?: [] as $old) {
            unlink($old);
        }
    }

    public function line(string $line): void
    {
        if ($this->fh === null || $this->size + strlen($line) > RESTORE_PART_MAX) {
            $this->close();
            $name = sprintf('restore_data_%02d.sql', count($this->parts) + 1);
            $this->fh = fopen("$this->dir/$name", 'w');
            $this->parts[$name] = 0;
            $this->size = 0;
        }
        fwrite($this->fh, $line);
        $this->size += strlen($line);
        $this->parts[array_key_last($this->parts)] = $this->size;
    }

    public function close(): void
    {
        if ($this->fh !== null) {
            fclose($this->fh);
            $this->fh = null;
        }
    }
}

date_default_timezone_set('Europe/Istanbul');
$pdo = db();
$dir = realpath(__DIR__ . '/../deploy') ?: __DIR__ . '/../deploy';
if (!is_dir($dir)) {
    mkdir($dir);
}

$meta = [
    'generated_at' => date('Y-m-d H:i:s'),
    'db' => DB_NAME,
    'server' => $pdo->query('SELECT VERSION()')->fetchColumn(),
    'tables' => RESTORE_TABLES,
    'ddl_only' => RESTORE_DDL_ONLY,
    'latest_columns' => RESTORE_LATEST,
    'counts' => [],
    'latest' => [],
];
foreach (RESTORE_TABLES as $table) {
    $meta['counts'][$table] = in_array($table, RESTORE_DDL_ONLY, true)
        ? 0 : (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    foreach (RESTORE_LATEST[$table] ?? [] as $col) {
        $meta['latest']["$table.$col"] = $pdo->query("SELECT MAX(`$col`) FROM `$table`")->fetchColumn();
    }
}

$out = new PartWriter($dir);
$out->line('-- RESTORE_META ' . json_encode($meta, JSON_UNESCAPED_SLASHES) . "\n");

$total = 0;
foreach (RESTORE_TABLES as $table) {
    $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch()['Create Table'];
    $out->line("-- TABLE $table rows={$meta['counts'][$table]}\n");
    $out->line(staging_ddl($create, $table) . "\n");
    if (in_array($table, RESTORE_DDL_ONLY, true)) {
        continue;
    }
    $numeric = [];
    foreach ($pdo->query("SHOW COLUMNS FROM `$table`") as $col) {
        $numeric[$col['Field']] = (bool) preg_match('/^(?:(?:tiny|small|medium|big)?int|decimal)/', $col['Type']);
    }
    $cols = array_keys($numeric);
    $colList = '`' . implode('`,`', $cols) . '`';
    $chunkSize = RESTORE_CHUNK[$table] ?? 500;
    $prefix = "INSERT IGNORE INTO `{$table}_new` ($colList) VALUES ";

    // Unbuffered: the episodes table is far too big to hold in PHP memory.
    $reader = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
    ]);
    $rows = $reader->query("SELECT $colList FROM `$table`");
    $chunk = [];
    $n = 0;
    foreach ($rows as $row) {
        $vals = [];
        foreach ($row as $i => $v) {
            $vals[] = $v === null ? 'NULL'
                : ($numeric[$cols[$i]] && is_numeric($v) ? (string) $v : $pdo->quote((string) $v));
        }
        $chunk[] = '(' . implode(',', $vals) . ')';
        $n++;
        if (count($chunk) >= $chunkSize) {
            $out->line($prefix . implode(',', $chunk) . ";\n");
            $chunk = [];
        }
    }
    if ($chunk) {
        $out->line($prefix . implode(',', $chunk) . ";\n");
    }
    $rows->closeCursor();
    $total += $n;
    echo str_pad($table, 18) . "$n rows\n";
}
$out->close();

echo "$total rows in all, " . number_format(array_sum($out->parts)) . " bytes in " . count($out->parts) . " parts\n";
foreach ($out->parts as $name => $bytes) {
    echo "  $name  " . number_format($bytes) . ' bytes  sha1 ' . sha1_file("$dir/$name") . "\n";
}
echo 'latest activity: ' . json_encode($meta['latest'], JSON_UNESCAPED_SLASHES) . "\n\n";
echo "const DATA_PARTS = " . var_export($out->parts, true) . ";\n";
