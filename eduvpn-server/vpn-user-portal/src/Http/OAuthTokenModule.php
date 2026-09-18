<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

use fkooman\OAuth\Server\Exception\OAuthException;
use fkooman\OAuth\Server\OAuthServer;
use Vpn\Portal\LoggerInterface;

final class OAuthTokenModule implements ServiceModuleInterface
{
    public function __construct(private OAuthServer $oauthServer, private LoggerInterface $logger) {}

    #[\Override]
    public function init(ServiceInterface $service): void
    {
        $service->postBeforeAuth(
            '/oauth/token',
            function (Request $request): Response {
                try {
                    $tokenResponse = $this->oauthServer->postToken();

                    return new Response($tokenResponse->getBody(), $tokenResponse->getHeaders(), $tokenResponse->getStatusCode());
                } catch (OAuthException $e) {
                    $this->logger->warning(\sprintf('OAuth (error=%s description=%s]', $e->getMessage(), $e->getDescription() ?? 'N/A'));
                    $jsonResponse = $e->getJsonResponse();

                    return new Response($jsonResponse->getBody(), $jsonResponse->getHeaders(), $jsonResponse->getStatusCode());
                }
            }
        );
    }
}
