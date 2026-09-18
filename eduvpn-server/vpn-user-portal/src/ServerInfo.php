<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

use Vpn\Portal\Cfg\OpenVpnConfig;
use Vpn\Portal\Cfg\WireGuardConfig;
use Vpn\Portal\OpenVpn\CA\CaInterface;
use Vpn\Portal\OpenVpn\TlsCrypt;

final class ServerInfo
{
    public function __construct(
        private string $portalUrl,
        private string $keyDir,
        private CaInterface $ca,
        private TlsCrypt $tlsCrypt,
        private WireGuardConfig $wgConfig,
        private OpenVpnConfig $openVpnConfig,
        private string $oauthPublicKey
    ) {}

    public function portalUrl(): string
    {
        return $this->portalUrl;
    }

    public function ca(): CaInterface
    {
        return $this->ca;
    }

    public function tlsCrypt(): TlsCrypt
    {
        return $this->tlsCrypt;
    }

    public function publicKey(int $nodeNumber): ?string
    {
        $publicKeyFile = \sprintf('%s/wireguard.%d.public.key', $this->keyDir, $nodeNumber);
        if (!FileIO::exists($publicKeyFile)) {
            return null;
        }

        return FileIO::read($publicKeyFile);
    }

    public function wgConfig(): WireGuardConfig
    {
        return $this->wgConfig;
    }

    public function openVpnConfig(): OpenVpnConfig
    {
        return $this->openVpnConfig;
    }

    public function oauthPublicKey(): string
    {
        return $this->oauthPublicKey;
    }
}
