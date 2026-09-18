<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

interface HookInterface
{
    public function beforeAuth(Request $request): ?Response;

    public function afterAuth(Request $request, UserInfo &$userInfo): ?Response;
}
