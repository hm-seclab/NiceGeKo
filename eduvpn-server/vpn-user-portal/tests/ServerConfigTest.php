<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use PHPUnit\Framework\TestCase;
use Vpn\Portal\Cfg\Config;
use Vpn\Portal\OpenVpn\ServerConfig as OpenVpnServerConfig;
use Vpn\Portal\OpenVpn\TlsCrypt;
use Vpn\Portal\ServerConfig;
use Vpn\Portal\WireGuard\ServerConfig as WireGuardServerConfig;

/**
 * @internal
 *
 * @coversNothing
 */
final class ServerConfigTest extends TestCase
{
    public function testGetConfig(): void
    {
        $tmpDir = sys_get_temp_dir();
        $config = new Config(
            [
                'Db' => [
                    'dbDsn' => 'sqlite::memory:',
                ],
                'WireGuard' => [
                    'listenPort' => 443,
                    'setMtu' => 1392,
                    'firewallMark' => 1337,
                ],
                'ProfileList' => [
                    [
                        'profileId' => 'default',
                        'displayName' => 'Default',
                        'hostName' => 'vpn.example',
                        'dnsServerList' => ['9.9.9.9', '2620:fe::fe'],
                        'wRangeFour' => '10.43.43.0/24',
                        'wRangeSix' => 'fd43::/64',
                    ],
                    [
                        'profileId' => 'other',
                        'displayName' => 'Other',
                        'hostName' => 'vpn.example',
                        'dnsServerList' => ['9.9.9.9', '2620:fe::fe'],
                        'wRangeFour' => '192.168.1.0/24',
                        'wRangeSix' => 'fd99::/64',
                    ],
                ],
            ]
        );
        $serverConfig = new ServerConfig(
            openVpnServerConfig : new OpenVpnServerConfig($config->openVpnConfig(), new TestCa(), new TlsCrypt($tmpDir)),
            wireGuardServerConfig: new WireGuardServerConfig($tmpDir, $config->wireGuardConfig())
        );

        static::assertSame(
            [
                'wireguard' => [
                    'Address' => [
                        '10.43.43.1/24',
                        '192.168.1.1/24',
                        'fd43::1/64',
                        'fd99::1/64',
                    ],
                    'ListenPort' => 443,
                    'FwMark' => 1337,
                    'MTU' => 1392,
                ],
            ],
            $serverConfig->get(
                profileConfigList: $config->profileConfigList(),
                nodeNumber: 0,
                publicKey: 'i5m+eBcVa69VWDK+2RiFZrLBC6ksTisKWQDt4He+U0o=',
                cpuHasAes: true,
                vpnUser: 'openvpn',
                vpnGroup: 'openvpn'
            )
        );
    }
}
