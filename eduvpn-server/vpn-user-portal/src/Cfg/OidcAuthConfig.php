<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Cfg;

use Vpn\Portal\Extractor;

final class OidcAuthConfig
{
    public function __construct(
        /** @var array<mixed> $d */
        private array $d
    ) {}

    public function userIdAttribute(): string
    {
        return Extractor::requireString($this->d, 'userIdAttribute', 'REMOTE_USER');
    }

    /**
     * @return array<string>
     */
    public function permissionAttributeList(): array
    {
        return Extractor::requireStringArray($this->d, 'permissionAttributeList', []);
    }

    /**
     * Set this to the value of OIDCClaimDelimiter you use in your Apache
     * configuration. The default is ",", but this won't work well if the
     * values of your permissions themselves contain a ",".
     */
    public function claimDelimiter(): string
    {
        return Extractor::requireString($this->d, 'claimDelimiter', ',');
    }
}
