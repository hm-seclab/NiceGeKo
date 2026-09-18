<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\OpenVPN\Tests;

use PHPUnit\Framework\TestCase;
use Vpn\Portal\Cfg\OpenVpnConfig;
use Vpn\Portal\Cfg\ProfileConfig;
use Vpn\Portal\OpenVpn\SingleProcessServerConfig as ServerConfig;
use Vpn\Portal\OpenVpn\TlsCrypt;
use Vpn\Portal\Tests\TestCa;
use Vpn\Portal\Tests\TestKeys;

/**
 * @internal
 *
 * @coversNothing
 */
final class SingleProcessServerConfigTest extends TestCase
{
    public function testDnsTemplate(): void
    {
        $tmpDir = \sprintf('%s/vpn-user-portal-%s', sys_get_temp_dir(), bin2hex(random_bytes(32)));
        mkdir($tmpDir);
        $tlsCryptDefault = TestKeys::tlsCrypt($tmpDir, 'default');

        $s = new ServerConfig(
            new OpenVpnConfig([]),
            new TestCa(),
            new TlsCrypt($tmpDir)
        );

        static::assertSame(
            [
                'default.conf'
=> <<<EOF
    # OpenVPN Server Config | Automatically Generated | Do NOT modify!
    verb 3
    dev-type tun
    user openvpn
    group openvpn
    topology subnet
    persist-tun
    remote-cert-tls client
    dh none
    tls-version-min 1.3
    data-ciphers CHACHA20-POLY1305:AES-256-GCM
    reneg-sec 36000
    client-connect /usr/libexec/vpn-server-node/client-connect
    client-disconnect /usr/libexec/vpn-server-node/client-disconnect
    server 10.42.42.0 255.255.255.0
    server-ipv6 fd42::/112
    max-clients 252
    keepalive 10 60
    script-security 2
    dev tun0
    management /run/openvpn-server/default.sock unix
    setenv PROFILE_ID default
    <ca>
    ---CA---
    </ca>
    <cert>
    ---SERVER CERT---
    </cert>
    <key>
    ---SERVER KEY---
    </key>
    <tls-crypt>
    {$tlsCryptDefault}
    </tls-crypt>
    log /dev/null
    local :: 443 udp
    local :: 1194 udp
    local :: 443 tcp-server
    local :: 1194 tcp-server
    tcp-nodelay
    explicit-exit-notify 1
    push "explicit-exit-notify 1"
    push "redirect-gateway def1 ipv6"
    push "route 0.0.0.0 0.0.0.0"
    push "dhcp-option DNS 10.42.42.1"
    push "dhcp-option DNS 9.9.9.9"
    push "dhcp-option DNS fd42::1"
    push "block-outside-dns"
    EOF,
            ],
            $s->getProfile(
                new ProfileConfig(
                    [
                        'profileId' => 'default',
                        'displayName' => 'Default',
                        'hostName' => 'vpn.example.org',
                        'oRangeFour' => '10.42.42.0/24',
                        'oRangeSix' => 'fd42::/64',
                        'oUdpPortList' => [443, 1194],
                        'oTcpPortList' => [443, 1194],
                        'dnsServerList' => ['@GW4@', '9.9.9.9', '@GW6@'],
                    ],
                ),
                0,
                false,
                'openvpn',
                'openvpn'
            )
        );
    }

    public function testSetMtu(): void
    {
        $tmpDir = \sprintf('%s/vpn-user-portal-%s', sys_get_temp_dir(), bin2hex(random_bytes(32)));
        mkdir($tmpDir);
        $tlsCryptDefault = TestKeys::tlsCrypt($tmpDir, 'default');

        $s = new ServerConfig(
            new OpenVpnConfig(['setMtu' => 1392]),
            new TestCa(),
            new TlsCrypt($tmpDir)
        );

        static::assertSame(
            [
                'default.conf'
=> <<<EOF
    # OpenVPN Server Config | Automatically Generated | Do NOT modify!
    verb 3
    dev-type tun
    user openvpn
    group openvpn
    topology subnet
    persist-tun
    remote-cert-tls client
    dh none
    tls-version-min 1.3
    data-ciphers CHACHA20-POLY1305:AES-256-GCM
    reneg-sec 36000
    client-connect /usr/libexec/vpn-server-node/client-connect
    client-disconnect /usr/libexec/vpn-server-node/client-disconnect
    server 10.42.42.0 255.255.255.0
    server-ipv6 fd42::/112
    max-clients 252
    keepalive 10 60
    script-security 2
    dev tun0
    management /run/openvpn-server/default.sock unix
    setenv PROFILE_ID default
    <ca>
    ---CA---
    </ca>
    <cert>
    ---SERVER CERT---
    </cert>
    <key>
    ---SERVER KEY---
    </key>
    <tls-crypt>
    {$tlsCryptDefault}
    </tls-crypt>
    tun-mtu 1392
    log /dev/null
    local :: 443 udp
    local :: 1194 udp
    local :: 443 tcp-server
    local :: 1194 tcp-server
    tcp-nodelay
    explicit-exit-notify 1
    push "explicit-exit-notify 1"
    push "redirect-gateway def1 ipv6"
    push "route 0.0.0.0 0.0.0.0"
    push "dhcp-option DNS 10.42.42.1"
    push "dhcp-option DNS 9.9.9.9"
    push "dhcp-option DNS fd42::1"
    push "block-outside-dns"
    EOF,
            ],
            $s->getProfile(
                new ProfileConfig(
                    [
                        'profileId' => 'default',
                        'displayName' => 'Default',
                        'hostName' => 'vpn.example.org',
                        'oRangeFour' => '10.42.42.0/24',
                        'oRangeSix' => 'fd42::/64',
                        'oUdpPortList' => [443, 1194],
                        'oTcpPortList' => [443, 1194],
                        'dnsServerList' => ['@GW4@', '9.9.9.9', '@GW6@'],
                    ],
                ),
                0,
                false,
                'openvpn',
                'openvpn'
            )
        );
    }
}
