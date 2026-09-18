<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

final class ProtoSupport
{
    public function __construct(
        public bool $oUdp = false,
        public bool $oTcp = false,
        public bool $wUdp = false,
        public bool $wTcp = false
    ) {}

    /**
     * @return array<string>
     */
    public function toArray(): array
    {
        return array_merge(
            $this->oUdp ? ['openvpn+udp'] : [],
            $this->oTcp ? ['openvpn+tcp'] : [],
            $this->wUdp ? ['wireguard+udp'] : [],
            $this->wTcp ? ['wireguard+tcp'] : []
        );
    }
}
