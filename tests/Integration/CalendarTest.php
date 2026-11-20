<?php

namespace Tvtrack\Tests\Integration;

use Tvtrack\Tests\Support\Clock;
use Tvtrack\Tests\Support\DatabaseTestCase;

/** The calendar (calendar.php): per tracked show, the next episode to watch. */
final class CalendarTest extends DatabaseTestCase
{
    public function testTheEarliestUnwatchedAiredEpisodeThenWhatComesNext(): void
    {
        $this->pdo->exec("INSERT INTO shows (imdb_id, name) VALUES ('tt1000020', 'Listed')");
        $insert = $this->pdo->prepare("INSERT INTO episodes (show_imdb_id, season, number, airdate) VALUES ('tt1000020', ?, ?, ?)");
        foreach ([[0, 1, -20], [1, 1, -14], [1, 2, -7], [1, 3, 7]] as [$season, $number, $days]) {
            $insert->execute([$season, $number, Clock::day($days)]);
        }
        $viewer = $this->user();
        $this->watch($viewer, 'tt1000020', '1-1');

        $this->assertSame(['1-2'], $this->listed(calendar_episodes($viewer)), 'specials after the regular episodes');
        $this->watch($viewer, 'tt1000020', '1-2');
        $this->assertSame(['0-1'], $this->listed(calendar_episodes($viewer)));
        $this->watch($viewer, 'tt1000020', '0-1');
        $this->assertSame([], calendar_episodes($viewer));
        $this->assertSame(['1-3'], $this->listed(calendar_upcoming($viewer)));
    }

    private function listed(array $rows): array
    {
        return array_map(fn($row) => $row['season'] . '-' . $row['number'], $rows);
    }
}
