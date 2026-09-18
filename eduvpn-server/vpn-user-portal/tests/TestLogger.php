<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use Vpn\Portal\LoggerInterface;

final class TestLogger implements LoggerInterface
{
    /** @var array<string> */
    private array $logMessages = [];

    #[\Override]
    public function warning(string $logMessage): void
    {
        $this->logMessages[] = '[W] ' . $logMessage;
    }

    #[\Override]
    public function error(string $logMessage): void
    {
        $this->logMessages[] = '[E] ' . $logMessage;
    }

    #[\Override]
    public function info(string $logMessage): void
    {
        $this->logMessages[] = '[I] ' . $logMessage;
    }

    #[\Override]
    public function debug(string $logMessage): void
    {
        $this->logMessages[] = '[D] ' . $logMessage;
    }

    /**
     * @return array<string>
     */
    public function getAll(): array
    {
        return $this->logMessages;
    }
}
