<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

final class PermissionSourceManager
{
    /** @var array<PermissionSourceInterface> */
    private array $permissionSourceList = [];

    public function add(PermissionSourceInterface $permissionSource): void
    {
        $this->permissionSourceList[] = $permissionSource;
    }

    public function hasPermissionSources(): bool
    {
        return 0 !== \count($this->permissionSourceList);
    }

    /**
     * @return ?array<string>
     */
    public function get(string $userId): ?array
    {
        if (!$this->hasPermissionSources()) {
            // no active "Live Permission" sources
            return null;
        }

        $permissionsForUser = [];
        foreach ($this->permissionSourceList as $permissionSource) {
            $permissionsForUser = array_merge($permissionsForUser, $permissionSource->permissionsForUser($userId));
        }

        return $permissionsForUser;
    }
}
