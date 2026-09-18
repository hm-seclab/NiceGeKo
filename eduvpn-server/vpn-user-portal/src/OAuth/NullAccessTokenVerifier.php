<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\OAuth;

use fkooman\OAuth\Server\AccessToken;
use fkooman\OAuth\Server\AccessTokenVerifierInterface;

/**
 * This class does nothing. We use it instead of LocalAccessTokenVerifier
 * in the "Guest Access" scenario where we don't care whether the OAuth
 * client still exists, or whether the authorization is there.
 * We'll handle the authorization in the "GuestApiService" instead.
 */
final class NullAccessTokenVerifier implements AccessTokenVerifierInterface
{
    #[\Override]
    public function verify(AccessToken $accessToken): void
    {
        // NOP
    }
}
