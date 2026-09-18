<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Cfg;

use Vpn\Portal\Extractor;

final class MellonAuthConfig
{
    public function __construct(
        /** @var array<mixed> $d */
        private array $d
    ) {}

    public function userIdAttribute(): string
    {
        return Extractor::requireString($this->d, 'userIdAttribute');
    }

    public function nameIdSerialization(): bool
    {
        return Extractor::requireBool($this->d, 'nameIdSerialization', false);
    }

    /**
     * @return array<string>
     */
    public function permissionAttributeList(): array
    {
        return Extractor::requireStringArray($this->d, 'permissionAttributeList', []);
    }

    public function spEntityId(): string
    {
        return Extractor::requireString($this->d, 'spEntityId');
    }
}
