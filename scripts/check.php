<?php
// The static analysis (PHPStan, phpstan.neon.dist) and the tests (PHPUnit,
// phpunit.xml.dist), one after the other. Run it on the development machine:
// the tools are Composer's development packages in vendor/, which setup-dev.bat
// installs, and the tests need the local MySQL running (start-dev.bat starts
// it). scripts/deploy.php runs this first and deploys only when both pass.
//
// Usage: php scripts/check.php   (check.bat)
// Exit 0 when both pass, 1 otherwise. Both always run.

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
if (count($argv) > 1) {
    fwrite(STDERR, "Usage: php scripts/check.php\n");
    exit(1);
}
chdir(dirname(__DIR__));

$tools = [
    'Static analysis' => ['vendor/bin/phpstan', 'analyse', '--no-progress', '--memory-limit=1G'],
    'Tests' => ['vendor/bin/phpunit'],
];
$failed = [];
foreach ($tools as $name => [$tool]) {
    if (!is_file($tool)) {
        fwrite(STDERR, "There is no $tool: setup-dev.bat installs the development tools (composer install).\n");
        exit(1);
    }
}
foreach ($tools as $name => $command) {
    echo "== $name\n";
    // On the PHP running this one; the output goes straight to ours.
    $process = proc_open([PHP_BINARY, ...$command], [], $pipes);
    if (!is_resource($process) || proc_close($process) !== 0) {
        $failed[] = $name;
    }
    echo "\n";
}
if ($failed) {
    fwrite(STDERR, 'CHECK FAILED: ' . implode(' and ', $failed) . " — the output is above.\n");
    exit(1);
}
echo "Static analysis and tests passed.\n";
