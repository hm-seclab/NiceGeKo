<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Tests;

use Vpn\Portal\Http\Auth\AbstractAuthModule;
use Vpn\Portal\Http\Request;
use Vpn\Portal\Http\UserInfo;

final class DummyAuthModule extends AbstractAuthModule
{
    #[\Override]
    public function userInfo(Request $request): UserInfo
    {
        return new UserInfo('dummy', []);
    }
}
