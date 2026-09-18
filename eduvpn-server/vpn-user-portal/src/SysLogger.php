<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

final class SysLogger implements LoggerInterface
{
    public function __construct(string $appName)
    {
        openlog($appName, \LOG_ODELAY, \LOG_USER);
    }

    public function __destruct()
    {
        closelog();
    }

    #[\Override]
    public function warning(string $logMessage): void
    {
        syslog(\LOG_WARNING, $logMessage);
    }

    #[\Override]
    public function error(string $logMessage): void
    {
        syslog(\LOG_ERR, $logMessage);
    }

    #[\Override]
    public function info(string $logMessage): void
    {
        syslog(\LOG_INFO, $logMessage);
    }

    #[\Override]
    public function debug(string $logMessage): void
    {
        syslog(\LOG_DEBUG, $logMessage);
    }
}
