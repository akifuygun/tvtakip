<?php
// The test suite's start (phpunit.xml.dist). It sets the configuration that
// config.php sets elsewhere, so a test never touches this machine's own
// database or the providers:
// - the database is tvtakip_test on this machine's MySQL (setup-dev.bat
//   creates it, start-dev.bat starts MySQL), emptied and built from
//   migrations/ by the first test that needs it (Support\DatabaseTestCase);
// - the providers are Support\FakeProviders, which answers in their place;
// - the private directory (SESSION_DIR) is a fresh temporary one.
// The TVTRACK_TEST_DB_* environment variables name another database server.

const TVTRACK_TESTS = true;

define('DB_HOST', getenv('TVTRACK_TEST_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('TVTRACK_TEST_DB_NAME') ?: 'tvtakip_test');
define('DB_USER', getenv('TVTRACK_TEST_DB_USER') ?: 'tvtakip');
define('DB_PASS', getenv('TVTRACK_TEST_DB_PASS') ?: '');
define('TMDB_API_KEY', 'test-tmdb-key');
define('TVDB_API_KEY', 'test-tvdb-key');
define('MAIL_FROM', '');
define('WATCH_SITES', [
    'AA' => ['url' => 'https://aa.example.com/{slug}-{season}x{episode}'],
    'BB' => ['url' => 'https://www.bb.example.org/{slug}/{season}-sezon-{episode}-bolum/'],
]);

$sessionDir = sys_get_temp_dir() . '/tvtrack-tests-' . getmypid();
define('SESSION_DIR', $sessionDir);
register_shutdown_function(static function () use ($sessionDir): void {
    foreach (glob($sessionDir . '/*') ?: [] as $file) {
        unlink($file);
    }
    if (is_dir($sessionDir)) {
        rmdir($sessionDir);
    }
});

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/importer.php';
require_once __DIR__ . '/../includes/calendar.php';
// For its functions: the test database is built the way migrate.php builds one.
require_once __DIR__ . '/../scripts/migrate.php';
