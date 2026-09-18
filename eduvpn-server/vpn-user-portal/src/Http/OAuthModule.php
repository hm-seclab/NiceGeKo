<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

use fkooman\OAuth\Server\Exception\OAuthException;
use fkooman\OAuth\Server\OAuthServer;
use Vpn\Portal\Expiry;
use Vpn\Portal\Http\Exception\HttpException;
use Vpn\Portal\TplInterface;

final class OAuthModule implements ServiceModuleInterface
{
    public function __construct(private OAuthServer $oauthServer, private TplInterface $tpl, private Expiry $sessionExpiry) {}

    #[\Override]
    public function init(ServiceInterface $service): void
    {
        $service->get(
            '/oauth/authorize',
            function (Request $request, UserInfo $userInfo): Response {
                try {
                    $authorizeRequest = $this->oauthServer->handleAuthorizeRequest($userInfo->userId(), $this->sessionExpiry->expiresIn($userInfo));

                    return new HtmlResponse(
                        $this->tpl->render(
                            'authorizeOAuthClient',
                            [
                                'authorizeRequest' => $authorizeRequest,
                                // we need to also add this variable for the
                                // template engine to pick up the
                                // %display_name% for translations...
                                'display_name' => $authorizeRequest->clientInfo->displayName(),
                            ]
                        ),
                        [
                            'Cache-Control' => 'no-store',
                            'Pragma' => 'no-cache',
                        ]
                    );
                } catch (OAuthException $e) {
                    throw new HttpException(\sprintf('ERROR: %s (%s)', $e->getMessage(), $e->getDescription() ?? ''), $e->getStatusCode());
                }
            }
        );
    }
}
