<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

final class NullLogger implements LoggerInterface
{
    #[\Override]
    public function warning(string $logMessage): void
    {
        // NOP
    }

    #[\Override]
    public function error(string $logMessage): void
    {
        // NOP
    }

    #[\Override]
    public function info(string $logMessage): void
    {
        // NOP
    }

    #[\Override]
    public function debug(string $logMessage): void
    {
        // NOP
    }
}
