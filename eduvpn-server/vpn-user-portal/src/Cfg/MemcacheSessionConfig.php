<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Cfg;

use Vpn\Portal\Extractor;

final class MemcacheSessionConfig
{
    public function __construct(
        /** @var array<mixed> $d */
        private array $d
    ) {}

    /**
     * @return array<string>
     */
    public function serverList(): array
    {
        return Extractor::requireStringArray($this->d, 'serverList');
    }
}
