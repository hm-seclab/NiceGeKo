<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

use Vpn\Portal\Http\Auth\DbCredentialValidator;
use Vpn\Portal\Http\Auth\Exception\CredentialValidatorException;
use Vpn\Portal\Storage;
use Vpn\Portal\TplInterface;
use Vpn\Portal\Validator;

final class PasswdModule implements ServiceModuleInterface
{
    public function __construct(private DbCredentialValidator $dbCredentialValidator, private TplInterface $tpl, private Storage $storage) {}

    #[\Override]
    public function init(ServiceInterface $service): void
    {
        $service->get(
            '/passwd',
            fn(Request $request, UserInfo $userInfo): Response => new HtmlResponse(
                $this->tpl->render(
                    'vpnPortalPasswd',
                    [
                        'userId' => $userInfo->userId(),
                        'errorCode' => null,
                    ]
                )
            )
        );

        $service->post(
            '/passwd',
            function (Request $request, UserInfo $userInfo): Response {
                $userPass = $request->requirePostParameter('userPass', fn(string $s) => Validator::userAuthPass($s));
                $newUserPass = $request->requirePostParameter('newUserPass', fn(string $s) => Validator::userPass($s));
                $newUserPassConfirm = $request->requirePostParameter('newUserPassConfirm', fn(string $s) => Validator::userPass($s));

                try {
                    $this->dbCredentialValidator->validate($userInfo->userId(), $userPass);
                } catch (CredentialValidatorException) {
                    return new HtmlResponse(
                        $this->tpl->render(
                            'vpnPortalPasswd',
                            [
                                'userId' => $userInfo->userId(),
                                'errorCode' => 'wrongPassword',
                            ]
                        )
                    );
                }

                if ($newUserPass !== $newUserPassConfirm) {
                    return new HtmlResponse(
                        $this->tpl->render(
                            'vpnPortalPasswd',
                            [
                                'userId' => $userInfo->userId(),
                                'errorCode' => 'noMatchingPassword',
                            ]
                        )
                    );
                }

                $this->storage->localUserUpdatePassword($userInfo->userId(), self::generatePasswordHash($newUserPass));

                return new RedirectResponse($request->getRootUri() . 'account');
            }
        );
    }

    public static function generatePasswordHash(string $userPass): string
    {
        return sodium_crypto_pwhash_str(
            $userPass,
            \SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            \SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
        );
    }
}
