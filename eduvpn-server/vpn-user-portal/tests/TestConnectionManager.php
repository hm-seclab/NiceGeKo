<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use DateTimeImmutable;
use Vpn\Portal\Cfg\Config;
use Vpn\Portal\ConnectionHooks;
use Vpn\Portal\ConnectionManager;
use Vpn\Portal\NullLogger;
use Vpn\Portal\Storage;
use Vpn\Portal\VpnDaemon;

final class TestConnectionManager extends ConnectionManager
{
    public function __construct(Config $config, VpnDaemon $vpnDaemon, Storage $storage)
    {
        parent::__construct(
            $config,
            $vpnDaemon,
            $storage,
            new ConnectionHooks(new NullLogger()),
            new NullLogger()
        );
        $this->dateTime = new DateTimeImmutable('2022-01-01T09:00:00+00:00');
    }

    #[\Override]
    protected function getRandomBytes(): string
    {
        return str_repeat("\x00", 32);
    }
}
