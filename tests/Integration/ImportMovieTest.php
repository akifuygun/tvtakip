<?php

namespace Tvtrack\Tests\Integration;

use Tvtrack\Tests\Support\DatabaseTestCase;

/** import_movie(): a provisional movie moves to its IMDB id once TMDB links one. */
final class ImportMovieTest extends DatabaseTestCase
{
    public function testAProvisionalMovieMovesToItsImdbId(): void
    {
        $this->providers->tmdbMovie(810, null, ['title' => 'Indie']);
        $this->assertSame('tm810', import_movie($this->pdo, 'tm810'));
        $viewer = $this->user();
        $this->listMovie($viewer, 'tm810', true);

        $this->newProviders()->tmdbMovie(810, 'tt1000810', ['title' => 'Indie']);
        $this->assertSame('tt1000810', import_movie($this->pdo, 'tm810'));

        $this->assertSame(['tt1000810'], array_column($this->rows('SELECT imdb_id FROM movies'), 'imdb_id'));
        $this->assertSame(
            [['movie_imdb_id' => 'tt1000810', 'watched' => 1]],
            $this->rows('SELECT movie_imdb_id, watched FROM user_movies')
        );
    }

    public function testAMovieCachedUnderBothIdsIsFoldedIntoTheImdbOne(): void
    {
        $this->providers->tmdbMovie(811, null);
        import_movie($this->pdo, 'tm811');
        $this->pdo->exec("INSERT INTO movies (imdb_id, name) VALUES ('tt1000811', 'A Movie')");
        $viewer = $this->user();
        $other = $this->user('other@example.com');
        $this->listMovie($viewer, 'tm811', true);
        $this->listMovie($viewer, 'tt1000811', false);
        $this->listMovie($other, 'tm811', false);

        $this->newProviders()->tmdbMovie(811, 'tt1000811');
        $this->assertSame('tt1000811', import_movie($this->pdo, 'tt1000811'));

        $this->assertSame(['tt1000811'], array_column($this->rows('SELECT imdb_id FROM movies'), 'imdb_id'));
        $this->assertSame(
            [
                ['user_id' => $viewer, 'movie_imdb_id' => 'tt1000811', 'watched' => 1],
                ['user_id' => $other, 'movie_imdb_id' => 'tt1000811', 'watched' => 0],
            ],
            $this->rows('SELECT user_id, movie_imdb_id, watched FROM user_movies ORDER BY user_id')
        );
    }

    private function listMovie(int $userId, string $movieId, bool $watched): void
    {
        $this->pdo->prepare('INSERT INTO user_movies (user_id, movie_imdb_id, watched) VALUES (?, ?, ?)')
            ->execute([$userId, $movieId, (int) $watched]);
    }
}
