<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

/**
 * "Hooks" can extend this class to avoid needing to "implement" the method
 * they don't use. Typically a hook runs either *before* or *after*
 * authentication.
 */
class AbstractHook implements HookInterface
{
    #[\Override]
    public function beforeAuth(Request $request): ?Response
    {
        return null;
    }

    #[\Override]
    public function afterAuth(Request $request, UserInfo &$userInfo): ?Response
    {
        return null;
    }
}
