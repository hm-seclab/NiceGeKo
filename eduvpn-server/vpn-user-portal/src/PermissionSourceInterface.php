<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

interface PermissionSourceInterface
{
    /**
     * Get current permissions for users directly from the source.
     *
     * If no permissions are available, or the user no longer exists, an empty
     * array is returned.
     *
     * @return array<string>
     */
    public function permissionsForUser(string $userId): array;
}
