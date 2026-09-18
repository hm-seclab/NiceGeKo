<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Cfg;

use Vpn\Portal\Extractor;

final class LdapAuthConfig
{
    public function __construct(
        /** @var array<mixed> $d */
        private array $d
    ) {}

    public function ldapUri(): string
    {
        return Extractor::requireString($this->d, 'ldapUri');
    }

    public function tlsCa(): ?string
    {
        return Extractor::optionalString($this->d, 'tlsCa');
    }

    public function tlsCert(): ?string
    {
        return Extractor::optionalString($this->d, 'tlsCert');
    }

    public function tlsKey(): ?string
    {
        return Extractor::optionalString($this->d, 'tlsKey');
    }

    public function bindDnTemplate(): ?string
    {
        return Extractor::optionalString($this->d, 'bindDnTemplate');
    }

    /**
     * @return array<string>
     */
    public function baseDn(): array
    {
        return Extractor::requireStringOrStringArray($this->d, 'baseDn');
    }

    public function userFilterTemplate(): string
    {
        return Extractor::requireString($this->d, 'userFilterTemplate');
    }

    public function userIdAttribute(): string
    {
        return Extractor::requireString($this->d, 'userIdAttribute');
    }

    public function addRealm(): ?string
    {
        return Extractor::optionalString($this->d, 'addRealm');
    }

    /**
     * @return array<string>
     */
    public function permissionAttributeList(): array
    {
        return Extractor::requireStringArray($this->d, 'permissionAttributeList', []);
    }

    public function searchBindDn(): ?string
    {
        return Extractor::optionalString($this->d, 'searchBindDn');
    }

    public function searchBindPass(): ?string
    {
        return Extractor::optionalString($this->d, 'searchBindPass');
    }

    public function isLivePermissionSource(): bool
    {
        return Extractor::requireBool($this->d, 'isLivePermissionSource', false);
    }

    /**
     * Enable LDAP for obtaining (additional) permissions during the user
     * authentication.
     */
    public function isAuthenticationPermissionSource(): bool
    {
        return Extractor::requireBool($this->d, 'isAuthenticationPermissionSource', false);
    }
}
