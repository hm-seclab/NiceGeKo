<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Cfg;

use Vpn\Portal\Extractor;

final class OpenVpnConfig
{
    public function __construct(
        /** @var array<mixed> $d */
        private array $d
    ) {}

    public function singleProcess(): bool
    {
        return Extractor::requireBool($this->d, 'singleProcess', false);
    }

    public function setMtu(): ?int
    {
        return Extractor::optionalInt($this->d, 'setMtu');
    }
}
