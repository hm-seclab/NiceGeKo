<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

use Vpn\Portal\OpenVpn\ServerConfigInterface as OpenVpnServerConfigInterface;
use Vpn\Portal\WireGuard\ServerConfig as WireGuardServerConfig;

final class ServerConfig
{
    public function __construct(
        private OpenVpnServerConfigInterface $openVpnServerConfig,
        private WireGuardServerConfig $wireGuardServerConfig
    ) {}

    /**
     * @param array<\Vpn\Portal\Cfg\ProfileConfig> $profileConfigList
     *
     * @return array{openvpn?:array<string,string>,wireguard?:array<mixed>}
     */
    public function get(array $profileConfigList, int $nodeNumber, string $publicKey, bool $cpuHasAes, string $vpnUser, string $vpnGroup): array
    {
        // OpenVPN
        $oServerConfigs = [];
        foreach ($profileConfigList as $profileConfig) {
            if ($profileConfig->oSupport()) {
                $oServerConfigs = array_merge($oServerConfigs, $this->openVpnServerConfig->getProfile($profileConfig, $nodeNumber, $cpuHasAes, $vpnUser, $vpnGroup));
            }
        }

        // WireGuard
        $wServerConfig = $this->wireGuardServerConfig->get($profileConfigList, $nodeNumber, $publicKey);

        return array_merge(
            [],
            0 !== \count($oServerConfigs) ? ['openvpn' => $oServerConfigs] : [],
            null !== $wServerConfig ? ['wireguard' => $wServerConfig] : []
        );
    }
}
