<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http\Auth;

use Vpn\Portal\Http\Exception\HttpException;
use Vpn\Portal\Http\Request;
use Vpn\Portal\Http\UserInfo;

final class ClientCertAuthModule extends AbstractAuthModule
{
    #[\Override]
    public function userInfo(Request $request): UserInfo
    {
        if (null === $remoteUser = $request->optionalHeader('REMOTE_USER')) {
            throw new HttpException('client certificate authentication failed, no certificate provided', 400);
        }

        return new UserInfo($remoteUser, []);
    }
}
