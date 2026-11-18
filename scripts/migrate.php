<?php
// Applies the pending schema migrations, oldest first, and records each in the
// schema_migrations table. A migration is a file in migrations/ named
// NNNN_what_it_does.sql — plain statements — or .php for a change that needs
// code: that file returns a function (PDO $pdo): void. 0001_baseline.sql is
// the whole schema as it stood when migrations started, so this same command
// sets up an empty database.
//
// Usage: php scripts/migrate.php           apply what is pending
//        php scripts/migrate.php --status  only list the migrations and their state
//
// A migration that only changes rows runs in one transaction. A schema change
// can't: MySQL commits it on the spot. So a file holds one schema change, the
// run stops at the first failure, and the failed migration stays unrecorded.
// An applied migration is never edited or renamed — the next change is a new
// file. There are no "down" migrations: undo with a new one, or restore a dump.

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require __DIR__ . '/../includes/db.php';

const MIGRATIONS_DIR = __DIR__ . '/../migrations';

/**
 * Every migration as version => path, in the order they apply (scandir sorts
 * by name, and the numbers are unique). The version is the file name without
 * its extension. A file that doesn't follow the naming would silently never
 * run, so it stops the run instead.
 */
function migration_files(): array
{
    $files = [];
    $numbers = [];
    foreach (scandir(MIGRATIONS_DIR) as $name) {
        if ($name[0] === '.') {
            continue;
        }
        if (!preg_match('/^((\d{4})_[a-z0-9_]+)\.(?:sql|php)$/', $name, $m)) {
            throw new RuntimeException("migrations/$name is not named NNNN_what_it_does.sql (or .php)");
        }
        if (isset($numbers[$m[2]])) {
            throw new RuntimeException("migrations/{$numbers[$m[2]]} and migrations/$name share a number");
        }
        $numbers[$m[2]] = $name;
        $files[$m[1]] = MIGRATIONS_DIR . '/' . $name;
    }
    return $files;
}

/**
 * The statements of a .sql migration: the text split at every ";" that sits
 * outside strings, quoted names and comments; comments are dropped. DELIMITER
 * isn't understood — what needs more than plain statements is a .php file.
 */
function migration_statements(string $sql): array
{
    $token = <<<'REGEX'
        ~ '(?: [^'\\]++ | \\. | '' )*+'    # string
        | "(?: [^"\\]++ | \\. | "" )*+"    # string
        | `(?: [^`]++ | `` )*+`            # quoted name
        | (?<comment> --(?=\s|$)[^\n]*+ | \#[^\n]*+ | /\*.*?\*/ )
        | (?<end> ; )
        | [^'"`\-\#/;]++                   # anything else
        | .                                # a lone "-" or "/", an unclosed quote
        ~xs
        REGEX;
    if (preg_match_all($token, $sql, $tokens, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL) === false) {
        throw new RuntimeException('could not read the SQL: ' . preg_last_error_msg());
    }
    $statements = [];
    $current = '';
    foreach ($tokens as $t) {
        if (isset($t['end'])) {
            $statements[] = trim($current);
            $current = '';
        } else {
            $current .= isset($t['comment']) ? ' ' : $t[0];
        }
    }
    $statements[] = trim($current);
    return array_values(array_filter($statements, 'strlen'));
}

/**
 * Apply one migration and record it — in one transaction as far as MySQL
 * allows: a schema change ends the transaction, and whatever follows it (the
 * record too) is committed statement by statement.
 */
function migration_apply(PDO $pdo, string $version, string $path): void
{
    $pdo->beginTransaction();
    try {
        if (str_ends_with($path, '.php')) {
            $run = require $path;
            if (!is_callable($run)) {
                throw new RuntimeException('the file must return a function (PDO $pdo): void');
            }
            $run($pdo);
        } else {
            foreach (migration_statements(file_get_contents($path)) as $i => $statement) {
                try {
                    $pdo->exec($statement);
                } catch (PDOException $e) {
                    // Without the blank lines the dropped comments left behind.
                    $shown = preg_replace('/^\h*\R/m', '', $statement);
                    throw new RuntimeException('statement ' . ($i + 1) . ' failed: ' . $e->getMessage() . "\n\n$shown");
                }
            }
        }
        $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)')->execute([$version]);
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
            $state = 'Rolled back: the database is as it was.';
        } else {
            $state = 'Not rolled back: what ran before the failure stays (MySQL commits a schema change '
                . 'on the spot). Put that right by hand before the next run.';
        }
        throw new RuntimeException($e->getMessage() . "\n\n$state");
    }
}

$unknown = array_diff(array_slice($argv, 1), ['--status']);
if ($unknown) {
    fwrite(STDERR, 'Unknown argument: ' . implode(' ', $unknown) . "\nUsage: php scripts/migrate.php [--status]\n");
    exit(1);
}

try {
    $files = migration_files();
    $pdo = db();
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(100) NOT NULL PRIMARY KEY,  -- the file name without its extension
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );
    $status = in_array('--status', $argv, true);
    // One run at a time; the lock ends with the connection.
    if (!$status && !$pdo->query("SELECT GET_LOCK('tvtrack_migrate', 0)")->fetchColumn()) {
        throw new RuntimeException('another run is applying migrations right now');
    }
    $applied = $pdo->query('SELECT version, applied_at FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

// A checkout older than the database: nothing to apply, but worth knowing.
foreach (array_keys(array_diff_key($applied, $files)) as $version) {
    echo "$version is recorded as applied, but migrations/ has no such file\n";
}

if ($status) {
    foreach ($files as $version => $path) {
        printf("%-40s %s\n", $version, isset($applied[$version]) ? "applied {$applied[$version]}" : 'pending');
    }
    exit(0);
}

$pending = array_diff_key($files, $applied);
foreach ($pending as $version => $path) {
    echo "$version ... ";
    $t = microtime(true);
    try {
        migration_apply($pdo, $version, $path);
    } catch (Throwable $e) {
        echo "FAILED\n";
        fwrite(STDERR, "\n" . $e->getMessage() . "\n");
        exit(1);
    }
    printf("ok (%.1fs)\n", microtime(true) - $t);
}
echo $pending ? count($pending) . ' migration' . (count($pending) > 1 ? 's' : '') . " applied.\n" : "Nothing to migrate.\n";
