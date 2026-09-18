<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

use Vpn\Portal\Crypto\Hmac;
use Vpn\Portal\Crypto\HmacKey;
use Vpn\Portal\Json;

/**
 * Generate a HMAC of the userId to "obscure" the real userId inside the
 * application. The userId is *also* used as part of the OAuth tokens and
 * exposed to other VPN servers when "Guest Usage" is enabled. This hook
 * allows for obscuring the "real" userId for that scenario, while still
 * keeping track of the userId in case the HMAC needs to be reversed, e.g. in
 * case of abuse.
 */
final class HmacUserIdHook extends AbstractHook implements HookInterface
{
    public function __construct(private HmacKey $hmacKey) {}

    #[\Override]
    public function afterAuth(Request $request, UserInfo &$userInfo): ?Response
    {
        // store the userId obtained through the authentication backend in
        // "authData"
        $userInfo->setAuthData(
            Json::encode(
                [
                    'userId' => $userInfo->userId(),
                ]
            )
        );

        // generate a HMAC with secret from this userId and replace the userId
        $userInfo->setUserId(
            Hmac::generate(
                $userInfo->userId(),
                $this->hmacKey
            )
        );

        return null;
    }
}
