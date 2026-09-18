<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

use Vpn\Portal\Http\Exception\HttpException;

/**
 * This hook is used to check if a user is allowed to access the portal/API.
 */
final class AccessHook extends AbstractHook implements HookInterface
{
    /**
     * @param array<string> $accessPermissionList
     */
    public function __construct(private array $accessPermissionList) {}

    #[\Override]
    public function afterAuth(Request $request, UserInfo &$userInfo): ?Response
    {
        // allow "Logout", even when the user has no permission to access the
        // portal
        if ('POST' === $request->getRequestMethod() && '/_logout' === $request->getPathInfo()) {
            return null;
        }

        if (!$userInfo->hasAnyPermission($this->accessPermissionList)) {
            throw new HttpException('your account does not have the required permissions', 403);
        }

        return null;
    }
}
