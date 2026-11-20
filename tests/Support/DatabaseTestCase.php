<?php

namespace Tvtrack\Tests\Support;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A test on the test database (tests/bootstrap.php), which is empty at every
 * test's start, with FakeProviders answering for TMDB, TVmaze and TheTVDB.
 * The first such test of a run builds the database from migrations/.
 */
abstract class DatabaseTestCase extends TestCase
{
    private static bool $built = false;

    protected PDO $pdo;
    protected FakeProviders $providers;

    public static function setUpBeforeClass(): void
    {
        if (self::$built) {
            return;
        }
        // Everything in it goes: never anything but a local *_test database.
        if (!in_array(DB_HOST, ['localhost', '127.0.0.1', '::1'], true) || !str_ends_with(DB_NAME, '_test')) {
            throw new RuntimeException('The tests empty their database: ' . DB_NAME . ' on ' . DB_HOST
                . ' is not a local database named *_test.');
        }
        try {
            $pdo = db();
        } catch (PDOException $e) {
            throw new RuntimeException('No test database (' . $e->getMessage() . '). The tests need MySQL running '
                . '(start-dev.bat starts it) with the database ' . DB_NAME . ' (setup-dev.bat creates it).');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $pdo->exec("DROP TABLE `$table`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        migrations_table($pdo);
        foreach (migration_files() as $version => $path) {
            migration_apply($pdo, $version, $path);
        }
        self::$built = true;
    }

    protected function setUp(): void
    {
        $this->pdo = db();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if ($table !== 'schema_migrations') {
                $this->pdo->exec("DELETE FROM `$table`");
            }
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $this->providers = new FakeProviders();
        $this->providers->install();
    }

    protected function tearDown(): void
    {
        FakeProviders::uninstall();
    }

    /** Providers that know nothing yet, in place of the current ones: for
     *  what an import does once what they say has changed. */
    protected function newProviders(): FakeProviders
    {
        $this->providers = new FakeProviders();
        $this->providers->install();
        return $this->providers;
    }

    /** All rows of a query. */
    protected function rows(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** One value of a query (false when there is no row). */
    protected function value(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /** A show's stored episodes, keyed "season-number". */
    protected function episodes(string $showId): array
    {
        $episodes = [];
        foreach ($this->rows('SELECT * FROM episodes WHERE show_imdb_id = ? ORDER BY season, number', [$showId]) as $ep) {
            $episodes[$ep['season'] . '-' . $ep['number']] = $ep;
        }
        return $episodes;
    }

    /** A user (id returned); the tests need no password. */
    protected function user(string $email = 'viewer@example.com'): int
    {
        $this->pdo->prepare("INSERT INTO users (email, display_name, password_hash) VALUES (?, 'Viewer', '')")
            ->execute([$email]);
        return (int) $this->pdo->lastInsertId();
    }

    /** $userId tracks the show and has watched these "season-number" episodes of it. */
    protected function watch(int $userId, string $showId, string ...$episodes): void
    {
        $this->pdo->prepare('INSERT IGNORE INTO user_shows (user_id, show_imdb_id) VALUES (?, ?)')
            ->execute([$userId, $showId]);
        foreach ($episodes as $key) {
            [$season, $number] = array_map('intval', explode('-', $key));
            $this->pdo->prepare(
                'INSERT INTO watched_episodes (user_id, episode_id)
                 SELECT ?, id FROM episodes WHERE show_imdb_id = ? AND season = ? AND number = ?'
            )->execute([$userId, $showId, $season, $number]);
        }
    }

    /** "season-number" of the episodes $userId has marked watched. */
    protected function watched(int $userId): array
    {
        return array_column($this->rows(
            "SELECT CONCAT(e.season, '-', e.number) AS k FROM watched_episodes w JOIN episodes e ON e.id = w.episode_id
             WHERE w.user_id = ? ORDER BY e.season, e.number",
            [$userId]
        ), 'k');
    }
}
