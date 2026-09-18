<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use PHPUnit\Framework\TestCase;
use Vpn\Portal\Cfg\RadiusAuthConfig;

/**
 * @covers \Vpn\Portal\Cfg\RadiusAuthConfig
 */
final class RadiusAuthConfigTest extends TestCase
{
    public function testIpFour(): void
    {
        $r = new RadiusAuthConfig(
            [
                'serverList' => [
                    '127.0.0.1:1812:s3cr3t',
                ],
            ]
        );
        static::assertCount(1, $r->serverList());
        $serverInfo = $r->serverList()[0];
        static::assertSame('udp://127.0.0.1:1812', $serverInfo->serverUri());
        static::assertSame('s3cr3t', $serverInfo->sharedSecret());
    }

    public function testIpSix(): void
    {
        $r = new RadiusAuthConfig(
            [
                'serverList' => [
                    '[fdd4:56b7:5ebc:4e82::1]:1812:s3cr3t',
                ],
            ]
        );
        static::assertCount(1, $r->serverList());
        $serverInfo = $r->serverList()[0];
        static::assertSame('udp://[fdd4:56b7:5ebc:4e82::1]:1812', $serverInfo->serverUri());
        static::assertSame('s3cr3t', $serverInfo->sharedSecret());
    }

    public function testMultiple(): void
    {
        $r = new RadiusAuthConfig(
            [
                'serverList' => [
                    'radius.example.org:12345:foobar',
                    '127.0.0.1:1812:s3cr3t',
                    '[fdd4:56b7:5ebc:4e82::1]:1812:s3cr3t',
                ],
            ]
        );
        static::assertCount(3, $r->serverList());
        static::assertSame('udp://radius.example.org:12345', $r->serverList()[0]->serverUri());
        static::assertSame('foobar', $r->serverList()[0]->sharedSecret());
        static::assertSame('udp://127.0.0.1:1812', $r->serverList()[1]->serverUri());
        static::assertSame('s3cr3t', $r->serverList()[1]->sharedSecret());
        static::assertSame('udp://[fdd4:56b7:5ebc:4e82::1]:1812', $r->serverList()[2]->serverUri());
        static::assertSame('s3cr3t', $r->serverList()[2]->sharedSecret());
    }

    public function testRealm(): void
    {
        $r = new RadiusAuthConfig(['addRealm' => 'example.org']);
        static::assertSame('example.org', $r->radiusRealm());
        $r = new RadiusAuthConfig(['radiusRealm' => 'example.org']);
        static::assertSame('example.org', $r->radiusRealm());
        $r = new RadiusAuthConfig([]);
        static::assertNull($r->radiusRealm());
    }
}
