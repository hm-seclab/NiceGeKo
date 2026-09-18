<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * eduVPN mTLS Posture Check Extension
 */

namespace Vpn\Portal\Cfg;

use Vpn\Portal\Extractor;

final class PostureCheckConfig
{
    public function __construct(
        /** @var array<mixed> $d */
        private array $d
    ) {}

    public function wazuhApiUrl(): string
    {
        return Extractor::requireString($this->d, 'wazuhApiUrl');
    }

    public function wazuhUser(): string
    {
        return Extractor::requireString($this->d, 'wazuhUser');
    }

    public function wazuhPass(): string
    {
        return Extractor::requireString($this->d, 'wazuhPass');
    }

    /**
     * Path to CA certificate for verifying the Wazuh API TLS connection.
     * Empty string means skip TLS verification (for internal networks).
     */
    public function wazuhCaCert(): string
    {
        return Extractor::requireString($this->d, 'wazuhCaCert', '');
    }

    /**
     * Minimum SCA score required (0 = disabled).
     */
    public function scaMinScore(): int
    {
        return Extractor::requireInt($this->d, 'scaMinScore', 0);
    }
}
