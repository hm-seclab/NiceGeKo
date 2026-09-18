<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http\Auth;

use Vpn\Portal\FileIO;
use Vpn\Portal\Http\JsonResponse;
use Vpn\Portal\Http\Request;
use Vpn\Portal\Http\Response;
use Vpn\Portal\Http\UserInfo;

final class AdminApiAuthModule extends AbstractAuthModule
{
    public function __construct(private string $adminApiKeyFile, private string $authRealm = 'Protected Area') {}

    #[\Override]
    public function userInfo(Request $request): ?UserInfo
    {
        if (null === $authHeader = $request->optionalHeader('HTTP_AUTHORIZATION')) {
            return null;
        }
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }
        $userAuthToken = substr($authHeader, 7);

        if (!hash_equals(FileIO::read($this->adminApiKeyFile), $userAuthToken)) {
            return null;
        }

        return new UserInfo('!admin!api!user!', []);
    }

    #[\Override]
    public function startAuth(Request $request): Response
    {
        return new JsonResponse(['error' => 'authentication required'], ['WWW-Authenticate' => 'Bearer realm="' . $this->authRealm . '"'], 401);
    }
}
