<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http\Auth;

use Vpn\Portal\Cfg\OidcAuthConfig;
use Vpn\Portal\Http\RedirectResponse;
use Vpn\Portal\Http\Request;
use Vpn\Portal\Http\Response;
use Vpn\Portal\Http\UserInfo;

final class OidcAuthModule extends AbstractAuthModule
{
    public function __construct(private OidcAuthConfig $config) {}

    #[\Override]
    public function userInfo(Request $request): UserInfo
    {
        $attributeNameValueList = [];
        foreach ($this->config->permissionAttributeList() as $permissionAttribute) {
            if (null !== $attributeNameValue = self::optionalHeader($request, $permissionAttribute)) {
                // OIDCClaimDelimiter for multi-valued claims (default is ",")
                if ('' === $claimDelimiter = $this->config->claimDelimiter()) {
                    // we need to check if the value is not an empty string to
                    // keep vimeo/psalm static code analysis happy...
                    // if it is an empty string, just default to "," again...
                    $claimDelimiter = ',';
                }
                // after splitting on "delimiter", make sure the values are
                // "trimmed"
                $attributeNameValueList[$permissionAttribute] = array_map(fn(string $v): string => trim($v), explode($claimDelimiter, $attributeNameValue));
            }
        }

        return new UserInfo(
            self::requireHeader($request, $this->config->userIdAttribute()),
            self::flattenPermissionList($attributeNameValueList, $this->config->permissionAttributeList())
        );
    }

    #[\Override]
    public function triggerLogout(Request $request): Response
    {
        return new RedirectResponse(
            // we redirect back to OIDCRedirectURI as defined in the Apache
            // configuration with the "logout" query parameter
            // @see https://github.com/zmartzone/mod_auth_openidc/wiki#9-how-do-i-logout-users
            $request->getRootUri() . 'redirect_uri?' . http_build_query(['logout' => $request->getRootUri()])
        );
    }

    /**
     * Sometimes the `REDIRECT_` prefix is added to the HTTP request headers,
     * make sure to also check with this prefix.
     *
     * @see https://codeberg.org/eduVPN/vpn-user-portal/issues/3
     * @see https://stackoverflow.com/questions/3050444/when-setting-environment-variables-in-apache-rewriterule-directives-what-causes
     */
    private static function optionalHeader(Request $request, string $headerName): ?string
    {
        if (null !== $headerValue = $request->optionalHeader($headerName)) {
            return $headerValue;
        }

        return $request->optionalHeader('REDIRECT_' . $headerName);
    }

    /**
     * Sometimes the `REDIRECT_` prefix is added to the HTTP request headers,
     * make sure to also check with this prefix.
     *
     * @see https://codeberg.org/eduVPN/vpn-user-portal/issues/3
     * @see https://stackoverflow.com/questions/3050444/when-setting-environment-variables-in-apache-rewriterule-directives-what-causes
     */
    private static function requireHeader(Request $request, string $headerName): string
    {
        // turn the logic around here by first searching for the variant with
        // REDIRECT_ prefix...
        if (null !== $headerValue = $request->optionalHeader('REDIRECT_' . $headerName)) {
            return $headerValue;
        }

        // ...to make sure the error message references the header name without
        // prefix if it is missing
        return $request->requireHeader($headerName);
    }
}
