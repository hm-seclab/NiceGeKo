<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

use Vpn\Portal\Http\Auth\NullAuthModule;
use Vpn\Portal\Http\Exception\HttpException;

/**
 * Used from "oauth.php" to handle the OAuth 2 /token calls.
 */
final class OAuthTokenService extends Service implements ServiceInterface
{
    public function __construct()
    {
        // the OAuthTokenModule implements its own authentication, it does not
        // use the built-in authentication mechanism
        parent::__construct(new NullAuthModule());
    }

    #[\Override]
    public function run(Request $request): Response
    {
        try {
            return parent::run($request);
        } catch (HttpException $e) {
            return new JsonResponse(
                [
                    'error' => $e->getMessage(),
                ],
                $e->responseHeaders(),
                $e->statusCode()
            );
        }
    }
}
