<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

use fkooman\OAuth\Server\AccessToken;

final class ApiUserInfo
{
    public function __construct(private string $userId, private AccessToken $accessToken) {}

    public function userId(): string
    {
        return $this->userId;
    }

    public function accessToken(): AccessToken
    {
        return $this->accessToken;
    }
}
