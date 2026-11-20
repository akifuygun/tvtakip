<?php

namespace Tvtrack\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Tvtrack\Tests\Support\Clock;
use Tvtrack\Tests\Support\DatabaseTestCase;

/**
 * The three "has it aired" gates (auth.php), run by MySQL as the pages run
 * them: aired_sql() decides what can be marked watched, certainly_aired_sql()
 * what counts as aired in My Shows' progress, airs_by_today_sql() what the
 * calendar lists. An exact airstamp decides alone wherever there is one; a
 * date-only episode is markable from its air day, counted only after it, and
 * listed on it.
 */
final class AiredGatesTest extends DatabaseTestCase
{
    // The moment the gates are asked at: 12:00 UTC on 15 July 2026, a viewer
    // in Istanbul (UTC+3). Their day ends at 21:00 UTC.
    private const NOW = '2026-07-15 12:00:00';
    private const TODAY = '2026-07-15';
    private const TODAY_END_UTC = '2026-07-15 21:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec("INSERT INTO shows (imdb_id, name) VALUES ('tt9000001', 'Gated')");
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('SET TIMESTAMP = DEFAULT');
        parent::tearDown();
    }

    /** [airdate, airstamp (UTC), markable, counted as aired, on the calendar] */
    public static function moments(): array
    {
        return [
            'aired an hour ago' => ['2026-07-15', '2026-07-15 11:00:00', true, true, true],
            'airs in an hour' => ['2026-07-15', '2026-07-15 13:00:00', false, false, true],
            'airs at 23:30 local' => ['2026-07-15', '2026-07-15 20:30:00', false, false, true],
            'airs at 00:30 local tomorrow' => ['2026-07-15', '2026-07-15 21:30:00', false, false, false],
            'stamp earlier than its date' => ['2026-07-16', '2026-07-15 06:00:00', true, true, true],
            'US evening = after midnight here' => ['2026-07-15', '2026-07-16 02:00:00', false, false, false],
            'date only, today' => ['2026-07-15', null, true, false, true],
            'date only, yesterday' => ['2026-07-14', null, true, true, true],
            'date only, tomorrow' => ['2026-07-16', null, false, false, false],
            'no date, no time' => [null, null, false, false, false],
            'time without a date' => [null, '2026-07-15 11:00:00', true, true, true],
        ];
    }

    #[DataProvider('moments')]
    public function testGatesAtAFixedMoment(?string $airdate, ?string $airstamp, bool $markable, bool $counted, bool $listed): void
    {
        $this->episode($airdate, $airstamp);
        // MySQL's clock (UTC_TIMESTAMP() included) stands at NOW for this connection.
        $this->pdo->exec('SET TIMESTAMP = UNIX_TIMESTAMP(CONVERT_TZ(' . $this->pdo->quote(self::NOW) . ", '+00:00', @@session.time_zone))");
        $this->assertSame(self::NOW, $this->value('SELECT UTC_TIMESTAMP()'), 'the clock is set');

        $this->assertSame($markable, $this->passes(aired_sql('e'), [self::TODAY]), 'aired_sql');
        $this->assertSame($counted, $this->passes(certainly_aired_sql('e'), [self::TODAY]), 'certainly_aired_sql');
        $this->assertSame($listed, $this->passes(airs_by_today_sql('e'), [self::TODAY_END_UTC, self::TODAY]), 'airs_by_today_sql');
    }

    /** [airdate, airstamp], relative to the real now (episode_aired() reads the clock). */
    public static function nowCases(): array
    {
        return [
            'aired an hour ago' => [Clock::day(0), Clock::utc(-3600)],
            'airs in an hour' => [Clock::day(0), Clock::utc(3600)],
            'stamped tomorrow' => [Clock::day(1), Clock::utc(86400)],
            'date only, today' => [Clock::day(0), null],
            'date only, yesterday' => [Clock::day(-1), null],
            'date only, tomorrow' => [Clock::day(1), null],
            'no date, no time' => [null, null],
        ];
    }

    #[DataProvider('nowCases')]
    public function testEpisodeAiredAgreesWithAiredSql(?string $airdate, ?string $airstamp): void
    {
        $this->episode($airdate, $airstamp);
        $this->assertSame($this->passes(aired_sql('e'), [today()]), episode_aired($airdate, $airstamp));
    }

    public function testToday(): void
    {
        $this->assertSame(date('Y-m-d'), today());
        $this->assertSame('Europe/Istanbul', date_default_timezone_get(), 'without a tz cookie');
        $end = new \DateTimeImmutable(today_end_utc(), new \DateTimeZone('UTC'));
        $this->assertSame('00:00', $end->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format('H:i'));
        $this->assertSame(Clock::day(1), $end->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format('Y-m-d'));
    }

    private function episode(?string $airdate, ?string $airstamp): void
    {
        $this->pdo->prepare(
            "INSERT INTO episodes (show_imdb_id, season, number, airdate, airstamp) VALUES ('tt9000001', 1, 1, ?, ?)"
        )->execute([$airdate, $airstamp]);
    }

    private function passes(string $gate, array $params): bool
    {
        return (bool) $this->value("SELECT COUNT(*) FROM episodes e WHERE $gate", $params);
    }
}
