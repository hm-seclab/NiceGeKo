<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

final class IpInfo
{
    public function __construct(private string $countryCode, private string $geoUri) {}

    public function countryCode(): string
    {
        return $this->countryCode;
    }

    public function geoUri(): string
    {
        return $this->geoUri;
    }
}
