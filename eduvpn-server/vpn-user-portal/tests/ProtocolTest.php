<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use PHPUnit\Framework\TestCase;
use Vpn\Portal\Cfg\ProfileConfig;
use Vpn\Portal\Cfg\WireGuardConfig;
use Vpn\Portal\Exception\ProtocolException;
use Vpn\Portal\Protocol;
use Vpn\Portal\ProtoSupport;

/**
 * @covers \Vpn\Portal\Protocol
 *
 * @uses \Vpn\Portal\Cfg\ProfileConfig
 * @uses \Vpn\Portal\Cfg\WireGuardConfig
 */
final class ProtocolTest extends TestCase
{
    private const WIREGUARD_PUBLIC_KEY = 'vb+cQ2qTSwNjcnht2cURSubD/NC8CsD0/QGygtrb5Es=';

    public function testParseMimeType(): void
    {
        static::assertSame(
            ['openvpn+udp','openvpn+tcp','wireguard+udp'],
            Protocol::parseMimeType(null)->toArray()
        );
        static::assertSame(
            ['openvpn+udp','openvpn+tcp','wireguard+udp'],
            Protocol::parseMimeType('foo')->toArray()
        );
        static::assertSame(
            ['openvpn+udp','openvpn+tcp'],
            Protocol::parseMimeType('application/x-openvpn-profile')->toArray()
        );
        static::assertSame(
            ['openvpn+udp','openvpn+tcp'],
            Protocol::parseMimeType('application/x-openvpn-profile, application/x-openvpn-profile')->toArray()
        );
        static::assertSame(
            ['wireguard+udp'],
            Protocol::parseMimeType('application/x-wireguard-profile')->toArray()
        );
        static::assertSame(
            ['wireguard+udp'],
            Protocol::parseMimeType('application/x-wireguard-profile, foo/bar')->toArray()
        );
        static::assertSame(
            ['openvpn+udp','openvpn+tcp','wireguard+udp'],
            Protocol::parseMimeType('application/x-wireguard-profile, application/x-openvpn-profile')->toArray()
        );
        static::assertSame(
            ['openvpn+udp','openvpn+tcp','wireguard+udp','wireguard+tcp'],
            Protocol::parseMimeType('application/x-wireguard-profile, application/x-openvpn-profile, application/x-wireguard+tcp-profile')->toArray()
        );
        static::assertSame(
            ['wireguard+tcp'],
            Protocol::parseMimeType('application/x-wireguard+tcp-profile')->toArray()
        );
    }

    /**
     * This is the default deployment, with OpenVPN and WireGuard enabled, but
     * no WireGuard+TCP support yet, neither in client, nor in server. The
     * client does NOT prefer TCP.
     */
    public function testDefault(): void
    {
        static::assertSame(
            ['openvpn+udp', 'openvpn+tcp', 'wireguard+udp'],
            Protocol::determine(
                self::wgConfig(),
                self::profileConfig(),
                new ProtoSupport(oUdp: true, oTcp: true, wUdp: true, wTcp: false),
                self::WIREGUARD_PUBLIC_KEY,
                false
            )
        );
    }

    /**
     * This is the default deployment, with OpenVPN and WireGuard enabled, but
     * no WireGuard+TCP support yet, neither in client, nor in server. The
     * client DOES prefer TCP.
     */
    public function testDefaultPreferTcp(): void
    {
        static::assertSame(
            ['openvpn+tcp', 'openvpn+udp', 'wireguard+udp'],
            Protocol::determine(
                self::wgConfig(),
                self::profileConfig(),
                new ProtoSupport(oUdp: true, oTcp: true, wUdp: true, wTcp: false),
                self::WIREGUARD_PUBLIC_KEY,
                true
            )
        );
    }

    /**
     * This is the default deployment, with OpenVPN and WireGuard enabled, but
     * no WireGuard+TCP support yet, neither in client, nor in server. The
     * preferred protocol in the server is *WireGuard*. The client does NOT
     * prefer TCP.
     */
    public function testPreferWireGuard(): void
    {
        static::assertSame(
            ['wireguard+udp', 'openvpn+udp', 'openvpn+tcp'],
            Protocol::determine(
                self::wgConfig(),
                self::profileConfig(['preferredProto' => 'wireguard']),
                new ProtoSupport(oUdp: true, oTcp: true, wUdp: true, wTcp: false),
                self::WIREGUARD_PUBLIC_KEY,
                false
            )
        );
    }

    /**
     * This is the default deployment, with OpenVPN and WireGuard enabled, but
     * no WireGuard+TCP support yet, neither in client, nor in server. The
     * preferred protocol in the server is *WireGuard*. The client DOES prefer
     * TCP.
     */
    public function testPreferWireGuardPreferTcp(): void
    {
        static::assertSame(
            ['openvpn+tcp', 'wireguard+udp', 'openvpn+udp'],
            Protocol::determine(
                self::wgConfig(),
                self::profileConfig(['preferredProto' => 'wireguard']),
                new ProtoSupport(oUdp: true, oTcp: true, wUdp: true, wTcp: false),
                self::WIREGUARD_PUBLIC_KEY,
                true
            )
        );
    }

    /**
     * This is the *recommended* deployment before WireGuard+TCP support was a
     * thing, with OpenVPN and WireGuard enabled, but only OpenVPN+TCP. The
     * preferred protocol in the server is *WireGuard*. The client does *NOT*
     * prefer TCP.
     */
    public function testRecommended(): void
    {
        static::assertSame(
            ['wireguard+udp', 'openvpn+tcp'],
            Protocol::determine(
                self::wgConfig(),
                self::profileConfig(['preferredProto' => 'wireguard', 'oUdpPortList' => []]),
                new ProtoSupport(oUdp: true, oTcp: true, wUdp: true, wTcp: false),
                self::WIREGUARD_PUBLIC_KEY,
                false
            )
        );
    }

    /**
     * This is the *recommended* deployment before WireGuard+TCP support was a
     * thing, with OpenVPN and WireGuard enabled, but only OpenVPN+TCP. The
     * preferred protocol in the server is *WireGuard*. The client *DOES*
     * prefer TCP.
     */
    public function testRecommendedPreferTcp(): void
    {
        static::assertSame(
            ['openvpn+tcp', 'wireguard+udp'],
            Protocol::determine(
                self::wgConfig(),
                self::profileConfig(['preferredProto' => 'wireguard', 'oUdpPortList' => []]),
                new ProtoSupport(oUdp: true, oTcp: true, wUdp: true, wTcp: false),
                self::WIREGUARD_PUBLIC_KEY,
                true
            )
        );
    }

    /**
     * This is the default deployment, with OpenVPN and WireGuard enabled and
     * WireGuard+TCP server support. The client does NOT support WireGuard+TCP
     * and does not prefer TCP.
     */
    public function testDefaultServerWireGuardTcp(): void
    {
        static::assertSame(
            ['openvpn+udp', 'openvpn+tcp', 'wireguard+udp'],
            Protocol::determine(
                self::wgConfig(['enableProxy' => true]),
                self::profileConfig(),
                new ProtoSupport(oUdp: true, oTcp: true, wUdp: true, wTcp: false),
                self::WIREGUARD_PUBLIC_KEY,
                false
            )
        );
    }

    /**
     * This is the default deployment, with OpenVPN and WireGuard enabled and
     * WireGuard+TCP server support. The client does NOT support WireGuard+TCP
     * and DOES prefer TCP.
     */
    public function testDefaultServerWireGuardTcpPreferTcp(): void
    {
        static::assertSame(
            ['openvpn+tcp', 'openvpn+udp', 'wireguard+udp'],
            Protocol::determine(
                self::wgConfig(['enableProxy' => true]),
                self::profileConfig(),
                new ProtoSupport(oUdp: true, oTcp: true, wUdp: true, wTcp: false),
                self::WIREGUARD_PUBLIC_KEY,
                true
            )
        );
    }

    /**
     * This is the default deployment, but with OpenVPN disabled. No
     * WireGuard+TCP support yet, neither in client, nor in server. The client
     * does NOT prefer TCP.
     */
    public function testDefaultNoOpenVpn(): void
    {
        static::assertSame(
            ['wireguard+udp'],
            Protocol::determine(
                self::wgConfig(),
                self::profileConfig(['oRangeFour' => [], 'oRangeSix' => []]),
                new ProtoSupport(oUdp: true, oTcp: true, wUdp: true, wTcp: false),
                self::WIREGUARD_PUBLIC_KEY,
                false
            )
        );
    }

    /**
     * This is the default deployment, but with OpenVPN disabled. No
     * WireGuard+TCP support yet, neither in client, nor in server. The client
     * DOES prefer TCP.
     *
     * NOTE: this is interesting, the client has "Prefer TCP", but the server
     * just ignores it and provides a WireGuard+UDP config...
     */
    public function testDefaultNoOpenVpnPreferTcp(): void
    {
        static::assertSame(
            ['wireguard+udp'],
            Protocol::determine(
                self::wgConfig(),
                self::profileConfig(['oRangeFour' => [], 'oRangeSix' => []]),
                new ProtoSupport(oUdp: true, oTcp: true, wUdp: true, wTcp: false),
                self::WIREGUARD_PUBLIC_KEY,
                true
            )
        );
    }

    /**
     * This is the default deployment, but with WireGuard disabled. The client
     * does NOT prefer TCP.
     */
    public function testDefaultNoWireGuard(): void
    {
        static::assertSame(
            ['openvpn+udp', 'openvpn+tcp'],
            Protocol::determine(
                self::wgConfig(),
                self::profileConfig(['wRangeFour' => [], 'wRangeSix' => []]),
                new ProtoSupport(oUdp: true, oTcp: true, wUdp: true, wTcp: false),
                self::WIREGUARD_PUBLIC_KEY,
                false
            )
        );
    }

    /**
     * This is the default deployment, but with WireGuard disabled. The client
     * DOES prefer TCP.
     */
    public function testDefaultNoWireGuardPreferTcp(): void
    {
        static::assertSame(
            ['openvpn+tcp', 'openvpn+udp'],
            Protocol::determine(
                self::wgConfig(),
                self::profileConfig(['wRangeFour' => [], 'wRangeSix' => []]),
                new ProtoSupport(oUdp: true, oTcp: true, wUdp: true, wTcp: false),
                self::WIREGUARD_PUBLIC_KEY,
                true
            )
        );
    }

    /**
     * The server only supports WireGuard+TCP, but the client does not. This
     * should result in an error.
     */
    public function testServerWireGuardTcpOnly(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('no common VPN protocol [CLIENT=openvpn+udp,openvpn+tcp,wireguard+udp SERVER=wireguard+tcp]');
        Protocol::determine(
            self::wgConfig(['enableProxy' => true, 'onlyProxy' => true]),
            self::profileConfig(['oRangeFour' => [], 'oRangeSix' => []]),
            new ProtoSupport(oUdp: true, oTcp: true, wUdp: true, wTcp: false),
            self::WIREGUARD_PUBLIC_KEY,
            false
        );
    }

    /**
     * @param array<mixed> $configOverride
     */
    private static function profileConfig(array $configOverride = []): ProfileConfig
    {
        return new ProfileConfig(
            array_merge(
                [
                    'profileId' => 'default',
                    'displayName' => 'Default',
                    'hostName' => 'vpn.example',
                    'dnsServerList' => ['9.9.9.9', '2620:fe::fe'],
                    'wRangeFour' => '10.43.43.0/24',
                    'wRangeSix' => 'fd43::/64',
                    'oRangeFour' => '10.42.42.0/24',
                    'oRangeSix' => 'fd42::/64',
                ],
                $configOverride
            )
        );
    }

    /**
     * @param array<mixed> $configOverride
     */
    private static function wgConfig(array $configOverride = []): WireGuardConfig
    {
        return new WireGuardConfig(
            array_merge(
                [],
                $configOverride
            )
        );
    }
}
