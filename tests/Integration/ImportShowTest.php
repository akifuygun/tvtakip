<?php

namespace Tvtrack\Tests\Integration;

use Tvtrack\Tests\Support\Clock;
use Tvtrack\Tests\Support\DatabaseTestCase;

/**
 * import_show(): how what TMDB, TVmaze and TheTVDB say about a show ends up
 * in the shared cache — and what a later import may and may not change.
 */
final class ImportShowTest extends DatabaseTestCase
{
    public function testTmdbBaseWithTvmazeAirTimesAndEpisodeIds(): void
    {
        $this->providers
            ->tmdbShow(100, 'tt1000001', [
                'name' => 'The Show',
                'status' => 'Returning Series',
                'networks' => [['name' => 'HBO'], ['name' => 'Max']],
                'translations' => ['translations' => [['iso_639_1' => 'tr', 'data' => ['overview' => 'Türkçe özet.']]]],
            ], [
                ['season' => 1, 'number' => 1, 'name' => 'Pilot', 'air_date' => Clock::day(-14), 'name_tr' => 'Başlangıç', 'imdb_id' => 'tt2000001'],
                ['season' => 1, 'number' => 2, 'name' => 'Second', 'air_date' => Clock::day(-7), 'name_tr' => '2. Bölüm', 'imdb_id' => ''],
                ['season' => 1, 'number' => 3, 'name' => 'Third', 'air_date' => Clock::day(7), 'episode_type' => 'finale', 'imdb_id' => 'tt2000003'],
            ])
            ->tvmazeShow(70, 'tt1000001', [], [
                ['season' => 1, 'number' => 1, 'airdate' => Clock::day(-14), 'airtime' => '21:00', 'airstamp' => Clock::iso(Clock::day(-13) . ' 02:00:00')],
                ['season' => 1, 'number' => 2, 'airdate' => Clock::day(-7)],
                ['season' => 1, 'number' => 3, 'airdate' => Clock::day(7), 'airtime' => '21:00', 'airstamp' => Clock::iso(Clock::day(8) . ' 02:00:00')],
            ])
            ->tvdbUnknown('tt1000001');

        $this->assertSame('tt1000001', import_show($this->pdo, 'tt1000001', background: true));

        $show = $this->rows('SELECT * FROM shows')[0];
        $this->assertSame('The Show', $show['name']);
        $this->assertSame('running', $show['status']);
        $this->assertSame('HBO, Max', $show['network']);
        $this->assertSame('Türkçe özet.', $show['overview_tr']);
        $this->assertNotNull($show['synced_at']);
        $this->assertSame(0, (int) $show['ids_pending']);

        $eps = $this->episodes('tt1000001');
        $this->assertSame(['1-1', '1-2', '1-3'], array_keys($eps));
        $this->assertSame(Clock::day(-13) . ' 02:00:00', $eps['1-1']['airstamp']);
        $this->assertNull($eps['1-2']['airstamp'], 'TVmaze has no time for it: its noon placeholder is no air time');
        $this->assertSame('tt2000001', $eps['1-1']['imdb_id']);
        $this->assertNull($eps['1-2']['imdb_id']);
        $this->assertNotNull($eps['1-2']['imdb_checked_at'], 'TMDB answered: no id');
        $this->assertSame('Başlangıç', $eps['1-1']['name_tr']);
        $this->assertSame('', $eps['1-2']['name_tr'], "TMDB's numbered placeholder is no Turkish name");
        $this->assertSame('', $eps['1-3']['name_tr'], 'TMDB answered in English: no Turkish name');
        $this->assertSame('', $eps['1-1']['finale']);
        $this->assertSame('season', $eps['1-3']['finale']);
    }

    public function testTheTvdbAddsFinalesAirTimesAndAnnouncedEpisodes(): void
    {
        $this->providers
            ->tmdbShow(200, 'tt1000002', [], [
                ['season' => 1, 'number' => 1, 'air_date' => Clock::day(-40)],
                ['season' => 1, 'number' => 2, 'air_date' => Clock::day(-33)],
                ['season' => 2, 'number' => 1, 'air_date' => Clock::day(-5)],
                ['season' => 2, 'number' => 2, 'air_date' => Clock::day(2)],
            ], 600)
            // A streaming show: TVmaze knows the days, not the times.
            ->tvmazeShow(71, 'tt1000002', [], [
                ['season' => 1, 'number' => 1, 'airdate' => Clock::day(-40)],
                ['season' => 1, 'number' => 2, 'airdate' => Clock::day(-33)],
                ['season' => 2, 'number' => 1, 'airdate' => Clock::day(-5)],
                ['season' => 2, 'number' => 2, 'airdate' => Clock::day(2)],
            ])
            ->tvdbShow(600, 'tt1000002', ['airsTime' => '21:00', 'airsDays' => ['monday' => true], 'originalCountry' => 'usa'], [
                ['seasonNumber' => 1, 'number' => 1, 'aired' => Clock::day(-40)],
                // Flagged before the show was renewed, and never corrected.
                ['seasonNumber' => 1, 'number' => 2, 'aired' => Clock::day(-33), 'finaleType' => 'series'],
                // Dated a day apart from TMDB's: not known to be the same episode.
                ['seasonNumber' => 2, 'number' => 1, 'aired' => Clock::day(-4), 'finaleType' => 'midseason'],
                ['seasonNumber' => 2, 'number' => 2, 'aired' => Clock::day(2), 'finaleType' => 'midseason'],
                ['seasonNumber' => 2, 'number' => 3, 'aired' => Clock::day(9), 'name' => 'Announced'],
                ['seasonNumber' => 3, 'number' => 1, 'aired' => Clock::day(30), 'finaleType' => 'series'],
                // In an earlier season than TMDB's last: numbered differently, never added.
                ['seasonNumber' => 1, 'number' => 3, 'aired' => Clock::day(-26)],
            ]);

        import_show($this->pdo, 'tt1000002', background: true);

        $eps = $this->episodes('tt1000002');
        $this->assertSame(['1-1', '1-2', '2-1', '2-2', '2-3', '3-1'], array_keys($eps));
        $this->assertSame('season', $eps['1-2']['finale'], 'a series finale with a season after it');
        $this->assertSame('', $eps['2-1']['finale']);
        $this->assertSame('midseason', $eps['2-2']['finale']);
        $this->assertSame('series', $eps['3-1']['finale']);
        $this->assertSame('Announced', $eps['2-3']['name']);
        $this->assertNull($eps['2-3']['imdb_id']);
        foreach ($eps as $key => $ep) {
            $this->assertSame(self::newYork($ep['airdate'], '21:00'), $ep['airstamp'], "$key at TheTVDB's air time");
        }
    }

    public function testTheTvdbAddsOnlyEpisodesStillToAir(): void
    {
        $this->providers
            ->tmdbShow(201, 'tt1000011', [], [['season' => 1, 'number' => 1, 'air_date' => Clock::day(-10)]])
            ->tvdbShow(602, 'tt1000011', [], [
                ['seasonNumber' => 1, 'number' => 1, 'aired' => Clock::day(-10)],
                // Aired ones TMDB doesn't list are often another show TheTVDB
                // files as a later season (a spin-off).
                ['seasonNumber' => 2, 'number' => 1, 'aired' => Clock::day(-3)],
                ['seasonNumber' => 2, 'number' => 2, 'aired' => Clock::day(4)],
            ]);
        import_show($this->pdo, 'tt1000011', background: true);
        $this->assertSame(['1-1', '2-2'], array_keys($this->episodes('tt1000011')));
    }

    public function testAnEarlyStreamingReleaseTakesItsDayAndNoTime(): void
    {
        $tmdb = [
            ['season' => 1, 'number' => 1, 'air_date' => Clock::day(-3)],
            ['season' => 1, 'number' => 2, 'air_date' => Clock::day(4)],
        ];
        $tvdb = ['airsTime' => '21:00', 'airsDays' => ['sunday' => true]];
        $this->providers
            ->tmdbShow(300, 'tt1000003', [], $tmdb)
            ->tvmazeShow(72, 'tt1000003', [], [
                ['season' => 1, 'number' => 1, 'airdate' => Clock::day(-3), 'airtime' => '21:00', 'airstamp' => Clock::iso(Clock::day(-2) . ' 01:00:00')],
                ['season' => 1, 'number' => 2, 'airdate' => Clock::day(4)],
            ])
            ->tvdbShow(601, 'tt1000003', $tvdb);
        import_show($this->pdo, 'tt1000003', background: true);
        $this->assertSame(Clock::day(-2) . ' 01:00:00', $this->episodes('tt1000003')['1-1']['airstamp']);

        // TVmaze lists the streaming release now: no time, two days before
        // the broadcast. Three days before is too far to be one.
        $this->newProviders()
            ->tmdbShow(300, 'tt1000003', [], $tmdb)
            ->tvmazeShow(72, 'tt1000003', [], [
                ['season' => 1, 'number' => 1, 'airdate' => Clock::day(-5)],
                ['season' => 1, 'number' => 2, 'airdate' => Clock::day(1)],
            ])
            ->tvdbShow(601, 'tt1000003', $tvdb);
        import_show($this->pdo, 'tt1000003', background: true);

        $eps = $this->episodes('tt1000003');
        $this->assertSame(Clock::day(-5), $eps['1-1']['airdate']);
        $this->assertNull($eps['1-1']['airstamp'], "neither the broadcast's time nor TheTVDB's");
        $this->assertSame(Clock::day(4), $eps['1-2']['airdate']);
        $this->assertSame(self::newYork(Clock::day(4), '21:00'), $eps['1-2']['airstamp']);
    }

    public function testALaterImportKeepsWhatItsProviderDoesNotKnow(): void
    {
        $this->providers
            ->tmdbShow(400, 'tt1000004', [
                'translations' => ['translations' => [['iso_639_1' => 'tr', 'data' => ['overview' => 'Türkçe özet.']]]],
            ], [
                ['season' => 1, 'number' => 1, 'name' => 'Pilot', 'air_date' => Clock::day(-20), 'name_tr' => 'Açılış', 'imdb_id' => 'tt4000001'],
                ['season' => 1, 'number' => 2, 'name' => 'The End', 'air_date' => Clock::day(-13), 'episode_type' => 'finale', 'name_tr' => 'Son', 'imdb_id' => 'tt4000002'],
            ])
            ->tvdbUnknown('tt1000004');
        import_show($this->pdo, 'tt1000004', background: true);

        // TMDB fails: the show comes from TVmaze, which has no finale flags,
        // Turkish names, Turkish description or episode ids.
        $this->newProviders()
            ->tvmazeShow(73, 'tt1000004', ['status' => 'Ended'], [
                ['season' => 1, 'number' => 1, 'name' => 'Pilot', 'airdate' => Clock::day(-20)],
                ['season' => 1, 'number' => 2, 'name' => 'The End', 'airdate' => Clock::day(-13)],
            ])
            ->tvdbUnknown('tt1000004');
        import_show($this->pdo, 'tt1000004', background: true);

        $show = $this->rows('SELECT * FROM shows')[0];
        $this->assertSame('ended', $show['status']);
        $this->assertSame('Türkçe özet.', $show['overview_tr']);
        $eps = $this->episodes('tt1000004');
        $this->assertSame(['Açılış', 'Son'], array_column($eps, 'name_tr'));
        $this->assertSame(['tt4000001', 'tt4000002'], array_column($eps, 'imdb_id'));
        $this->assertSame(['', 'season'], array_column($eps, 'finale'));

        // TMDB again: the last episode is a regular one now, and has no
        // Turkish name anymore. Saying so clears what was stored.
        $this->newProviders()
            ->tmdbShow(400, 'tt1000004', [], [
                ['season' => 1, 'number' => 1, 'name' => 'Pilot', 'air_date' => Clock::day(-20), 'name_tr' => 'Açılış'],
                ['season' => 1, 'number' => 2, 'name' => 'The End', 'air_date' => Clock::day(-13)],
            ])
            ->tvdbUnknown('tt1000004');
        import_show($this->pdo, 'tt1000004', background: true);

        $eps = $this->episodes('tt1000004');
        $this->assertSame(['Açılış', ''], array_column($eps, 'name_tr'));
        $this->assertSame(['', ''], array_column($eps, 'finale'));
        $this->assertSame(['tt4000001', 'tt4000002'], array_column($eps, 'imdb_id'), 'ids are not asked again');
        $this->assertSame([], $this->providers->requested('/episode/'));
    }

    public function testOnlyTheManualRefreshDropsAiredEpisodesAndNoImportDropsWatchedOnes(): void
    {
        $episodes = [
            ['season' => 1, 'number' => 1, 'air_date' => Clock::day(-10)],
            ['season' => 1, 'number' => 2, 'air_date' => Clock::day(-7)],
            ['season' => 1, 'number' => 3, 'air_date' => Clock::day(-3)],
            ['season' => 1, 'number' => 4, 'air_date' => Clock::day(5)],
            ['season' => 1, 'number' => 5, 'air_date' => Clock::day(12)],
        ];
        $this->providers->tmdbShow(500, 'tt1000005', [], $episodes)->tvdbUnknown('tt1000005');
        import_show($this->pdo, 'tt1000005', background: true);
        $viewer = $this->user();
        $this->watch($viewer, 'tt1000005', '1-1');

        // The providers list the third episode alone from now on. While
        // TheTVDB can't be asked, its announcements may be what is missing:
        // nothing goes.
        $this->newProviders()->tmdbShow(500, 'tt1000005', [], [$episodes[2]]);
        import_show($this->pdo, 'tt1000005', background: true);
        $this->assertSame(['1-1', '1-2', '1-3', '1-4', '1-5'], array_keys($this->episodes('tt1000005')));

        // TheTVDB answers: the unlisted episodes still to air go.
        $this->providers->tvdbUnknown('tt1000005');
        import_show($this->pdo, 'tt1000005', background: true);
        $this->assertSame(['1-1', '1-2', '1-3'], array_keys($this->episodes('tt1000005')));

        // The manual Refresh drops the aired ones too — never a watched one.
        import_show($this->pdo, 'tt1000005', prune: true, background: true);
        $this->assertSame(['1-1', '1-3'], array_keys($this->episodes('tt1000005')));
        $this->assertSame(['1-1'], $this->watched($viewer));
    }

    public function testAProvisionalShowMovesToItsImdbIdWithEverythingOnIt(): void
    {
        $episodes = [
            ['season' => 1, 'number' => 1, 'air_date' => Clock::day(-10)],
            ['season' => 1, 'number' => 2, 'air_date' => Clock::day(-3)],
        ];
        $this->providers->tmdbShow(600, null, [], $episodes);
        $this->assertSame('tm600', import_show($this->pdo, 'tm600', background: true));
        $viewer = $this->user();
        $this->watch($viewer, 'tm600', '1-1');
        $rows = array_column($this->episodes('tm600'), 'id');

        // TMDB links an IMDB id now — and finds this very show by it.
        $this->newProviders()->tmdbShow(600, 'tt1000006', [], $episodes)->tvdbUnknown('tt1000006');
        $this->assertSame('tt1000006', import_show($this->pdo, 'tm600', background: true));

        $this->assertSame(['tt1000006'], array_column($this->rows('SELECT imdb_id FROM shows'), 'imdb_id'));
        $this->assertSame($rows, array_column($this->episodes('tt1000006'), 'id'), 'the same episode rows, moved');
        $this->assertSame('tt1000006', $this->value('SELECT show_imdb_id FROM user_shows WHERE user_id = ?', [$viewer]));
        $this->assertSame(['1-1'], $this->watched($viewer));
    }

    public function testAProvisionalShowStaysWhenItsImdbIdLeadsToAnotherRecord(): void
    {
        $episodes = [['season' => 1, 'number' => 1, 'air_date' => Clock::day(-10)]];
        $this->providers->tmdbShow(601, null, [], $episodes);
        import_show($this->pdo, 'tm601', background: true);

        // TMDB links an IMDB id, but by that id it finds another show (a
        // duplicate entry): importing by it would bring that one's data.
        $this->newProviders()->tmdbShow(601, 'tt1000007', [], $episodes)->tmdbFinds('tt1000007', 999);
        $this->assertSame('tm601', import_show($this->pdo, 'tm601', background: true));
        $this->assertSame(['tm601'], array_column($this->rows('SELECT imdb_id FROM shows'), 'imdb_id'));
    }

    public function testAShowCachedUnderBothIdsIsFoldedIntoTheImdbOne(): void
    {
        $episodes = [
            ['season' => 1, 'number' => 1, 'air_date' => Clock::day(-10)],
            ['season' => 1, 'number' => 2, 'air_date' => Clock::day(-3)],
        ];
        $this->providers->tmdbShow(602, null, [], $episodes);
        import_show($this->pdo, 'tm602', background: true);
        $viewer = $this->user();
        $this->watch($viewer, 'tm602', '1-1', '1-2');

        // Meanwhile the show came in by its IMDB id as well, from TVmaze.
        $this->newProviders()
            ->tvmazeShow(74, 'tt1000008', [], [
                ['season' => 1, 'number' => 1, 'airdate' => Clock::day(-10)],
                ['season' => 1, 'number' => 2, 'airdate' => Clock::day(-3)],
            ])
            ->tvdbUnknown('tt1000008');
        import_show($this->pdo, 'tt1000008', background: true);
        $other = $this->user('other@example.com');
        $this->watch($other, 'tt1000008', '1-2');

        // TMDB links that IMDB id now.
        $this->newProviders()->tmdbShow(602, 'tt1000008', [], $episodes)->tvdbUnknown('tt1000008');
        $this->assertSame('tt1000008', import_show($this->pdo, 'tt1000008', background: true));

        $this->assertSame(['tt1000008'], array_column($this->rows('SELECT imdb_id FROM shows'), 'imdb_id'));
        $this->assertSame('tt1000008', $this->value('SELECT show_imdb_id FROM user_shows WHERE user_id = ?', [$viewer]));
        $this->assertSame(['1-1', '1-2'], $this->watched($viewer));
        $this->assertSame(['1-2'], $this->watched($other));
    }

    public function testAWebImportLeavesManyEpisodeIdsToTheWorker(): void
    {
        $episodes = [];
        for ($n = 1; $n <= WEB_ID_LOOKUPS + 1; $n++) {
            $episodes[] = ['season' => 1, 'number' => $n, 'air_date' => Clock::day($n - 400), 'imdb_id' => sprintf('tt9%06d', $n)];
        }
        $this->providers->tmdbShow(700, 'tt1000009', [], $episodes)->tvdbUnknown('tt1000009');

        import_show($this->pdo, 'tt1000009');
        $this->assertSame(1, (int) $this->value('SELECT ids_pending FROM shows'));
        $this->assertSame([], $this->providers->requested('/episode/'));
        $this->assertCount(WEB_ID_LOOKUPS + 1, $this->episodes('tt1000009'), 'the episodes are stored all the same');

        // The cron worker's import looks them all up.
        import_show($this->pdo, 'tt1000009', background: true);
        $this->assertSame(0, (int) $this->value('SELECT ids_pending FROM shows'));
        $this->assertCount(WEB_ID_LOOKUPS + 1, $this->providers->requested('/episode/'));
        $this->assertSame(WEB_ID_LOOKUPS + 1, (int) $this->value('SELECT COUNT(imdb_id) FROM episodes'));
    }

    public function testTmdbIsAskedAgainOnlyAboutNewEpisodesItHadNoIdFor(): void
    {
        $this->providers
            ->tmdbShow(800, 'tt1000010', [], [
                ['season' => 1, 'number' => 1, 'air_date' => Clock::day(-100), 'imdb_id' => ''],
                ['season' => 1, 'number' => 2, 'air_date' => Clock::day(-50), 'imdb_id' => 'tt5000002'],
                ['season' => 1, 'number' => 3, 'air_date' => Clock::day(-5), 'imdb_id' => ''],
                ['season' => 1, 'number' => 4, 'air_date' => Clock::day(5), 'imdb_id' => ''],
            ])
            ->tvdbUnknown('tt1000010');
        import_show($this->pdo, 'tt1000010', background: true);
        $this->assertSame([1, 2, 3, 4], $this->askedAbout());

        $this->providers->requests = [];
        import_show($this->pdo, 'tt1000010', background: true);
        $this->assertSame([3, 4], $this->askedAbout(), 'aired in the last ' . ID_FRESH_DAYS . ' days, or still to air');
    }

    public function testACuratedShowTakesItsRegularEpisodesFromTheTvdb(): void
    {
        $this->assertContains('tt0983983', TVDB_EPISODE_SOURCE);
        $this->providers
            ->tmdbShow(900, 'tt0983983', [], [
                ['season' => 0, 'number' => 1, 'name' => 'Special', 'air_date' => Clock::day(-400), 'imdb_id' => 'tt6000001'],
                ['season' => 1, 'number' => 1, 'name' => 'One', 'air_date' => Clock::day(-300), 'name_tr' => 'Bir', 'imdb_id' => 'tt6000011'],
                // TMDB skips TheTVDB's second season: its season 2 is the third.
                ['season' => 2, 'number' => 1, 'name' => 'Three', 'air_date' => Clock::day(-100), 'name_tr' => 'Üç', 'imdb_id' => 'tt6000031'],
            ], 700)
            ->tvdbShow(700, 'tt0983983', [], [
                ['seasonNumber' => 1, 'number' => 1, 'aired' => Clock::day(-300), 'name' => 'One'],
                ['seasonNumber' => 2, 'number' => 1, 'aired' => Clock::day(-200), 'name' => 'Two'],
                ['seasonNumber' => 3, 'number' => 1, 'aired' => Clock::day(-100), 'name' => 'Three'],
            ]);
        import_show($this->pdo, 'tt0983983', background: true);

        $eps = $this->episodes('tt0983983');
        $this->assertSame(['0-1', '1-1', '2-1', '3-1'], array_keys($eps));
        $this->assertSame(['Special', 'One', 'Two', 'Three'], array_column($eps, 'name'));
        $this->assertSame([Clock::day(-400), Clock::day(-300), Clock::day(-200), Clock::day(-100)], array_column($eps, 'airdate'));
        // TMDB's ids and Turkish names only where both number alike.
        $this->assertSame(['tt6000001', 'tt6000011', null, null], array_column($eps, 'imdb_id'));
        $this->assertSame(['', 'Bir', '', ''], array_column($eps, 'name_tr'));
        $this->assertSame([], $this->providers->requested('/season/2/episode/'));

        // Without TheTVDB's list nothing changes: TMDB's numbering is the wrong one.
        $this->newProviders()->tmdbShow(900, 'tt0983983', [], [
            ['season' => 1, 'number' => 1, 'name' => 'Renamed', 'air_date' => Clock::day(-300)],
        ], 700);
        import_show($this->pdo, 'tt0983983', background: true);
        $this->assertSame(array_column($eps, 'name'), array_column($this->episodes('tt0983983'), 'name'));
    }

    /** The episode numbers TMDB was asked an IMDB id for, in order. */
    private function askedAbout(): array
    {
        return array_map(
            fn($key) => (int) preg_replace('~^.*/episode/(\d+)/external_ids$~', '$1', $key),
            $this->providers->requested('/external_ids')
        );
    }

    /** UTC DATETIME of $time on $date in New York (TheTVDB's zone for US shows). */
    private static function newYork(string $date, string $time): string
    {
        return (new \DateTimeImmutable("$date $time", new \DateTimeZone('America/New_York')))
            ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
