<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\OpenVpn\CA;

use DateTimeImmutable;

interface CaInterface
{
    /**
     * Get the CA root certificate.
     */
    public function caCert(): CaInfo;

    /**
     * Generate a certificate for the VPN server.
     */
    public function serverCert(string $serverName, string $profileId): CertInfo;

    /**
     * Generate a certificate for a VPN client.
     */
    public function clientCert(string $commonName, string $profileId, DateTimeImmutable $expiresAt): CertInfo;
}
