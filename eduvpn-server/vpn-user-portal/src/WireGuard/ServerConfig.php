<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\WireGuard;

use Vpn\Portal\Cfg\WireGuardConfig;
use Vpn\Portal\Exception\ServerConfigException;
use Vpn\Portal\FileIO;

final class ServerConfig
{
    public function __construct(
        private string $keyDir,
        private WireGuardConfig $wgConfig
    ) {
        // make sure "keyDir" exists
        FileIO::mkdir($keyDir);
    }

    /**
     * @param array<\Vpn\Portal\Cfg\ProfileConfig> $profileConfigList
     *
     * @return array<mixed>
     */
    public function get(array $profileConfigList, int $nodeNumber, string $publicKey): ?array
    {
        $ipFourList = [];
        $ipSixList = [];
        foreach ($profileConfigList as $profileConfig) {
            if (!$profileConfig->wSupport()) {
                // we only want WireGuard profiles
                continue;
            }
            $ipFourList[] = $profileConfig->wRangeFour($nodeNumber)->firstHostPrefix();
            $ipSixList[] = $profileConfig->wRangeSix($nodeNumber)->firstHostPrefix();
        }

        if (0 === \count($ipFourList) || 0 === \count($ipSixList)) {
            // apparently we did not have any WireGuard profiles...
            return null;
        }

        $this->registerPublicKey($nodeNumber, $publicKey);

        return array_merge(
            [
                'Address' => array_merge($ipFourList, $ipSixList),
                'ListenPort' => $this->wgConfig->listenPort(),
            ],
            null !== $this->wgConfig->firewallMark() ? ['FwMark' => $this->wgConfig->firewallMark()] : [],
            null !== $this->wgConfig->setMtu() ? ['MTU' => $this->wgConfig->setMtu()] : []
        );
    }

    private function registerPublicKey(int $nodeNumber, string $publicKey): void
    {
        $publicKeyFile = \sprintf('%s/wireguard.%d.public.key', $this->keyDir, $nodeNumber);
        if (!FileIO::exists($publicKeyFile)) {
            // we do not yet know this node's public key, write it
            FileIO::write($publicKeyFile, $publicKey);

            return;
        }

        // we already know this node's public key... compare it to what we get,
        // it MUST be the same!
        if ($publicKey !== FileIO::read($publicKeyFile)) {
            throw new ServerConfigException(\sprintf('node "%d" already registered a public key, but it does not match anymore, delete the existing public key first from "/var/lib/vpn-user-portal/keys"', $nodeNumber));
        }
    }
}
