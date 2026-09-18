<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\OpenVpn\CA;

use DateTimeImmutable;
use RuntimeException;

final class CaInfo
{
    public function __construct(private string $pemCert, private int $validFrom, private int $validTo) {}

    public function pemCert(): string
    {
        return trim($this->pemCert);
    }

    public function validFrom(): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $this->validFrom);
    }

    public function validTo(): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $this->validTo);
    }

    public function fingerprint(): string
    {
        if (false === $fingerPrint = openssl_x509_fingerprint($this->pemCert, 'sha256')) {
            throw new RuntimeException('OpenSSL: openssl_x509_fingerprint');
        }

        return $fingerPrint;
    }
}
