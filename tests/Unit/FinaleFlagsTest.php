<?php

namespace Tvtrack\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FinaleFlagsTest extends TestCase
{
    /** @return array<string, array{?string, ?string}> */
    public static function tmdbEpisodeTypes(): array
    {
        return [
            'not stated' => [null, null],
            'finale' => ['finale', 'season'],
            'mid season' => ['mid_season', 'midseason'],
            'standard' => ['standard', ''],
            'premiere type' => ['premiere', ''],
            'empty string' => ['', ''],
            'unknown word' => ['something_new', ''],
        ];
    }

    #[DataProvider('tmdbEpisodeTypes')]
    public function testTmdbEpisodeTypeBecomesFinaleFlag(?string $type, ?string $expected): void
    {
        $this->assertSame($expected, tmdb_finale($type));
    }

    /** @return array{season: int, number: int, airdate: string, finale: ?string} */
    private static function ep(int $season, int $number, string $airdate, ?string $finale = null): array
    {
        return ['season' => $season, 'number' => $number, 'airdate' => $airdate, 'finale' => $finale];
    }

    public function testTvdbFlagAppliesOnFullMatch(): void
    {
        $episodes = [self::ep(2, 8, '2026-07-15', '')];
        tvdb_merge_finales($episodes, [self::ep(2, 8, '2026-07-15', 'season')]);
        $this->assertSame('season', $episodes[0]['finale']);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function mismatchingTvdbEpisodes(): array
    {
        return [
            'other season' => [['season' => 3, 'number' => 8, 'airdate' => '2026-07-15', 'finale' => 'season']],
            'other number' => [['season' => 2, 'number' => 9, 'airdate' => '2026-07-15', 'finale' => 'season']],
            'other airdate' => [['season' => 2, 'number' => 8, 'airdate' => '2026-07-16', 'finale' => 'season']],
        ];
    }

    /** @param array<string, mixed> $tvdb */
    #[DataProvider('mismatchingTvdbEpisodes')]
    public function testTvdbFlagIsIgnoredWhenSeasonNumberOrAirdateDiffer(array $tvdb): void
    {
        $episodes = [self::ep(2, 8, '2026-07-15', '')];
        tvdb_merge_finales($episodes, [$tvdb]);
        $this->assertSame('', $episodes[0]['finale']);
    }

    public function testEmptyTvdbFlagNeverOverridesTheBaseFlag(): void
    {
        $episodes = [self::ep(2, 8, '2026-07-15', 'season'), self::ep(2, 9, '2026-07-22', null)];
        tvdb_merge_finales($episodes, [
            self::ep(2, 8, '2026-07-15', ''),
            self::ep(2, 9, '2026-07-22', ''),
        ]);
        $this->assertSame('season', $episodes[0]['finale']);
        $this->assertNull($episodes[1]['finale']);
    }

    public function testTvdbFlagFillsAnUnknownBaseFlag(): void
    {
        $episodes = [self::ep(1, 10, '2026-03-01', null)];
        tvdb_merge_finales($episodes, [self::ep(1, 10, '2026-03-01', 'midseason')]);
        $this->assertSame('midseason', $episodes[0]['finale']);
    }

    public function testSeriesFlagBeforeTheLastSeasonBecomesSeason(): void
    {
        $episodes = [self::ep(1, 10, '2025-03-01', ''), self::ep(2, 10, '2026-03-01', '')];
        tvdb_merge_finales($episodes, [self::ep(1, 10, '2025-03-01', 'series')]);
        $this->assertSame('season', $episodes[0]['finale']);
    }

    public function testSeriesFlagOnTheLastSeasonStays(): void
    {
        $episodes = [self::ep(1, 10, '2025-03-01', ''), self::ep(2, 10, '2026-03-01', '')];
        tvdb_merge_finales($episodes, [self::ep(2, 10, '2026-03-01', 'series')]);
        $this->assertSame('series', $episodes[1]['finale']);
    }

    public function testSeriesFlagBeforeASeasonOnlyTheTvdbListKnowsBecomesSeason(): void
    {
        $episodes = [self::ep(1, 10, '2025-03-01', '')];
        tvdb_merge_finales($episodes, [
            self::ep(1, 10, '2025-03-01', 'series'),
            self::ep(2, 1, '2026-03-01', ''),
        ]);
        $this->assertSame('season', $episodes[0]['finale']);
    }

    public function testSeriesFlagOnTheLastSeasonStaysWhenOnlyBaseKnowsEarlierOnes(): void
    {
        $episodes = [self::ep(1, 10, '2025-03-01', ''), self::ep(2, 10, '2026-03-01', '')];
        tvdb_merge_finales($episodes, [self::ep(2, 10, '2026-03-01', 'series')]);
        $this->assertSame('', $episodes[0]['finale']);
        $this->assertSame('series', $episodes[1]['finale']);
    }

    public function testSeasonFlagsAreNotAffectedByLaterSeasons(): void
    {
        $episodes = [self::ep(1, 10, '2025-03-01', ''), self::ep(2, 1, '2026-03-01', '')];
        tvdb_merge_finales($episodes, [self::ep(1, 10, '2025-03-01', 'midseason')]);
        $this->assertSame('midseason', $episodes[0]['finale']);
    }

    public function testMergingWithNoTvdbEpisodesChangesNothing(): void
    {
        $episodes = [self::ep(1, 1, '2025-03-01', 'season')];
        tvdb_merge_finales($episodes, []);
        $this->assertSame('season', $episodes[0]['finale']);
    }

    /** @return array<string, array{int, int, ?string, string}> */
    public static function badgeCases(): array
    {
        return [
            'series finale' => [3, 10, 'series', 'ep-badge-finale'],
            'season finale' => [3, 10, 'season', 'ep-badge-finale'],
            'midseason finale' => [3, 5, 'midseason', 'ep-badge-finale'],
            'series premiere' => [1, 1, null, 'ep-badge-premiere'],
            'season premiere' => [4, 1, '', 'ep-badge-premiere'],
        ];
    }

    #[DataProvider('badgeCases')]
    public function testBadgeClass(int $season, int $number, ?string $finale, string $class): void
    {
        $this->assertStringContainsString('class="ep-badge ' . $class . '"', episode_badge_html($season, $number, $finale));
    }

    /** @return array<string, array{int, int, ?string, string}> */
    public static function badgeTexts(): array
    {
        return [
            'series finale' => [3, 10, 'series', 'Series finale'],
            'season finale' => [3, 10, 'season', 'Season finale'],
            'midseason finale' => [3, 5, 'midseason', 'Mid-season finale'],
            'series premiere' => [1, 1, null, 'Series premiere'],
            'season premiere' => [2, 1, null, 'Season premiere'],
        ];
    }

    #[DataProvider('badgeTexts')]
    public function testBadgeText(int $season, int $number, ?string $finale, string $text): void
    {
        $this->assertStringContainsString('>' . $text . '</span>', episode_badge_html($season, $number, $finale));
    }

    public function testFinaleFlagWinsOverEpisodeOne(): void
    {
        $html = episode_badge_html(1, 1, 'series');
        $this->assertStringContainsString('ep-badge-finale', $html);
        $this->assertStringNotContainsString('premiere', $html);
    }

    /** @return array<string, array{int, int, ?string}> */
    public static function noBadge(): array
    {
        return [
            'regular episode' => [2, 5, null],
            'regular episode with empty flag' => [2, 5, ''],
            'special' => [0, 1, null],
            'special with finale flag' => [0, 3, 'season'],
        ];
    }

    #[DataProvider('noBadge')]
    public function testNoBadge(int $season, int $number, ?string $finale): void
    {
        $this->assertSame('', episode_badge_html($season, $number, $finale));
    }
}
