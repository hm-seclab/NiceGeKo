<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Cfg;

use Vpn\Portal\Extractor;

final class StaticPermissionsConfig
{
    private const DEFAULT_ATTRIBUTE_NAME = 'memberOf';

    public function __construct(
        /** @var array<mixed> $d */
        private array $d
    ) {}

    public function permissionsFile(): string
    {
        return Extractor::requireString(
            $this->d,
            'permissionsFile',
            \sprintf('%s/config/static_permissions.json', Extractor::requireString($this->d, 'baseDir'))
        );
    }

    public function isLivePermissionSource(): bool
    {
        return Extractor::requireBool($this->d, 'isLivePermissionSource', false);
    }

    public function defaultAttributeName(): string
    {
        return Extractor::requireString($this->d, 'defaultAttributeName', self::DEFAULT_ATTRIBUTE_NAME);
    }
}
