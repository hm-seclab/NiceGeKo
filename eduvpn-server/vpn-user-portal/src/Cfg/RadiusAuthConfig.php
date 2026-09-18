<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Cfg;

use fkooman\Radius\ServerInfo;
use Vpn\Portal\Extractor;

final class RadiusAuthConfig
{
    public function __construct(
        /** @var array<mixed> $d */
        private array $d
    ) {}

    /**
     * @return array<\fkooman\Radius\ServerInfo>
     */
    public function serverList(): array
    {
        // connectionTimeout/maxTries are a "per server" option in
        // fkooman/radius >=2 and not global, but we only allow configuring it
        // "globally", so simply apply it to all servers we add...
        $maxTries = $this->maxTries();
        $connectionTimeout = $this->timeOutSec();

        $serverInfoList = [];
        $serverList = Extractor::requireStringArray($this->d, 'serverList');
        foreach ($serverList as $serverInfo) {
            // split this thing in a good way to handle IPv6 addresses
            $serverInfo = trim($serverInfo);
            if (str_starts_with($serverInfo, '[')) {
                // IPv6
                // find first closing bracket
                if (false === $brPos = strpos($serverInfo, ']')) {
                    continue;
                }
                $ipSix = substr($serverInfo, 1, $brPos - 1);
                if (false === filter_var($ipSix, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
                    // not valid IPv6 address
                    continue;
                }
                $e = explode(':', substr($serverInfo, $brPos + 2), 2);
                if (2 !== \count($e)) {
                    continue;
                }
                $serverInfoList[] = new ServerInfo(\sprintf('udp://[%s]:%d', $ipSix, (int) $e[0]), $e[1], $maxTries, $connectionTimeout);

                continue;
            }

            $e = explode(':', $serverInfo, 3);
            if (3 !== \count($e)) {
                // malformed entry
                continue;
            }

            $serverInfoList[] = new ServerInfo(\sprintf('udp://%s:%d', $e[0], (int) $e[1]), $e[2], $maxTries, $connectionTimeout);
        }

        return $serverInfoList;
    }

    public function radiusRealm(): ?string
    {
        // in the documentation we wrote "addRealm" is the option, but it seems
        // in the code we used "radiusRealm", now we support both
        return Extractor::optionalString($this->d, 'radiusRealm') ?? Extractor::optionalString($this->d, 'addRealm');
    }

    public function nasIdentifier(): ?string
    {
        return Extractor::optionalString($this->d, 'nasIdentifier');
    }

    /**
     * This is the legacy attribute handling where only an `int` could be
     * used to specify an attribute.
     */
    public function permissionAttribute(): ?int
    {
        return Extractor::optionalInt($this->d, 'permissionAttribute');
    }

    /**
     * @return array<string>
     */
    public function permissionAttributeList(): array
    {
        return Extractor::requireStringArray($this->d, 'permissionAttributeList', []);
    }

    public function requireMessageAuthenticator(): bool
    {
        return Extractor::requireBool($this->d, 'requireMessageAuthenticator', false);
    }

    private function maxTries(): int
    {
        return Extractor::requireInt($this->d, 'maxTries', 3);
    }

    private function timeOutSec(): int
    {
        return Extractor::requireInt($this->d, 'timeOutSec', 3);
    }
}
