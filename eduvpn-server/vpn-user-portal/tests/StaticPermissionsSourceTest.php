<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use PHPUnit\Framework\TestCase;
use Vpn\Portal\Cfg\StaticPermissionsConfig;
use Vpn\Portal\Http\Request;
use Vpn\Portal\StaticPermissionsSource;

/**
 * @covers \Vpn\Portal\StaticPermissionsSource
 *
 * @uses \Vpn\Portal\Ip
 * @uses \Vpn\Portal\Json
 * @uses \Vpn\Portal\Cfg\StaticPermissionsConfig
 * @uses \Vpn\Portal\FileIO
 * @uses \Vpn\Portal\Http\Request
 */
final class StaticPermissionsSourceTest extends TestCase
{
    public function testRemoteAddressFour(): void
    {
        // inside the range
        $permissionSource = new StaticPermissionsSource(self::cfg(), self::remoteAddressRequest('192.168.5.5'));
        static::assertSame(['memberOf!ipv4', 'memberOf!allowed-ip-list'], $permissionSource->permissionsForUser('x'));

        // outside the range
        $permissionSource = new StaticPermissionsSource(self::cfg(), self::remoteAddressRequest('192.168.6.5'));
        static::assertSame(['memberOf!ipv4'], $permissionSource->permissionsForUser('x'));
    }

    public function testRemoteAddressSix(): void
    {
        // inside the range
        $permissionSource = new StaticPermissionsSource(self::cfg(), self::remoteAddressRequest('fd00::5'));
        static::assertSame(['memberOf!ipv6', 'memberOf!allowed-ip-list'], $permissionSource->permissionsForUser('x'));

        // outside the range
        $permissionSource = new StaticPermissionsSource(self::cfg(), self::remoteAddressRequest('fd01::5'));
        static::assertSame(['memberOf!ipv6',], $permissionSource->permissionsForUser('x'));
    }

    private static function cfg(): StaticPermissionsConfig
    {
        return new StaticPermissionsConfig(['baseDir' => __DIR__]);
    }

    private static function remoteAddressRequest(string $remoteAddress): Request
    {
        return new Request(
            [
                'REMOTE_ADDR' => $remoteAddress,
            ],
            [],
            [],
            []
        );
    }
}
