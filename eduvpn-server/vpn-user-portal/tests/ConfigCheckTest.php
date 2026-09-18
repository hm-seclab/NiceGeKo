<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vpn\Portal\Cfg\Config;
use Vpn\Portal\ConfigCheck;
use Vpn\Portal\OpenVpn\CA\CaInfo;

/**
 * @covers \Vpn\Portal\ConfigCheck
 *
 * @uses \Vpn\Portal\Cfg\ProfileConfig
 * @uses \Vpn\Portal\Cfg\Config
 * @uses \Vpn\Portal\Cfg\WireGuardConfig
 * @uses \Vpn\Portal\FileIO
 * @uses \Vpn\Portal\Ip
 * @uses \Vpn\Portal\Validator
 * @uses \Vpn\Portal\OpenVpn\CA\CaInfo
 * @uses \Vpn\Portal\Dt
 */
final class ConfigCheckTest extends TestCase
{
    public function testEmpty(): void
    {
        static::assertSame(
            [
                'global_problems' => [],
                'profile_problems' => [],
            ],
            ConfigCheck::verify(new Config([]), self::caInfo())
        );
    }

    public function testDefaultConfig(): void
    {
        static::assertSame(
            [
                'global_problems' => [],
                'profile_problems' => [
                    'default' => [],
                ],
            ],
            ConfigCheck::verify(Config::fromFile(\dirname(__DIR__) . '/config/config.php.example'), self::caInfo())
        );
    }

    public function testInvalidProfileId(): void
    {
        static::assertSame(
            [
                'global_problems' => [],
                'profile_problems' => [
                    'foo_bar' => [
                        '"profileId" field has invalid value "foo_bar"',
                    ],
                ],
            ],
            ConfigCheck::verify(
                new Config(
                    [
                        'ProfileList' => [
                            [
                                'profileId' => 'foo_bar',
                                'wRangeFour' => '10.0.0.0/24',
                                'wRangeSix' => 'fd00::/64',
                                'defaultGateway' => false,
                            ],
                        ],
                    ]
                ),
                self::caInfo()
            )
        );
    }

    public function testVerifyUniqueOpenVpnPortsPerNode(): void
    {
        static::assertSame(
            [
                'global_problems' => [],
                'profile_problems' => [
                    'foo' => [],
                    'bar' => [
                        'Node "http://localhost:41194" already uses IP!UDP port "::/128!1194"',
                        'Node "http://localhost:41194" already uses IP!TCP port "::/128!1194"',
                    ],
                ],
            ],
            ConfigCheck::verify(
                new Config(
                    [
                        'ProfileList' => [
                            [
                                'profileId' => 'foo',
                                'oRangeFour' => '10.0.0.0/24',
                                'oRangeSix' => 'fd00::/64',
                                'defaultGateway' => false,
                            ],
                            [
                                'profileId' => 'bar',
                                'oRangeFour' => '10.1.0.0/24',
                                'oRangeSix' => 'fd01::/64',
                                'defaultGateway' => false,
                            ],
                        ],
                    ]
                ),
                self::caInfo()
            )
        );
    }

    public function testVerifyUniqueOpenVpnPortsPerNodeDifferentListenOn(): void
    {
        static::assertSame(
            [
                'global_problems' => [],
                'profile_problems' => [
                    'foo' => [],
                    'bar' => [],
                ],
            ],
            ConfigCheck::verify(
                new Config(
                    [
                        'ProfileList' => [
                            [
                                'profileId' => 'foo',
                                'oRangeFour' => '10.0.0.0/24',
                                'oRangeSix' => 'fd00::/64',
                                'defaultGateway' => false,
                                'oListenOn' => '192.0.2.10',
                            ],
                            [
                                'profileId' => 'bar',
                                'oRangeFour' => '10.1.0.0/24',
                                'oRangeSix' => 'fd01::/64',
                                'defaultGateway' => false,
                                'oListenOn' => '192.0.2.11',
                            ],
                        ],
                    ]
                ),
                self::caInfo()
            )
        );
    }

    private static function caInfo(): CaInfo
    {
        $dateTime = new DateTimeImmutable();

        return new CaInfo(
            'CA Certificate',
            $dateTime->sub(new DateInterval('P1Y'))->getTimestamp(),
            $dateTime->add(new DateInterval('P5Y'))->getTimestamp()
        );
    }
}
