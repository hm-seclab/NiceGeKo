<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use PHPUnit\Framework\TestCase;
use Vpn\Portal\Cfg\Config;

/**
 * @covers \Vpn\Portal\Cfg\Config
 *
 * @uses \Vpn\Portal\Cfg\ProfileConfig
 */
final class ConfigTest extends TestCase
{
    public function testNodeNumberUrlList(): void
    {
        $c = new Config(
            [
                'ProfileList' => [
                    [
                        'profileId' => 'foo',
                        'nodeUrl' => 'http://n1.home.arpa:41194',
                        'onNode' => 0,
                    ],
                    [
                        'profileId' => 'bar',
                        'nodeUrl' => ['http://n2.home.arpa:41194', 'http://n3.home.arpa:41194'],
                        'onNode' => [1, 2],
                    ],
                ],
            ]
        );

        static::assertSame(
            [
                0 => 'http://n1.home.arpa:41194',
                1 => 'http://n2.home.arpa:41194',
                2 => 'http://n3.home.arpa:41194',
            ],
            $c->nodeNumberUrlList()
        );
    }

    public function testNodeNumberUrlListOverlap(): void
    {
        $c = new Config(
            [
                'ProfileList' => [
                    [
                        'profileId' => 'foo',
                        'nodeUrl' => ['http://n1.home.arpa:41194', 'http://n2.home.arpa:41194'],
                    ],
                    [
                        'profileId' => 'bar',
                        'nodeUrl' => ['http://n1.home.arpa:41194', 'http://n2.home.arpa:41194'],
                    ],
                ],
            ]
        );

        static::assertSame(
            [
                0 => 'http://n1.home.arpa:41194',
                1 => 'http://n2.home.arpa:41194',
            ],
            $c->nodeNumberUrlList()
        );
    }

    public function testDefaultSupportedSessionExpiry(): void
    {
        $c = new Config([]);
        static::assertSame(['P90D'], $c->supportedSessionExpiry());
    }

    public function testSupportedSessionExpiry(): void
    {
        $c = new Config(
            [
                'supportedSessionExpiry' => ['PT12H', 'P1Y'],
            ]
        );
        static::assertSame(
            [
                'P90D',
                'PT12H',
                'P1Y',
            ],
            $c->supportedSessionExpiry()
        );
    }

    public function testDuplicateSupportedSessionExpiry(): void
    {
        $c = new Config(
            [
                'supportedSessionExpiry' => ['PT12H', 'P90D', 'P1Y'],
            ]
        );
        static::assertSame(
            [
                'P90D',
                'PT12H',
                'P90D',
                'P1Y',
            ],
            $c->supportedSessionExpiry()
        );
    }
}
