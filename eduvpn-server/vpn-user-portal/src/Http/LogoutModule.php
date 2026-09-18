<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

/**
 * Registers an endpoint that the portal "POSTs" to in order to trigger
 * the logout action. It will _also_ allow for authentication mechanisms to
 * "do something extra" in case logout is triggered, for example stop the SAML
 * session when using a SAML authentication backend.
 *
 * NOTE: not all authentication mechanisms support logout, e.g. BasicAuth or
 * ClientCertAuth, they will require the user to close the browser or restart
 * the device.
 */
final class LogoutModule implements ServiceModuleInterface
{
    public function __construct(private AuthModuleInterface $authModule, private SessionInterface $session) {}

    #[\Override]
    public function init(ServiceInterface $service): void
    {
        $service->post(
            '/_logout',
            function (Request $request, UserInfo $userInfo): Response {
                // destroy our local session before triggering any (external)
                // mechanism to facilitate logout
                $this->session->destroy();

                return $this->authModule->triggerLogout($request);
            }
        );
    }
}
