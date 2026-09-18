<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

use Vpn\Portal\Http\Exception\HttpException;

/**
 * This hook is used to check if a user is disabled before allowing any other
 * actions except login.
 */
final class DisabledUserHook extends AbstractHook implements HookInterface
{
    #[\Override]
    public function afterAuth(Request $request, UserInfo &$userInfo): ?Response
    {
        // allow "Logout", even when the user has no permission to access the
        // portal
        if ('POST' === $request->getRequestMethod() && '/_logout' === $request->getPathInfo()) {
            return null;
        }

        if ($userInfo->isDisabled()) {
            throw new HttpException('your account has been disabled by an administrator', 403);
        }

        return null;
    }
}
