<?php

namespace Tvtrack\Tests\Integration;

use Tvtrack\Tests\Support\DatabaseTestCase;

/**
 * The settings table and what reads it: the Watch sites' origins, admin-set
 * (the footer ❤) or their URL template's. The sites are the bootstrap's AA
 * and BB.
 */
final class WatchBasesTest extends DatabaseTestCase
{
    public function testSettingIsSetReplacedAndDeleted(): void
    {
        $this->assertSame([], settings());
        set_setting('x.one', 'first');
        set_setting('x.one', 'second');
        set_setting('x.two', 'other');
        $this->assertSame(['x.one' => 'second', 'x.two' => 'other'], settings());
        set_setting('x.one', null);
        set_setting('x.missing', null);
        $this->assertSame(['x.two' => 'other'], settings());
    }

    public function testTemplateOriginsWithoutSettings(): void
    {
        $this->assertSame(
            ['AA' => 'https://aa.example.com/', 'BB' => 'https://www.bb.example.org/'],
            watch_bases()
        );
    }

    public function testAdminSetOriginReplacesOneSite(): void
    {
        set_setting(WATCH_BASE_SETTING . 'BB', 'https://bb2.example.net/');
        $this->assertSame(
            ['AA' => 'https://aa.example.com/', 'BB' => 'https://bb2.example.net/'],
            watch_bases()
        );
    }

    public function testStoredValueThatIsNotAnOriginIsIgnored(): void
    {
        set_setting(WATCH_BASE_SETTING . 'AA', 'https://aa2.example.com/with/a/path');
        set_setting(WATCH_BASE_SETTING . 'BB', 'bb2.example.net');
        $this->assertSame(
            ['AA' => 'https://aa.example.com/', 'BB' => 'https://www.bb.example.org/'],
            watch_bases()
        );
    }

    public function testSitesOutsideTheConfigAreNotListed(): void
    {
        set_setting(WATCH_BASE_SETTING . 'GONE', 'https://gone.example.com/');
        $this->assertSame(['AA', 'BB'], array_keys(watch_bases()));
    }
}
