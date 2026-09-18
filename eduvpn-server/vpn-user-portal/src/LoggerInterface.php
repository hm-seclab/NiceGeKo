<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

interface LoggerInterface
{
    public function warning(string $logMessage): void;

    public function error(string $logMessage): void;

    public function info(string $logMessage): void;

    public function debug(string $logMessage): void;
}
