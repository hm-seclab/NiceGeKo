<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http\Auth;

use Vpn\Portal\Cfg\ShibAuthConfig;
use Vpn\Portal\Http\RedirectResponse;
use Vpn\Portal\Http\Request;
use Vpn\Portal\Http\Response;
use Vpn\Portal\Http\UserInfo;

final class ShibAuthModule extends AbstractAuthModule
{
    public function __construct(private ShibAuthConfig $config) {}

    #[\Override]
    public function userInfo(Request $request): UserInfo
    {
        $attributeNameValueList = [];
        foreach ($this->config->permissionAttributeList() as $permissionAttribute) {
            if (null !== $attributeNameValue = $request->optionalHeader($permissionAttribute)) {
                $attributeValues = explode(';', $attributeNameValue);
                if ($this->config->encodedAttributeValues()) {
                    // urldecode the attribute values as per "encoding" value
                    // set to "URL"
                    // @see https://shibboleth.atlassian.net/wiki/spaces/SP3/pages/2065334723/ContentSettings
                    $attributeValues = array_map('rawurldecode', $attributeValues);
                }
                $attributeNameValueList[$permissionAttribute] = $attributeValues;
            }
        }

        return new UserInfo(
            $request->requireHeader($this->config->userIdAttribute()),
            self::flattenPermissionList($attributeNameValueList, $this->config->permissionAttributeList())
        );
    }

    #[\Override]
    public function triggerLogout(Request $request): Response
    {
        return new RedirectResponse(
            $request->getScheme() . '://' . $request->getAuthority() . '/Shibboleth.sso/Logout?' . http_build_query(['return' => $request->requireReferrer()])
        );
    }
}
