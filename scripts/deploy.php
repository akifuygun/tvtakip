<?php
// Deploys main to the live site and checks the result. Run it on the
// development machine, in a checkout of main that is committed and pushed.
//
// Usage: php scripts/deploy.php          deploy, then check the live site
//        php scripts/deploy.php --check  only check the live site as it is
//
// Three steps; the first failure ends the run with "DEPLOY FAILED" and exit 1:
//   1. Here: the checkout is on main with nothing uncommitted (an untracked
//      file counts — it would be missing on live) and nothing unpushed.
//   2. On the server, over SSH: the live checkout has no changes of its own
//      and fast-forwards to this very commit. Pending migrations go first,
//      while the old code still serves, after a dump of the database — so a
//      migration has to leave the code before it working, and one that fails
//      leaves that code live. Only then the code is switched.
//   3. From here: the public pages answer 200 and carry the asset version of
//      the commit's CSS/JS (live works it out from its own files, so that also
//      says those are the commit's), the login-gated ones turn a guest away,
//      the assets and the service worker are served, the repository's
//      internals are refused.
//
// Deploying again when live is current changes nothing and repeats the checks.
//
// Rolling back: put the commit live ran before (the deploy names it) back with
//   ssh hostuno "git -C domains/tvtakip.akifuygun.com/public_html reset --hard <commit>"
// and run --check. Migrations are not undone: README.md, "Deployment".

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

require __DIR__ . '/../includes/assets.php';

const SSH_HOST = 'hostuno';  // a Host alias in ~/.ssh/config
const LIVE_DIR = 'domains/tvtakip.akifuygun.com/public_html';  // the live checkout, from the SSH user's home
const LIVE_URL = 'https://tvtakip.akifuygun.com';
// Live's opcache looks at a file's timestamp at most this often
// (opcache.revalidate_freq), so for that long it may answer with the old code.
const OPCACHE_SECONDS = 2;

// The server's part, run by bash there: $1 = the commit to deploy, $2 = LIVE_DIR.
const REMOTE_DEPLOY = <<<'BASH'
    set -euo pipefail
    new=$1
    cd "$HOME/$2"

    old=$(git rev-parse HEAD)
    if [ -n "$(git status --porcelain)" ]; then
        echo "The live checkout has changes of its own:"
        git status --short
        exit 1
    fi
    git fetch --quiet origin main
    if [ "$(git rev-parse FETCH_HEAD)" != "$new" ]; then
        echo "GitHub's main is at $(git rev-parse --short FETCH_HEAD), not at the commit being deployed."
        exit 1
    fi
    if ! git merge-base --is-ancestor "$old" "$new"; then
        echo "Live is at ${old:0:7}, which the commit being deployed does not contain."
        exit 1
    fi

    # The schema before the code: only migrations/ and the script that applies
    # them come from the new commit yet. Whatever fails from here on puts the
    # checkout back as it was.
    git checkout --quiet "$new" -- migrations scripts/migrate.php
    trap 'git reset --quiet --hard "$old"' EXIT
    status=$(php scripts/migrate.php --status)
    if grep -q ' pending$' <<< "$status"; then
        # Named like the nightly dumps, so their 14-day pruning takes these too.
        dump="$HOME/dbdumps/tvtakip-$(date +%Y%m%d-%H%M)-before-${new:0:7}.sql.gz"
        if ! mysqldump --single-transaction --no-tablespaces "$(php -r 'require "config.php"; echo DB_NAME;')" | gzip > "$dump"; then
            rm -f "$dump"
            echo "The database dump failed, so no migration was run."
            exit 1
        fi
        echo "Database dumped to ~${dump#"$HOME"}"
        php scripts/migrate.php
    fi
    git merge --quiet --ff-only "$new"
    trap - EXIT
    BASH;

function fail(string $message): never
{
    fwrite(STDERR, "\nDEPLOY FAILED: $message\n");
    exit(1);
}

/**
 * Run a command without a shell in between: [exit code, its output without
 * the trailing newline — byte for byte with $trim off]. Its error output goes
 * straight to ours; with $capture off so does its output, as it comes. The
 * child inherits those streams by not being given any: passing STDOUT/STDERR
 * instead loses what was written before whenever the output is a file.
 */
function run(array $command, string $stdin = '', bool $capture = true, bool $trim = true): array
{
    $process = proc_open($command, $capture ? [['pipe', 'r'], ['pipe', 'w']] : [['pipe', 'r']], $pipes);
    if (!is_resource($process)) {
        fail("could not start $command[0]");
    }
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $output = '';
    if ($capture) {
        $output = stream_get_contents($pipes[1]);
        $output = $trim ? rtrim($output, "\r\n") : $output;
        fclose($pipes[1]);
    }
    return [proc_close($process), $output];
}

/** Git in this checkout: its output. A git that fails ends the run. */
function git(string ...$args): string
{
    [$code, $output] = run(['git', ...$args]);
    if ($code !== 0) {
        fail('git ' . implode(' ', $args) . " failed (exit $code)" . ($output === '' ? '' : ":\n$output"));
    }
    return $output;
}

/** A commit as "abc1234 Its subject line". */
function describe(string $commit): string
{
    return git('log', '-1', '--format=%h %s', $commit);
}

/** The commit the live checkout is on. */
function live_commit(): string
{
    [$code, $output] = run(['ssh', SSH_HOST, 'git -C ' . LIVE_DIR . ' rev-parse HEAD']);
    if ($code !== 0 || !preg_match('/^[0-9a-f]{40}$/', $output)) {
        fail('could not read the live commit over SSH (' . SSH_HOST . ')' . ($output === '' ? '' : ":\n$output"));
    }
    return $output;
}

/**
 * A file as a commit has it, byte for byte — also where this checkout's copy
 * has Windows line ends (core.autocrlf), which live's doesn't.
 */
function committed_file(string $commit, string $path): string
{
    [$code, $contents] = run(['git', 'show', "$commit:$path"], trim: false);
    if ($code !== 0) {
        fail("could not read $path as of $commit");
    }
    return $contents;
}

/** GET a path of the live site, redirects not followed: [status, body] — status 0 with the reason when there was no answer. */
function fetch(string $path): array
{
    static $ch = null;
    $ch ??= curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => LIVE_URL . $path,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'tvtrack-deploy-check',
    ]);
    $body = curl_exec($ch);
    return $body === false ? [0, curl_error($ch)] : [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $body];
}

/**
 * Check the live site as a guest against the commit it should be running:
 * one line per check, returns [checks made, checks failed].
 */
function check_live(string $commit): array
{
    // What asset_version() answers on live when its CSS/JS are this commit's.
    $version = assets_hash(fn (string $path): string => committed_file($commit, $path));
    $css = "/assets/css/style.css?v=$version";
    $js = "/assets/js/app.js?v=$version";
    $checks = $failed = 0;
    $report = function (string $path, int|string $status, array $problems) use (&$checks, &$failed): void {
        printf("  %-4s  %3s  %s%s\n", $problems ? 'FAIL' : 'ok', $status, $path, $problems ? ' — ' . implode(', ', $problems) : '');
        $checks++;
        $failed += $problems ? 1 : 0;
    };
    // One path: the status, and the texts its body must contain. Returns the body.
    $check = function (string $path, int $expect = 200, string ...$needles) use ($report): string {
        [$status, $body] = fetch($path);
        if ($status === 0) {
            $report($path, '---', [$body]);
            return '';
        }
        $problems = $status === $expect ? [] : ["expected $expect"];
        foreach ($needles as $needle) {
            if (!str_contains($body, $needle)) {
                $problems[] = "no $needle in it";
            }
        }
        $report($path, $status, $problems);
        return $body;
    };

    echo 'Checking ' . LIVE_URL . " (asset version $version)\n";
    // A page's last tag is the script: finding it also says the page was rendered to its end.
    foreach (['/', '/tr/', '/upcoming', '/search.php', '/login.php'] as $path) {
        $check($path, 200, $css, $js);
    }
    // The catalogs, and the first show and movie they list.
    foreach (['/browse' => '#/series/(?:tt|tm|mz)\d+#', '/movies' => '#/movie/(?:tt|tm)\d+#'] as $path => $link) {
        if (preg_match($link, $check($path, 200, $css, $js), $m)) {
            $check($m[0], 200, $css, $js);
        } else {
            $report("$path: its first title", '---', ['no link found']);
        }
    }
    // Login-gated: a guest is sent to the login form, the API answers 401.
    $check('/myshows.php', 302);
    $check('/tv', 302);
    $check('/api/refresh.php', 401);
    foreach (VERSIONED_ASSETS as $path) {
        $check("/$path?v=$version");
    }
    // The service worker as app.js registers it.
    foreach (["/sw.js?v=$version", '/manifest.php', '/sitemap.xml'] as $path) {
        $check($path);
    }
    // The docroot is a git checkout: its internals must stay out of reach.
    foreach (['/.git/HEAD', '/scripts/deploy.php', '/migrations/0001_baseline.sql'] as $path) {
        $check($path, 403);
    }
    return [$checks, $failed];
}

$args = array_slice($argv, 1);
if (array_diff($args, ['--check'])) {
    fwrite(STDERR, 'Unknown argument: ' . implode(' ', array_diff($args, ['--check'])) . "\nUsage: php scripts/deploy.php [--check]\n");
    exit(1);
}
chdir(dirname(__DIR__));

if ($args) {
    $live = live_commit();
    echo 'Live runs ' . describe($live) . "\n";
    [$checks, $failed] = check_live($live);
    if ($failed) {
        fail("$failed of $checks checks failed.");
    }
    echo "All $checks checks passed.\n";
    exit(0);
}

// 1. Here.
if (($branch = git('rev-parse', '--abbrev-ref', 'HEAD')) !== 'main') {
    fail("this checkout is on \"$branch\"; live runs main.");
}
if (($changes = git('status', '--porcelain')) !== '') {
    fail("uncommitted changes — commit them, or take them out of the way:\n$changes");
}
$new = git('rev-parse', 'HEAD');
if (($unpushed = git('rev-list', '--count', 'origin/main..HEAD')) !== '0') {
    fail("$unpushed commit" . ($unpushed === '1' ? '' : 's') . ' not pushed to origin/main.');
}
if ($new !== git('rev-parse', 'origin/main')) {
    fail('this checkout is behind origin/main — pull first.');
}
$live = live_commit();
if (run(['git', 'merge-base', '--is-ancestor', $live, $new])[0] !== 0) {
    fail("live runs $live, which is not part of main here. Look at the live checkout before anything else; don't force it.");
}
if ($live === $new) {
    echo 'Live already runs ' . describe($new) . "\n";
} else {
    echo 'Deploying to live, which runs ' . describe($live) . ":\n  "
        . str_replace("\n", "\n  ", git('log', '--format=%h %s', "$live..$new")) . "\n";
}

// 2. On the server.
[$code] = run(['ssh', SSH_HOST, "bash -s -- $new " . LIVE_DIR], str_replace("\r", '', REMOTE_DEPLOY), capture: false);
if ($code !== 0) {
    $now = live_commit();
    fail("the server's part stopped (exit $code) — its output is above. Live runs "
        . describe($now) . ($now === $live ? ', as before.' : '.'));
}

// 3. From here.
if ($live !== $new) {
    echo 'Live now runs ' . describe($new) . "\n";
    sleep(OPCACHE_SECONDS + 1);
}
[$checks, $failed] = check_live($new);
if ($failed) {
    fail("$failed of $checks checks failed." . ($live === $new ? '' : ' Live runs the new commit. To put the one before back:'
        . "\n  ssh " . SSH_HOST . ' "git -C ' . LIVE_DIR . " reset --hard $live\""
        . "\nthen check it with: php scripts/deploy.php --check"));
}
echo "All $checks checks passed.\n";
