<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

use DateTimeImmutable;

/**
 * Write new entry in connection_log table on "connect", close the entry on
 * "disconnect".
 */
final class ConnectionLogHook implements ConnectionHookInterface
{
    protected DateTimeImmutable $dateTime;

    public function __construct(private Storage $storage)
    {
        $this->dateTime = new DateTimeImmutable();
    }

    #[\Override]
    public function connect(NodeInfo $nodeInfo, string $userId, string $profileId, string $vpnProto, string $connectionId, string $ipFour, string $ipSix, ?string $originatingIp, ?IpInfo $ipInfo): void
    {
        $this->storage->clientConnect($userId, $profileId, $vpnProto, $connectionId, $ipFour, $ipSix, $this->dateTime);
    }

    #[\Override]
    public function disconnect(NodeInfo $nodeInfo, string $userId, string $profileId, string $vpnProto, string $connectionId, string $ipFour, string $ipSix, int $bytesIn, int $bytesOut): void
    {
        $this->storage->clientDisconnect($connectionId, $bytesIn, $bytesOut, $this->dateTime);
    }
}
