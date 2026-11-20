<?php

namespace Tvtrack\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderFieldsTest extends TestCase
{
    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function video(string $key, array $extra = []): array
    {
        return $extra + [
            'site' => 'YouTube', 'type' => 'Trailer', 'key' => $key, 'official' => true,
            'iso_639_1' => 'en', 'published_at' => '2026-01-01T00:00:00.000Z',
        ];
    }

    /**
     * @param list<array<string, mixed>> $videos
     * @return array<string, mixed>
     */
    private static function detail(array $videos): array
    {
        return ['videos' => ['results' => $videos]];
    }

    // youtube_key

    /** @return array<string, array{?string, ?string}> */
    public static function youtubeInputs(): array
    {
        return [
            'watch URL' => ['https://www.youtube.com/watch?v=abcDEF12345', 'abcDEF12345'],
            'watch URL with more parameters' => ['https://www.youtube.com/watch?feature=x&v=abcDEF12345&t=3', 'abcDEF12345'],
            'short URL' => ['https://youtu.be/abcDEF12345', 'abcDEF12345'],
            'embed URL' => ['https://www.youtube.com/embed/abcDEF12345?rel=0', 'abcDEF12345'],
            'bare id' => ['abc-DEF_123', 'abc-DEF_123'],
            'bare id with spaces around' => ['  abcDEF12345 ', 'abcDEF12345'],
            'too short' => ['abc', null],
            'too long bare id' => ['abcDEF123456', null],
            'not a video URL' => ['https://example.com/page', null],
            'empty' => ['', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('youtubeInputs')]
    public function testYoutubeKey(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, youtube_key($input));
    }

    // tmdb_trailers

    public function testTrailersKeepYouTubeTrailersAndTeasersOnly(): void
    {
        $out = tmdb_trailers(self::detail([
            self::video('vimeo000001', ['site' => 'Vimeo']),
            self::video('clip0000001', ['type' => 'Clip']),
            self::video('featurette1', ['type' => 'Featurette']),
            self::video('teaser00001', ['type' => 'Teaser']),
        ]));
        $this->assertSame(['en' => 'teaser00001'], $out);
    }

    public function testOfficialComesBeforeFan(): void
    {
        $out = tmdb_trailers(self::detail([
            self::video('fan00000001', ['official' => false, 'published_at' => '2026-06-01T00:00:00.000Z']),
            self::video('official001', ['official' => true, 'published_at' => '2026-01-01T00:00:00.000Z']),
        ]));
        $this->assertSame(['en' => 'official001'], $out);
    }

    public function testTrailerComesBeforeTeaser(): void
    {
        $out = tmdb_trailers(self::detail([
            self::video('teaser00001', ['type' => 'Teaser', 'published_at' => '2026-06-01T00:00:00.000Z']),
            self::video('trailer0001', ['type' => 'Trailer', 'published_at' => '2026-01-01T00:00:00.000Z']),
        ]));
        $this->assertSame(['en' => 'trailer0001'], $out);
    }

    public function testOfficialTeaserBeatsFanTrailer(): void
    {
        $out = tmdb_trailers(self::detail([
            self::video('fantrailer1', ['official' => false, 'type' => 'Trailer']),
            self::video('offteaser01', ['official' => true, 'type' => 'Teaser']),
        ]));
        $this->assertSame(['en' => 'offteaser01'], $out);
    }

    public function testNewestComesFirstAmongEquals(): void
    {
        $out = tmdb_trailers(self::detail([
            self::video('older000001', ['published_at' => '2025-01-01T00:00:00.000Z']),
            self::video('newer000001', ['published_at' => '2026-05-01T00:00:00.000Z']),
            self::video('middle00001', ['published_at' => '2025-09-01T00:00:00.000Z']),
        ]));
        $this->assertSame(['en' => 'newer000001'], $out);
    }

    public function testLanguageLessVideoCountsAsEnglish(): void
    {
        $out = tmdb_trailers(self::detail([
            self::video('nolang00001', ['iso_639_1' => null]),
            self::video('nolang00002', ['iso_639_1' => '', 'published_at' => '2026-06-01T00:00:00.000Z']),
        ]));
        $this->assertSame(['en' => 'nolang00002'], $out);
    }

    public function testVideoWithoutTheLanguageKeyCountsAsEnglish(): void
    {
        $video = self::video('nolang00001');
        unset($video['iso_639_1']);
        $this->assertSame(['en' => 'nolang00001'], tmdb_trailers(self::detail([$video])));
    }

    public function testOnlyEnglishAndTurkishAreKeptOneEach(): void
    {
        $out = tmdb_trailers(self::detail([
            self::video('french00001', ['iso_639_1' => 'fr']),
            self::video('german00001', ['iso_639_1' => 'de']),
            self::video('turkish0001', ['iso_639_1' => 'tr', 'published_at' => '2026-02-01T00:00:00.000Z']),
            self::video('turkish0002', ['iso_639_1' => 'tr', 'published_at' => '2025-02-01T00:00:00.000Z']),
            self::video('english0001', ['iso_639_1' => 'en']),
        ]));
        $this->assertEquals(['en' => 'english0001', 'tr' => 'turkish0001'], $out);
    }

    public function testVideosWithoutAUsableKeyAreDropped(): void
    {
        $out = tmdb_trailers(self::detail([
            self::video('short', ['key' => 'short']),
            self::video('good0000001'),
        ]));
        $this->assertSame(['en' => 'good0000001'], $out);
    }

    public function testNoVideosGiveNoTrailers(): void
    {
        $this->assertSame([], tmdb_trailers([]));
        $this->assertSame([], tmdb_trailers(self::detail([])));
    }

    // trailer_json

    public function testTrailerJsonKeepsOnlyValidKeysOfBothLanguages(): void
    {
        $json = trailer_json(['en' => 'abcDEF12345', 'tr' => 'https://youtu.be/ZZZzzz98765', 'fr' => 'frfrfrfrfrf']);
        $this->assertSame('{"en":"abcDEF12345","tr":"ZZZzzz98765"}', $json);
    }

    public function testTrailerJsonDropsInvalidKeys(): void
    {
        $this->assertSame('{"tr":"abcDEF12345"}', trailer_json(['en' => 'bad', 'tr' => 'abcDEF12345']));
    }

    public function testTrailerJsonIsNullForNone(): void
    {
        $this->assertNull(trailer_json([]));
        $this->assertNull(trailer_json(['en' => 'bad', 'fr' => 'abcDEF12345']));
    }

    // gallery_json

    public function testGalleryJsonKeepsHttpsUrlsOnly(): void
    {
        $json = gallery_json([
            'https://image.tmdb.org/t/p/w1280/a.jpg',
            'http://insecure.example/b.jpg',
            '//no-scheme.example/c.jpg',
            '/relative/d.jpg',
            '',
            'https://image.tmdb.org/t/p/w1280/e.jpg',
        ]);
        $this->assertSame('["https://image.tmdb.org/t/p/w1280/a.jpg","https://image.tmdb.org/t/p/w1280/e.jpg"]', $json);
    }

    public function testGalleryJsonIgnoresNonStrings(): void
    {
        $this->assertSame('["https://x.example/a.jpg"]', gallery_json([null, 5, ['https://no.example'], 'https://x.example/a.jpg']));
    }

    public function testGalleryJsonIsNullForNone(): void
    {
        $this->assertNull(gallery_json([]));
        $this->assertNull(gallery_json(['http://insecure.example/b.jpg']));
    }

    // tmdb_gallery

    /**
     * @param list<array<string, mixed>> $backdrops
     * @return array<string, mixed>
     */
    private static function images(array $backdrops): array
    {
        return ['images' => ['backdrops' => $backdrops]];
    }

    public function testGalleryPutsTextFreeFirstThenByVote(): void
    {
        $out = tmdb_gallery(self::images([
            ['file_path' => '/en-low.jpg', 'iso_639_1' => 'en', 'vote_average' => 3.0],
            ['file_path' => '/free-low.jpg', 'iso_639_1' => null, 'vote_average' => 4.0],
            ['file_path' => '/en-high.jpg', 'iso_639_1' => 'en', 'vote_average' => 9.0],
            ['file_path' => '/free-high.jpg', 'iso_639_1' => null, 'vote_average' => 6.0],
        ]));
        $this->assertSame([
            TMDB_GALLERY_BASE . '/free-high.jpg',
            TMDB_GALLERY_BASE . '/free-low.jpg',
            TMDB_GALLERY_BASE . '/en-high.jpg',
            TMDB_GALLERY_BASE . '/en-low.jpg',
        ], $out);
    }

    public function testGalleryHoldsAtMostGalleryMaxAndUsesTheFullUrl(): void
    {
        $backdrops = [];
        for ($i = 1; $i <= GALLERY_MAX + 4; $i++) {
            $backdrops[] = ['file_path' => "/b$i.jpg", 'iso_639_1' => null, 'vote_average' => 100 - $i];
        }
        $out = tmdb_gallery(self::images($backdrops));
        $this->assertCount(GALLERY_MAX, $out);
        $this->assertSame(9, GALLERY_MAX);
        $this->assertSame('https://image.tmdb.org/t/p/w1280/b1.jpg', $out[0]);
        $this->assertStringStartsWith('https://', $out[GALLERY_MAX - 1]);
    }

    public function testGallerySkipsBackdropsWithoutAPath(): void
    {
        $out = tmdb_gallery(self::images([
            ['file_path' => '', 'iso_639_1' => null],
            ['iso_639_1' => null],
            ['file_path' => '/ok.jpg', 'iso_639_1' => null],
        ]));
        $this->assertSame([TMDB_GALLERY_BASE . '/ok.jpg'], $out);
    }

    public function testGalleryIsEmptyWithoutImages(): void
    {
        $this->assertSame([], tmdb_gallery([]));
        $this->assertSame([], tmdb_gallery(self::images([])));
    }

    // Overviews

    public function testTmdbTurkishOverview(): void
    {
        $detail = ['translations' => ['translations' => [
            ['iso_639_1' => 'de', 'data' => ['overview' => 'Deutsch']],
            ['iso_639_1' => 'tr', 'data' => ['overview' => '  Türkçe özet  ']],
        ]]];
        $this->assertSame('Türkçe özet', tmdb_tr_overview($detail));
    }

    public function testTmdbTurkishOverviewSkipsAnEmptyTurkishEntry(): void
    {
        $detail = ['translations' => ['translations' => [
            ['iso_639_1' => 'tr', 'data' => ['overview' => '   ']],
            ['iso_639_1' => 'tr', 'data' => ['overview' => 'İkinci']],
        ]]];
        $this->assertSame('İkinci', tmdb_tr_overview($detail));
    }

    public function testTmdbTurkishOverviewIsNullWhenAbsent(): void
    {
        $this->assertNull(tmdb_tr_overview([]));
        $this->assertNull(tmdb_tr_overview(['translations' => ['translations' => [
            ['iso_639_1' => 'tr', 'data' => ['overview' => '']],
            ['iso_639_1' => 'en', 'data' => ['overview' => 'English']],
        ]]]));
    }

    public function testTvdbTurkishOverview(): void
    {
        $record = ['translations' => ['overviewTranslations' => [
            ['language' => 'eng', 'overview' => 'English'],
            ['language' => 'tur', 'overview' => ' Türkçe '],
        ]]];
        $this->assertSame('Türkçe', tvdb_tr_overview($record));
    }

    public function testTvdbTurkishOverviewIsNullWhenAbsentOrBlank(): void
    {
        $this->assertNull(tvdb_tr_overview([]));
        $this->assertNull(tvdb_tr_overview(['translations' => ['overviewTranslations' => [
            ['language' => 'tur', 'overview' => ' '],
            ['language' => 'eng', 'overview' => 'English'],
        ]]]));
    }

    // Small cleaners

    /** @return array<string, array{list<mixed>, ?string}> */
    public static function genreLists(): array
    {
        return [
            'joined' => [['Drama', 'Comedy'], 'Drama, Comedy'],
            'trimmed and blanks dropped' => [[' Drama ', '', '  ', 'Comedy'], 'Drama, Comedy'],
            'empty' => [[], null],
            'only blanks' => [['', ' '], null],
        ];
    }

    /** @param list<mixed> $names */
    #[DataProvider('genreLists')]
    public function testGenresString(array $names, ?string $expected): void
    {
        $this->assertSame($expected, genres_string($names));
    }

    public function testGenresStringIsCutAt255Characters(): void
    {
        $out = genres_string([str_repeat('é', 200), str_repeat('ü', 200)]);
        $this->assertNotNull($out);
        $this->assertSame(255, mb_strlen($out));
    }

    /** @return array<string, array{mixed, ?float}> */
    public static function ratings(): array
    {
        return [
            'rounded to one decimal' => [7.84, 7.8],
            'rounds up' => [7.85, 7.9],
            'numeric string' => ['8.26', 8.3],
            'integer' => [9, 9.0],
            'zero' => [0, null],
            'zero string' => ['0', null],
            'negative' => [-1.5, null],
            'null' => [null, null],
            'not numeric' => ['n/a', null],
            'rounds to zero but is positive' => [0.04, 0.0],
        ];
    }

    #[DataProvider('ratings')]
    public function testCleanRating(mixed $value, ?float $expected): void
    {
        $this->assertSame($expected, clean_rating($value));
    }

    /** @return array<string, array{mixed, ?int}> */
    public static function runtimes(): array
    {
        return [
            'minutes' => [45, 45],
            'numeric string' => ['42', 42],
            'zero' => [0, null],
            'negative' => [-10, null],
            'null' => [null, null],
            'not numeric' => ['abc', null],
            'clamped to the column' => [100000, 65535],
            'at the limit' => [65535, 65535],
        ];
    }

    #[DataProvider('runtimes')]
    public function testCleanRuntime(mixed $value, ?int $expected): void
    {
        $this->assertSame($expected, clean_runtime($value));
    }

    /** @return array<string, array{mixed, ?string}> */
    public static function dates(): array
    {
        return [
            'a date' => ['2026-07-15', '2026-07-15'],
            'null' => [null, null],
            'empty' => ['', null],
            'with a time' => ['2026-07-15 20:00:00', null],
            'wrong order' => ['15-07-2026', null],
            'not a string' => [20260715, null],
        ];
    }

    #[DataProvider('dates')]
    public function testValidDate(mixed $value, ?string $expected): void
    {
        $this->assertSame($expected, valid_date($value));
    }

    /** @return array<string, array{?string, ?string}> */
    public static function showStatuses(): array
    {
        return [
            'null' => [null, null],
            'empty' => ['', null],
            'blank' => ['  ', null],
            'TVmaze running' => ['Running', 'running'],
            'TMDB returning' => ['Returning Series', 'running'],
            'ended' => ['Ended', 'ended'],
            'canceled' => ['Canceled', 'canceled'],
            'British spelling' => ['Cancelled', 'canceled'],
            'in production' => ['In Production', 'upcoming'],
            'planned' => ['Planned', 'upcoming'],
            'pilot' => ['Pilot', 'upcoming'],
            'in development' => ['In Development', 'upcoming'],
            'to be determined' => ['To Be Determined', 'unknown'],
            'already canonical upcoming' => ['upcoming', 'upcoming'],
            'padded and upper case' => ['  RETURNING SERIES ', 'running'],
            'unheard of' => ['Hiatus', 'unknown'],
        ];
    }

    #[DataProvider('showStatuses')]
    public function testNormalizeShowStatus(?string $status, ?string $expected): void
    {
        $this->assertSame($expected, normalize_show_status($status));
    }

    /** @return array<string, array{?string, ?string}> */
    public static function movieStatuses(): array
    {
        return [
            'null' => [null, null],
            'empty' => ['', null],
            'released' => ['Released', 'released'],
            'post production' => ['Post Production', 'upcoming'],
            'in production' => ['In Production', 'upcoming'],
            'planned' => ['Planned', 'upcoming'],
            'rumored' => ['Rumored', 'upcoming'],
            'canceled' => ['Canceled', 'canceled'],
            'British spelling' => ['Cancelled', 'canceled'],
            'padded and lower case' => [' released ', 'released'],
            'unheard of' => ['Leaked', 'unknown'],
        ];
    }

    #[DataProvider('movieStatuses')]
    public function testNormalizeMovieStatus(?string $status, ?string $expected): void
    {
        $this->assertSame($expected, normalize_movie_status($status));
    }
}
