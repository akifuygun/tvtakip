<?php

namespace Tvtrack\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderMergeTest extends TestCase
{
    /** @return array{imdb_id: null, season: int, number: int, name: string, airdate: ?string} */
    private static function base(int $season, int $number, ?string $airdate): array
    {
        return ['imdb_id' => null, 'season' => $season, 'number' => $number, 'name' => 'x', 'airdate' => $airdate];
    }

    /** @return array{season: int, number: int, name: string, airdate: string, finale: string} */
    private static function tvdb(int $season, int $number, string $airdate): array
    {
        return ['season' => $season, 'number' => $number, 'name' => 'n', 'airdate' => $airdate, 'finale' => ''];
    }

    /**
     * @param list<array<string, mixed>> $added
     * @return list<string>
     */
    private static function keys(array $added): array
    {
        return array_map(fn($ep) => $ep['season'] . 'x' . $ep['number'], $added);
    }

    // tvdb_continuations

    public function testContinuationAddsHigherNumbersOfTheLastSeason(): void
    {
        $base = [self::base(2, 1, '2026-07-01'), self::base(2, 2, '2026-07-08')];
        $added = tvdb_continuations($base, [self::tvdb(2, 3, '2026-07-15'), self::tvdb(2, 4, '2026-07-22')]);
        $this->assertSame(['2x3', '2x4'], self::keys($added));
    }

    public function testContinuationAddsRowsWithANullImdbId(): void
    {
        $added = tvdb_continuations([self::base(1, 1, '2026-07-01')], [self::tvdb(1, 2, '2026-07-08')]);
        $this->assertCount(1, $added);
        $this->assertArrayHasKey('imdb_id', $added[0]);
        $this->assertNull($added[0]['imdb_id']);
        $this->assertSame('2026-07-08', $added[0]['airdate']);
    }

    public function testContinuationOpensTheNextSeasonWhenDatedAfterTheBaseEnds(): void
    {
        $base = [self::base(1, 1, '2026-03-01'), self::base(1, 2, '2026-03-08')];
        $added = tvdb_continuations($base, [self::tvdb(2, 1, '2026-09-01'), self::tvdb(2, 2, '2026-09-08')]);
        $this->assertSame(['2x1', '2x2'], self::keys($added));
    }

    public function testNextSeasonDatedOnOrBeforeTheBaseEndIsSkipped(): void
    {
        $base = [self::base(1, 1, '2026-03-01'), self::base(1, 2, '2026-03-08')];
        $added = tvdb_continuations($base, [self::tvdb(2, 1, '2026-03-08'), self::tvdb(2, 2, '2026-03-01')]);
        $this->assertSame([], $added);
    }

    public function testContinuationDatedBeforeTheBasesLastEpisodeIsSkipped(): void
    {
        $base = [self::base(2, 1, '2026-07-01'), self::base(2, 2, '2026-07-08')];
        $added = tvdb_continuations($base, [self::tvdb(2, 3, '2026-07-07')]);
        $this->assertSame([], $added);
    }

    public function testContinuationDatedTheSameDayAsTheLastEpisodeIsKept(): void
    {
        $base = [self::base(2, 1, '2026-07-01'), self::base(2, 2, '2026-07-08')];
        $added = tvdb_continuations($base, [self::tvdb(2, 3, '2026-07-08')]);
        $this->assertSame(['2x3'], self::keys($added));
    }

    public function testEpisodesTheBaseAlreadyHasAreNeverAdded(): void
    {
        $base = [self::base(2, 1, '2026-07-01'), self::base(2, 3, '2026-07-15')];
        // 2x2 is a gap; 2x3 exists already.
        $added = tvdb_continuations($base, [self::tvdb(2, 2, '2026-07-08'), self::tvdb(2, 3, '2026-07-15')]);
        $this->assertSame([], $added);
    }

    public function testOlderSeasonsAndGapsAreNeverAdded(): void
    {
        $base = [self::base(1, 1, '2025-03-01'), self::base(3, 1, '2026-07-01')];
        $added = tvdb_continuations($base, [
            self::tvdb(1, 2, '2026-08-01'),
            self::tvdb(2, 1, '2026-08-01'),
            self::tvdb(5, 1, '2026-08-01'),
        ]);
        $this->assertSame([], $added);
    }

    public function testSpecialsInTheBaseAreIgnored(): void
    {
        $base = [
            self::base(0, 1, '2030-01-01'),
            self::base(0, 99, '2030-02-01'),
            self::base(1, 1, '2026-03-01'),
        ];
        $added = tvdb_continuations($base, [self::tvdb(1, 2, '2026-03-08'), self::tvdb(2, 1, '2026-09-01')]);
        $this->assertSame(['1x2', '2x1'], self::keys($added));
    }

    public function testBaseWithoutAirdatesStillContinues(): void
    {
        $added = tvdb_continuations([self::base(1, 1, null)], [self::tvdb(1, 2, '2026-03-08')]);
        $this->assertSame(['1x2'], self::keys($added));
    }

    public function testNothingToContinueWhenTvdbListsNothing(): void
    {
        $this->assertSame([], tvdb_continuations([self::base(1, 1, '2026-03-01')], []));
    }

    // same_numbering and within_a_day

    public function testSameNumberingKeepsEpisodesNumberedAlikeWithinADay(): void
    {
        $base = [
            self::base(1, 1, '2026-03-01'),
            self::base(1, 2, '2026-03-08'),
            self::base(1, 3, '2026-03-15'),
            self::base(1, 4, null),
        ];
        $tvdb = [
            self::tvdb(1, 1, '2026-03-01'),
            self::tvdb(1, 2, '2026-03-09'),
            self::tvdb(1, 3, '2026-03-20'),
            self::tvdb(1, 4, '2026-03-22'),
            self::tvdb(1, 5, '2026-03-29'),
        ];
        $this->assertSame([[1, 1], [1, 2]], same_numbering($base, $tvdb));
    }

    /** @return array<string, array{?string, ?string, bool}> */
    public static function dayPairs(): array
    {
        return [
            'same day' => ['2026-07-15', '2026-07-15', true],
            'one day later' => ['2026-07-15', '2026-07-16', true],
            'one day earlier' => ['2026-07-15', '2026-07-14', true],
            'two days apart' => ['2026-07-15', '2026-07-17', false],
            'a UTC stamp counts by its date' => ['2026-07-15 23:30:00', '2026-07-16', true],
            'across a month end' => ['2026-07-31', '2026-08-01', true],
            'null first' => [null, '2026-07-15', false],
            'null second' => ['2026-07-15', null, false],
            'both null' => [null, null, false],
        ];
    }

    #[DataProvider('dayPairs')]
    public function testWithinADay(?string $a, ?string $b, bool $expected): void
    {
        $this->assertSame($expected, within_a_day($a, $b));
    }

    // early_release

    /** @return array<string, array{?string, ?string, bool}> */
    public static function earlyReleases(): array
    {
        return [
            'one day earlier' => ['2026-07-14', '2026-07-15', true],
            'two days earlier' => ['2026-07-13', '2026-07-15', true],
            'same day' => ['2026-07-15', '2026-07-15', false],
            'three days earlier' => ['2026-07-12', '2026-07-15', false],
            'a week earlier' => ['2026-07-08', '2026-07-15', false],
            'later instead' => ['2026-07-16', '2026-07-15', false],
            'across a month end' => ['2026-07-31', '2026-08-02', true],
            'null early' => [null, '2026-07-15', false],
            'null date' => ['2026-07-14', null, false],
        ];
    }

    #[DataProvider('earlyReleases')]
    public function testEarlyRelease(?string $early, ?string $date, bool $expected): void
    {
        $this->assertSame($expected, early_release($early, $date));
    }

    // Time stamps

    /** @return array<string, array{array<string, mixed>, ?string}> */
    public static function tvmazeEpisodes(): array
    {
        $stamp = '2026-07-15T20:00:00-04:00';
        return [
            'with an airtime' => [['airtime' => '20:00', 'airstamp' => $stamp], '2026-07-16 00:00:00'],
            'empty airtime' => [['airtime' => '', 'airstamp' => $stamp], null],
            'blank airtime' => [['airtime' => '  ', 'airstamp' => $stamp], null],
            'missing airtime' => [['airstamp' => $stamp], null],
            'null airtime' => [['airtime' => null, 'airstamp' => $stamp], null],
            'airtime but no airstamp' => [['airtime' => '20:00'], null],
            'airtime but empty airstamp' => [['airtime' => '20:00', 'airstamp' => ''], null],
        ];
    }

    /** @param array<string, mixed> $episode */
    #[DataProvider('tvmazeEpisodes')]
    public function testTvmazeStampNeedsAnAirtime(array $episode, ?string $expected): void
    {
        $this->assertSame($expected, tvmaze_stamp($episode));
    }

    /** @return array<string, array{?string, ?string}> */
    public static function isoStamps(): array
    {
        return [
            'utc' => ['2026-07-15T20:00:00+00:00', '2026-07-15 20:00:00'],
            'with an offset' => ['2026-07-15T20:00:00-04:00', '2026-07-16 00:00:00'],
            'positive offset' => ['2026-07-15T01:30:00+03:00', '2026-07-14 22:30:00'],
            'Z suffix' => ['2026-07-15T20:00:00Z', '2026-07-15 20:00:00'],
            'null' => [null, null],
            'empty' => ['', null],
            'garbage' => ['not a date', null],
        ];
    }

    #[DataProvider('isoStamps')]
    public function testUtcStamp(?string $iso, ?string $expected): void
    {
        $this->assertSame($expected, utc_stamp($iso));
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function localAirTimes(): array
    {
        return [
            'US Eastern in summer (EDT)' => ['2026-07-15', '21:00', 'America/New_York', '2026-07-16 01:00:00'],
            'US Eastern in winter (EST)' => ['2026-01-15', '21:00', 'America/New_York', '2026-01-16 02:00:00'],
            'US Eastern daytime, same UTC day' => ['2026-07-15', '10:30', 'America/New_York', '2026-07-15 14:30:00'],
            'US Eastern crossing midnight UTC, summer' => ['2026-07-15', '20:00', 'America/New_York', '2026-07-16 00:00:00'],
            'US Eastern crossing midnight UTC, winter' => ['2026-01-15', '19:00', 'America/New_York', '2026-01-16 00:00:00'],
            'crossing a month end' => ['2026-07-31', '22:00', 'America/New_York', '2026-08-01 02:00:00'],
            'Istanbul, UTC+3 all year' => ['2026-07-15', '21:00', 'Europe/Istanbul', '2026-07-15 18:00:00'],
            'Istanbul early morning goes to the day before' => ['2026-07-15', '01:00', 'Europe/Istanbul', '2026-07-14 22:00:00'],
        ];
    }

    #[DataProvider('localAirTimes')]
    public function testTvdbStampConvertsLocalAirTimeToUtc(string $airdate, string $time, string $tz, string $expected): void
    {
        $this->assertSame($expected, tvdb_stamp($airdate, ['time' => $time, 'tz' => $tz]));
    }

    // title_key

    public function testProvisionalIdBecomesTheLinkedImdbIdWhenConfirmed(): void
    {
        $this->assertSame('tt1234567', title_key('tm123', 'tt1234567', fn() => true));
        $this->assertSame('tt1234567', title_key('mz45', 'tt1234567', fn() => true));
    }

    public function testProvisionalIdStaysWhenTheProviderFindsAnotherRecord(): void
    {
        $this->assertSame('tm123', title_key('tm123', 'tt1234567', fn() => false));
    }

    public function testCallbackReceivesTheLinkedId(): void
    {
        $seen = [];
        title_key('tm123', 'tt1234567', function ($linked) use (&$seen) {
            $seen[] = $linked;
            return true;
        });
        $this->assertSame(['tt1234567'], $seen);
    }

    /** @return array<string, array{?string}> */
    public static function invalidLinks(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'provisional id' => ['tm999'],
            'too short' => ['tt123'],
            'no prefix' => ['1234567'],
            'trailing junk' => ['tt1234567x'],
        ];
    }

    #[DataProvider('invalidLinks')]
    public function testInvalidLinkIsNeverTrustedNorAsked(?string $linked): void
    {
        $asked = false;
        $key = title_key('tm123', $linked, function () use (&$asked) {
            $asked = true;
            return true;
        });
        $this->assertSame('tm123', $key);
        $this->assertFalse($asked, 'the callback must not run for an invalid link');
    }

    public function testImdbIdStaysItself(): void
    {
        $asked = false;
        $key = title_key('tt7654321', 'tt1234567', function () use (&$asked) {
            $asked = true;
            return true;
        });
        $this->assertSame('tt7654321', $key);
        $this->assertFalse($asked);
    }

    // Turkish episode names

    /** @return array<string, array{string, string, string}> */
    public static function turkishNames(): array
    {
        return [
            'a real translation' => ['Pilot Bölümü', 'Pilot', 'Pilot Bölümü'],
            'same as English' => ['Pilot', 'Pilot', ''],
            'both empty' => ['', '', ''],
            'placeholder with a real English name' => ['3. Bölüm', 'The Heist', ''],
            'placeholder with Episode N' => ['3. Bölüm', 'Episode 3', '3. Bölüm'],
            'placeholder with lowercase episode N' => ['3. Bölüm', 'episode 3', '3. Bölüm'],
            'placeholder with an empty English name' => ['3. Bölüm', '', '3. Bölüm'],
            'placeholder without a space' => ['12.Bölüm', 'The Heist', ''],
            'trimmed' => ['  Yeni Başlangıç  ', 'A New Start', 'Yeni Başlangıç'],
            'trimmed placeholder' => ['  3. Bölüm ', 'The Heist', ''],
            'trimmed equal to English' => [' Pilot ', 'Pilot', ''],
            'a name that merely contains Bölüm' => ['Son Bölüm', 'The End', 'Son Bölüm'],
        ];
    }

    #[DataProvider('turkishNames')]
    public function testTurkishEpisodeName(string $turkish, string $english, string $expected): void
    {
        $this->assertSame($expected, turkish_episode_name($turkish, $english));
    }

    public function testTurkishEpisodeNameIsCutAt255Characters(): void
    {
        $long = str_repeat('Ş', 300);
        $name = turkish_episode_name($long, 'Short');
        $this->assertSame(255, mb_strlen($name));
        $this->assertSame(str_repeat('Ş', 255), $name);
    }

    public function testTmdbTurkishNamesAreKeyedBySeasonAndNumber(): void
    {
        $names = tmdb_turkish_names([
            ['episodes' => [
                ['season_number' => 1, 'episode_number' => 1, 'name' => ' Başlangıç '],
                ['season_number' => 1, 'episode_number' => 2, 'name' => ''],
                ['season_number' => 1, 'episode_number' => 3],
            ]],
            ['episodes' => [['season_number' => 2, 'episode_number' => 1, 'name' => 'Dönüş']]],
        ]);
        $this->assertSame(['1-1' => 'Başlangıç', '1-2' => '', '1-3' => '', '2-1' => 'Dönüş'], $names);
    }

    public function testTmdbTurkishNamesSkipSeasonsThatFailed(): void
    {
        $names = tmdb_turkish_names([
            null,
            ['episodes' => [['season_number' => 2, 'episode_number' => 1, 'name' => 'Dönüş']]],
            [],
        ]);
        $this->assertSame(['2-1' => 'Dönüş'], $names);
    }

    public function testTmdbTurkishNamesClampNegativeNumbers(): void
    {
        $names = tmdb_turkish_names([['episodes' => [['season_number' => -1, 'episode_number' => -5, 'name' => 'X']]]]);
        $this->assertSame(['0-0' => 'X'], $names);
    }
}
