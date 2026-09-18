<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http\Auth;

use fkooman\Radius\LoggerInterface as RadiusLoggerInterface;
use Vpn\Portal\LoggerInterface as VpnLoggerInterface;

final class RadiusLogger implements RadiusLoggerInterface
{
    public function __construct(private VpnLoggerInterface $vpnLogger) {}

    #[\Override]
    public function error(string $logMessage): void
    {
        $this->vpnLogger->error(\sprintf('[RADIUS] %s', $logMessage));
    }

    #[\Override]
    public function warning(string $logMessage): void
    {
        $this->vpnLogger->warning(\sprintf('[RADIUS] %s', $logMessage));
    }

    #[\Override]
    public function info(string $logMessage): void
    {
        $this->vpnLogger->info(\sprintf('[RADIUS] %s', $logMessage));
    }

    #[\Override]
    public function debug(string $logMessage): void
    {
        // we do NOT log any debugging
    }
}
