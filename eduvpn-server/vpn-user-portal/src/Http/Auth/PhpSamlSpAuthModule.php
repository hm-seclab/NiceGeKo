<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http\Auth;

use fkooman\SAML\SP\Api\AuthOptions;
use fkooman\SAML\SP\Api\SamlAuth;
use Vpn\Portal\Cfg\PhpSamlSpAuthConfig;
use Vpn\Portal\Http\Exception\HttpException;
use Vpn\Portal\Http\RedirectResponse;
use Vpn\Portal\Http\Request;
use Vpn\Portal\Http\Response;
use Vpn\Portal\Http\UserInfo;

final class PhpSamlSpAuthModule extends AbstractAuthModule
{
    private SamlAuth $samlAuth;

    public function __construct(private PhpSamlSpAuthConfig $config)
    {
        $this->samlAuth = new SamlAuth();
    }

    #[\Override]
    public function userInfo(Request $request): ?UserInfo
    {
        if (!$this->samlAuth->isAuthenticated($this->getAuthOptions())) {
            return null;
        }
        $samlAssertion = $this->samlAuth->getAssertion($this->getAuthOptions());
        if (null === $userId = $samlAssertion->getFirstAttributeValue($this->config->userIdAttribute())) {
            throw new HttpException(\sprintf('missing "userIdAttribute" attribute "%s" in SAML assertion', $this->config->userIdAttribute()), 500);
        }

        return new UserInfo(
            $userId,
            self::flattenPermissionList($samlAssertion->getAttributes(), $this->config->permissionAttributeList())
        );
    }

    #[\Override]
    public function startAuth(Request $request): Response
    {
        return new RedirectResponse($this->samlAuth->getLoginURL($this->getAuthOptions()));
    }

    #[\Override]
    public function triggerLogout(Request $request): Response
    {
        return new RedirectResponse($this->samlAuth->getLogoutURL($request->requireReferrer()));
    }

    private function getAuthOptions(): ?AuthOptions
    {
        if (null === $authnContext = $this->config->authnContext()) {
            return null;
        }

        return AuthOptions::init()->withAuthnContextClassRef($authnContext);
    }
}
