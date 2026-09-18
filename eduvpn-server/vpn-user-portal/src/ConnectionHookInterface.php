<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

interface ConnectionHookInterface
{
    public function connect(NodeInfo $nodeInfo, string $userId, string $profileId, string $vpnProto, string $connectionId, string $ipFour, string $ipSix, ?string $originatingIp, ?IpInfo $ipInfo): void;
    public function disconnect(NodeInfo $nodeInfo, string $userId, string $profileId, string $vpnProto, string $connectionId, string $ipFour, string $ipSix, int $bytesIn, int $bytesOut): void;
}
