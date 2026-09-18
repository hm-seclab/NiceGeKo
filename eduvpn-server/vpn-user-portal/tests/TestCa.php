<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use DateTimeImmutable;
use Vpn\Portal\OpenVpn\CA\CaInfo;
use Vpn\Portal\OpenVpn\CA\CaInterface;
use Vpn\Portal\OpenVpn\CA\CertInfo;

final class TestCa implements CaInterface
{
    /**
     * Get the CA root certificate.
     */
    #[\Override]
    public function caCert(): CaInfo
    {
        return new CaInfo('---CA---', 123456789, 234567890);
    }

    /**
     * Generate a certificate for the VPN server.
     */
    #[\Override]
    public function serverCert(string $serverName, string $profileId): CertInfo
    {
        return new CertInfo('---SERVER CERT---', '---SERVER KEY---');
    }

    /**
     * Generate a certificate for a VPN client.
     */
    #[\Override]
    public function clientCert(string $commonName, string $profileId, DateTimeImmutable $expiresAt): CertInfo
    {
        return new CertInfo('---CLIENT CERT---', '---CLIENT KEY---');
    }
}
