<?php

namespace Tvtrack\Tests\Support;

/** Dates and times relative to now, as the code under test sees them. */
final class Clock
{
    /** The date $days days from today (negative: before), in the visitor's
     *  time zone — the one today() uses (auth.php sets it). */
    public static function day(int $days): string
    {
        return date('Y-m-d', (int) strtotime(sprintf('%s %+d days', today(), $days)));
    }

    /** UTC DATETIME $seconds from now (negative: before), as airstamp holds it. */
    public static function utc(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', time() + $seconds);
    }

    /** A UTC DATETIME as TVmaze writes an airstamp (ISO 8601). */
    public static function iso(string $utc): string
    {
        return str_replace(' ', 'T', $utc) . '+00:00';
    }
}
