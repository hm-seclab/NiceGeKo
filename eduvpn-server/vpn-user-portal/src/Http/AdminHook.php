<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

use Vpn\Portal\TplInterface;

/**
 * Determines whether the current user is an "Admin", i.e. has extra
 * capabilities in the portal.
 *
 * It also augments the template engine with a boolean indicating whether the
 * user is "Admin", to show the admin portal options.
 */
final class AdminHook extends AbstractHook implements HookInterface
{
    /**
     * @param array<string> $adminPermissionList
     * @param array<string> $adminUserIdList
     */
    public function __construct(private array $adminPermissionList, private array $adminUserIdList, private TplInterface &$templateEngine) {}

    #[\Override]
    public function afterAuth(Request $request, UserInfo &$userInfo): ?Response
    {
        if ($this->isAdmin($userInfo)) {
            $userInfo->makeAdmin();
            $this->templateEngine->addDefault('isAdmin', true);
        }

        return null;
    }

    private function isAdmin(UserInfo $userInfo): bool
    {
        if (\in_array($userInfo->userId(), $this->adminUserIdList, true)) {
            return true;
        }

        return $userInfo->hasAnyPermission($this->adminPermissionList);
    }
}
