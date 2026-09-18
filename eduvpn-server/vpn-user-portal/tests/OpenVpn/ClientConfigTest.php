<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\OpenVPN\Tests;

use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vpn\Portal\Cfg\OpenVpnConfig;
use Vpn\Portal\Cfg\ProfileConfig;
use Vpn\Portal\OpenVpn\CA\CaInfo;
use Vpn\Portal\OpenVpn\CA\CertInfo;
use Vpn\Portal\OpenVpn\ClientConfig;
use Vpn\Portal\OpenVpn\TlsCrypt;
use Vpn\Portal\Tests\TestKeys;

/**
 * @internal
 *
 * @coversNothing
 */
final class ClientConfigTest extends TestCase
{
    public function testMtu(): void
    {
        $tmpDir = \sprintf('%s/vpn-user-portal-%s', sys_get_temp_dir(), bin2hex(random_bytes(32)));
        mkdir($tmpDir);
        $tlsCryptDefault = TestKeys::tlsCrypt($tmpDir, 'default');

        $dateTime = new DateTimeImmutable('2026-01-01T08:00:00+00:00');

        $clientConfig = new ClientConfig(
            openVpnConfig: new OpenVpnConfig(['setMtu' => 1392]),
            portalUrl: 'https://vpn.example.org/vpn-user-portal',
            nodeNumber: 0,
            profileConfig: new ProfileConfig(
                [
                    'displayName' => 'Default',
                    'profileId' => 'default',
                    'hostName' => 'vpn.example.org',
                ]
            ),
            caInfo: new CaInfo(
                pemCert: '--- CA Cert ---',
                validFrom: $dateTime->sub(new DateInterval('P1D'))->getTimestamp(),
                validTo: $dateTime->add(new DateInterval('P1Y'))->getTimestamp()
            ),
            tlsCrypt: new TlsCrypt($tmpDir),
            certInfo: new CertInfo(
                pemCert: '--- Client Cert ---',
                pemKey: '--- Client Key ---'
            ),
            vpnProto: 'x',
            expiresAt: $dateTime->add(new DateInterval('P90D')),
        );

        static::assertSame(
            <<<EOF
                # Portal: https://vpn.example.org/vpn-user-portal
                # Profile: Default (default)
                # Expires: 2026-04-01T08:00:00+00:00

                dev tun
                client
                nobind
                remote-cert-tls server
                verb 3
                server-poll-timeout 10
                tls-version-min 1.3
                data-ciphers AES-256-GCM:CHACHA20-POLY1305
                reneg-sec 0
                <ca>
                --- CA Cert ---
                </ca>
                <cert>
                --- Client Cert ---
                </cert>
                <key>
                --- Client Key ---
                </key>
                <tls-crypt>
                {$tlsCryptDefault}
                </tls-crypt>
                tun-mtu 1392
                remote vpn.example.org 1194 udp
                remote vpn.example.org 1194 tcp
                EOF,
            $clientConfig->get(includeComments: true)
        );
    }
}
