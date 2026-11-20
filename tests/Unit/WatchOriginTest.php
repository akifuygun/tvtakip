<?php

namespace Tvtrack\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** input_origin(): what the footer ❤ accepts as a Watch site's new domain. */
final class WatchOriginTest extends TestCase
{
    /** @return array<string, array{string, ?string}> */
    public static function inputs(): array
    {
        return [
            'origin' => ['https://www.example.com/', 'https://www.example.com/'],
            'no trailing slash' => ['https://example.com', 'https://example.com/'],
            'bare domain' => ['example.com', 'https://example.com/'],
            'bare domain with a path' => ['dizipal1600.com/bolum/x-1x2', 'https://dizipal1600.com/'],
            'http kept' => ['http://example.com/a', 'http://example.com/'],
            'lower-cased' => ['HTTPS://WWW.Example.COM/Path', 'https://www.example.com/'],
            'spaces around' => ['  example.com  ', 'https://example.com/'],
            'query and fragment dropped' => ['https://example.com/?q=1#x', 'https://example.com/'],
            'other scheme' => ['ftp://example.com/', null],
            'javascript' => ['javascript:alert(1)', null],
            'port' => ['https://example.com:8443/', null],
            'user' => ['https://me@example.com/', null],
            'IP address' => ['https://203.0.113.5/', null],
            'no dot' => ['localhost', null],
            'underscore' => ['https://bad_host.example.com/', null],
            'hyphen at the end of a label' => ['https://bad-.example.com/', null],
            'numeric top level' => ['https://example.123/', null],
            'nonsense' => ['not a domain', null],
        ];
    }

    #[DataProvider('inputs')]
    public function testInput(string $input, ?string $origin): void
    {
        $this->assertSame($origin, input_origin($input));
    }
}
