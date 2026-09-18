<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\OpenVpn\CA;

final class CertInfo
{
    public function __construct(private string $pemCert, private string $pemKey) {}

    public function pemCert(): string
    {
        return trim($this->pemCert);
    }

    public function pemKey(): string
    {
        return trim($this->pemKey);
    }
}
