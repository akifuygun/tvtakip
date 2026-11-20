<?php

namespace Tvtrack\Tests\Support;

/**
 * TMDB, TVmaze and TheTVDB as a test needs them: installed, it answers every
 * request includes/http.php would send (see http_fake()), from what the test
 * set up here, and remembers what was asked. A request nothing was set up for
 * fails, as a failed request does (null).
 *
 * The answers carry only the fields includes/importer.php reads. They are
 * registered under the URLs the importer's own functions build, so a test
 * keeps working when a URL's details change.
 */
final class FakeProviders
{
    /** @var array<string, array|null> answer by request (key()) */
    private array $answers = [];

    /** @var list<string> every request made, in order (key()) */
    public array $requests = [];

    public function __construct()
    {
        // TheTVDB's login: the key is all it takes.
        $this->on(TVDB_BASE . '/login', ['data' => ['token' => 'test-token']]);
    }

    public function install(): void
    {
        $GLOBALS['tvtrack_http_fake'] = $this->answer(...);
    }

    public static function uninstall(): void
    {
        unset($GLOBALS['tvtrack_http_fake']);
    }

    public function answer(string $url, ?array $payload = null): ?array
    {
        $key = self::key($url);
        $this->requests[] = $key;
        return $this->answers[$key] ?? null;
    }

    /** A request as compared: host, path and the query without the API key, sorted. */
    public static function key(string $url): string
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        unset($query['api_key']);
        ksort($query);
        return ($parts['host'] ?? '') . ($parts['path'] ?? '') . ($query ? '?' . http_build_query($query) : '');
    }

    /** Answer this request with $answer (null: it fails). */
    public function on(string $url, ?array $answer): self
    {
        $this->answers[self::key($url)] = $answer;
        return $this;
    }

    /** The requests made so far whose key() contains $part. */
    public function requested(string $part): array
    {
        return array_values(array_filter($this->requests, fn($key) => str_contains($key, $part)));
    }

    /**
     * A show on TMDB under $tmdbId, linking the IMDB id $imdbId (null: none)
     * and the TheTVDB id $tvdbId. $show: fields of its detail record over the
     * defaults below. $episodes: [season, number] with optionally 'name',
     * 'air_date', 'episode_type' ('finale', 'mid_season'; default 'standard'),
     * 'name_tr' (its name when asked in Turkish: TMDB answers an untranslated
     * episode under its English name, the default) and 'imdb_id' (what its
     * external_ids lookup answers: an id, or '' for none; without the key the
     * lookup fails).
     */
    public function tmdbShow(int $tmdbId, ?string $imdbId, array $show = [], array $episodes = [], ?int $tvdbId = null): self
    {
        $seasons = [];
        foreach ($episodes as $ep) {
            $seasons[$ep['season']][] = $ep;
        }
        ksort($seasons);
        $this->on(tmdb_api_url("/tv/$tmdbId", TMDB_DETAIL_EXTRAS), $show + [
            'id' => $tmdbId,
            'name' => 'A Show',
            'original_name' => 'A Show',
            'original_language' => 'en',
            'status' => 'Returning Series',
            'overview' => 'What it is about.',
            'first_air_date' => '2020-01-05',
            'genres' => [['name' => 'Drama']],
            'networks' => [['name' => 'HBO']],
            'vote_average' => 8.24,
            'episode_run_time' => [55],
            'poster_path' => '/poster.jpg',
            'backdrop_path' => '/backdrop.jpg',
            'seasons' => array_map(fn($n) => ['season_number' => $n], array_keys($seasons)),
            'external_ids' => ['imdb_id' => $imdbId, 'tvdb_id' => $tvdbId],
            'translations' => ['translations' => []],
            'images' => ['backdrops' => []],
            'videos' => ['results' => []],
        ]);
        $this->on(tmdb_api_url("/tv/$tmdbId/external_ids"), ['imdb_id' => $imdbId, 'tvdb_id' => $tvdbId]);
        if ($imdbId !== null) {
            $this->tmdbFinds($imdbId, $tmdbId);
        }
        foreach ($seasons as $season => $list) {
            $english = [];
            $turkish = [];
            foreach ($list as $ep) {
                $name = $ep['name'] ?? 'Episode ' . $ep['number'];
                $english[] = [
                    'season_number' => $season,
                    'episode_number' => $ep['number'],
                    'name' => $name,
                    'air_date' => $ep['air_date'] ?? null,
                    'episode_type' => $ep['episode_type'] ?? 'standard',
                ];
                $turkish[] = ['name' => $ep['name_tr'] ?? $name] + end($english);
                if (array_key_exists('imdb_id', $ep)) {
                    $this->on(
                        tmdb_api_url("/tv/$tmdbId/season/$season/episode/{$ep['number']}/external_ids"),
                        ['imdb_id' => $ep['imdb_id'] === '' ? null : $ep['imdb_id']]
                    );
                }
            }
            $this->on(tmdb_season_url($tmdbId, $season), ['season_number' => $season, 'episodes' => $english]);
            $this->on(tmdb_season_url($tmdbId, $season, 'tr-TR'), ['season_number' => $season, 'episodes' => $turkish]);
        }
        return $this;
    }

    /** TMDB finds the show $tmdbId by the IMDB id $imdbId. */
    public function tmdbFinds(string $imdbId, int $tmdbId): self
    {
        return $this->on(
            tmdb_api_url("/find/$imdbId", ['external_source' => 'imdb_id']),
            ['tv_results' => [['id' => $tmdbId, 'poster_path' => '/poster.jpg']], 'movie_results' => []]
        );
    }

    /** A movie on TMDB under $tmdbId, linking the IMDB id $imdbId (null: none). */
    public function tmdbMovie(int $tmdbId, ?string $imdbId, array $movie = []): self
    {
        $this->on(tmdb_api_url("/movie/$tmdbId", TMDB_DETAIL_EXTRAS), $movie + [
            'id' => $tmdbId,
            'imdb_id' => $imdbId,
            'title' => 'A Movie',
            'status' => 'Released',
            'overview' => 'What it is about.',
            'release_date' => '2024-03-01',
            'genres' => [['name' => 'Comedy']],
            'production_companies' => [['name' => 'A Studio']],
            'vote_average' => 7.0,
            'runtime' => 101,
            'poster_path' => '/movie.jpg',
            'translations' => ['translations' => []],
            'images' => ['backdrops' => []],
            'videos' => ['results' => []],
        ]);
        if ($imdbId !== null) {
            $this->on(
                tmdb_api_url("/find/$imdbId", ['external_source' => 'imdb_id']),
                ['tv_results' => [], 'movie_results' => [['id' => $tmdbId]]]
            );
        }
        return $this;
    }

    /**
     * A show on TVmaze under $mazeId, found by its IMDB id $imdbId (null:
     * TVmaze links none) and by the TheTVDB id $tvdbId. $episodes: [season,
     * number] with optionally 'name', 'airdate' and 'airtime' (local "HH:MM";
     * default '', none) with 'airstamp' (its UTC time as ISO 8601). Like
     * TVmaze, an episode without an air time still gets an airstamp: noon of
     * its air day.
     */
    public function tvmazeShow(int $mazeId, ?string $imdbId, array $show = [], array $episodes = [], ?int $tvdbId = null): self
    {
        $record = $show + [
            'id' => $mazeId,
            'name' => 'A Show',
            'status' => 'Running',
            'summary' => '<p>What it is about.</p>',
            'premiered' => '2020-01-05',
            'genres' => ['Drama'],
            'network' => ['name' => 'HBO'],
            'rating' => ['average' => 8.0],
            'runtime' => 55,
            'image' => ['medium' => 'https://static.tvmaze.com/poster.jpg'],
            'externals' => ['imdb' => $imdbId, 'thetvdb' => $tvdbId],
        ];
        if ($imdbId !== null) {
            $this->on(TVMAZE_BASE . '/lookup/shows?imdb=' . urlencode($imdbId), $record);
        }
        if ($tvdbId !== null) {
            $this->on(TVMAZE_BASE . '/lookup/shows?thetvdb=' . $tvdbId, $record);
        }
        $this->on(TVMAZE_BASE . "/shows/$mazeId", $record);
        $this->on(TVMAZE_BASE . "/shows/$mazeId/episodes", array_map(fn($ep) => $ep + [
            'name' => 'Episode ' . $ep['number'],
            'airdate' => null,
            'airtime' => '',
            'airstamp' => isset($ep['airdate']) ? $ep['airdate'] . 'T12:00:00+00:00' : null,
        ], $episodes));
        return $this;
    }

    /**
     * A show on TheTVDB under $seriesId, found by its IMDB id $imdbId. $series:
     * fields of its extended record over the defaults (no regular air time,
     * US). $episodes: [seasonNumber, number, aired] with optionally 'name' and
     * 'finaleType' ('season', 'series', 'midseason').
     */
    public function tvdbShow(int $seriesId, ?string $imdbId, array $series = [], array $episodes = []): self
    {
        if ($imdbId !== null) {
            $this->on(TVDB_BASE . '/search/remoteid/' . rawurlencode($imdbId), ['data' => [['series' => ['id' => $seriesId]]]]);
        }
        $this->on(TVDB_BASE . "/series/$seriesId/extended?meta=episodes,translations&short=true", ['data' => $series + [
            'id' => $seriesId,
            'airsTime' => '',
            'airsDays' => [],
            'originalCountry' => 'usa',
            'episodes' => array_map(fn($ep) => $ep + ['name' => 'Episode ' . $ep['number'], 'finaleType' => null], $episodes),
            'translations' => [],
            'trailers' => [],
        ]]);
        $this->on(TVDB_BASE . "/series/$seriesId/artworks?type=3", ['data' => ['artworks' => []]]);
        return $this;
    }

    /** TheTVDB answers, and doesn't know the show $imdbId. */
    public function tvdbUnknown(string $imdbId): self
    {
        return $this->on(TVDB_BASE . '/search/remoteid/' . rawurlencode($imdbId), ['data' => []]);
    }
}
