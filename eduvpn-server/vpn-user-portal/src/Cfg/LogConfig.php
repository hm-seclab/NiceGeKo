<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Cfg;

use DateInterval;
use Vpn\Portal\Extractor;

final class LogConfig
{
    public function __construct(
        /** @var array<mixed> $d */
        private array $d
    ) {}

    public function syslogConnectionEvents(): bool
    {
        return Extractor::requireBool($this->d, 'syslogConnectionEvents', false);
    }

    public function originatingIp(): bool
    {
        return Extractor::requireBool($this->d, 'originatingIp', false);
    }

    public function authData(): bool
    {
        return Extractor::requireBool($this->d, 'authData', false);
    }

    public function geoIpDb(): ?string
    {
        return Extractor::optionalString($this->d, 'geoIpDb');
    }

    public function connectLogTemplate(): string
    {
        if (null !== $connectLogTemplate = Extractor::optionalString($this->d, 'connectLogTemplate')) {
            return $connectLogTemplate;
        }

        if ($this->authData()) {
            return 'CONNECT {{USER_ID}} ({{PROFILE_ID}}:{{CONNECTION_ID}}) [{{ORIGINATING_IP}} => {{IP_FOUR}},{{IP_SIX}}] [AUTH_DATA={{AUTH_DATA}}]';
        }

        return 'CONNECT {{USER_ID}} ({{PROFILE_ID}}:{{CONNECTION_ID}}) [{{ORIGINATING_IP}} => {{IP_FOUR}},{{IP_SIX}}]';
    }

    public function disconnectLogTemplate(): string
    {
        if (null !== $disconnectLogTemplate = Extractor::optionalString($this->d, 'disconnectLogTemplate')) {
            return $disconnectLogTemplate;
        }

        return 'DISCONNECT {{USER_ID}} ({{PROFILE_ID}}:{{CONNECTION_ID}})';
    }

    public function authLogOkTemplate(): string
    {
        return Extractor::requireString($this->d, 'authLogOkTemplate', 'AUTH OK [USER_ID={{USER_ID}}]');
    }

    public function authLogFailTemplate(): string
    {
        return Extractor::requireString($this->d, 'authLogFailTemplate', 'AUTH FAIL [USER_ID={{USER_ID}}]');
    }

    public function connectionLogRetentionInterval(): DateInterval
    {
        // interval before which to delete the connection log, default is `P1M`
        // (1 month)
        return new DateInterval(Extractor::requireString($this->d, 'connectionLogRetentionInterval', 'P1M'));
    }
}
