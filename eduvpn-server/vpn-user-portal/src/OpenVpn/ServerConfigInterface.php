<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\OpenVpn;

use Vpn\Portal\Cfg\ProfileConfig;

interface ServerConfigInterface
{
    /**
     * @return array<string,string>
     */
    public function getProfile(ProfileConfig $profileConfig, int $nodeNumber, bool $preferAes, string $vpnUser, string $vpnGroup): array;
}
