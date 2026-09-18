<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Cfg;

use Vpn\Portal\Extractor;

final class WireGuardConfig
{
    public function __construct(
        /** @var array<mixed> $d */
        private array $d
    ) {}

    public function setMtu(): ?int
    {
        return Extractor::optionalInt($this->d, 'setMtu');
    }

    public function listenPort(): int
    {
        return Extractor::requireInt($this->d, 'listenPort', 51820);
    }

    /**
     * Only support the WireGuard TCP Proxy, i.e. no WireGuard UDP support is
     * offered by the server.
     */
    public function onlyProxy(): bool
    {
        return Extractor::requireBool($this->d, 'onlyProxy', false);
    }

    public function enableProxy(): bool
    {
        return Extractor::requireBool($this->d, 'enableProxy', false);
    }

    public function proxyUrl(): ?string
    {
        return Extractor::optionalString($this->d, 'proxyUrl');
    }

    /**
     * Allow users to click the "Extend" button for manual WireGuard
     * configuration file downloads to not require loading a new WireGuard
     * configuration file in their client.
     */
    public function allowExpiryExtension(): bool
    {
        return Extractor::requireBool($this->d, 'allowExpiryExtension', false);
    }

    public function firewallMark(): ?int
    {
        return Extractor::optionalInt($this->d, 'firewallMark');
    }

    /**
     * Get a list of configuration keys NOT supported in order to warn the
     * admins on the "Info" page about it.
     *
     * @return array<string>
     */
    public function unsupportedConfigKeys(): array
    {
        $supportedKeys = [
            'setMtu',
            'listenPort',
            'onlyProxy',
            'enableProxy',
            'proxyUrl',
            'allowExpiryExtension',
            'firewallMark',
        ];

        $unsupportedKeys = [];
        foreach (array_keys($this->d) as $configKey) {
            if (!\is_string($configKey)) {
                // ignore non-string keys
                continue;
            }
            if (!\in_array($configKey, $supportedKeys, true)) {
                $unsupportedKeys[] = $configKey;
            }
        }

        return $unsupportedKeys;
    }
}
