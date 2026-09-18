<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http\Auth;

use Vpn\Portal\Cfg\MellonAuthConfig;
use Vpn\Portal\Http\RedirectResponse;
use Vpn\Portal\Http\Request;
use Vpn\Portal\Http\Response;
use Vpn\Portal\Http\UserInfo;

final class MellonAuthModule extends AbstractAuthModule
{
    public function __construct(private MellonAuthConfig $config) {}

    #[\Override]
    public function userInfo(Request $request): UserInfo
    {
        $userIdAttribute = $this->config->userIdAttribute();
        $nameIdSerialization = $this->config->nameIdSerialization();
        $permissionAttributeList = $this->config->permissionAttributeList();

        $userId = trim(strip_tags($request->requireHeader($userIdAttribute)));

        if ($nameIdSerialization) {
            if (\in_array($userIdAttribute, ['MELLON_NAME_ID', 'MELLON_urn:oid:1_3_6_1_4_1_5923_1_1_1_10'], true)) {
                // only for NAME_ID and eduPersonTargetedID, serialize it the way Shibboleth does
                // it by prefixing it with the IdP entityID and SP entityID
                $idpEntityId = $request->requireHeader('MELLON_IDP');
                $spEntityId = $this->config->spEntityId();
                $userId = \sprintf('%s!%s!%s', $idpEntityId, $spEntityId, $userId);
            }
        }

        $attributeNameValueList = [];
        foreach ($permissionAttributeList as $permissionAttribute) {
            if (null !== $attributeNameValue = $request->optionalHeader($permissionAttribute)) {
                $attributeNameValueList[$permissionAttribute] = explode(';', $attributeNameValue);
            }
        }

        return new UserInfo(
            $userId,
            self::flattenPermissionList($attributeNameValueList, $permissionAttributeList)
        );
    }

    #[\Override]
    public function triggerLogout(Request $request): Response
    {
        return new RedirectResponse(
            $request->getScheme() . '://' . $request->getAuthority() . '/saml/logout?' . http_build_query(['ReturnTo' => $request->requireReferrer()])
        );
    }
}
