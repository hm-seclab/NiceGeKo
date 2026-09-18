<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http\Auth;

use RangeException;
use Vpn\Portal\Cfg\LogConfig;
use Vpn\Portal\Http\Auth\Exception\CredentialValidatorChallengeException;
use Vpn\Portal\Http\Auth\Exception\CredentialValidatorException;
use Vpn\Portal\Http\HtmlResponse;
use Vpn\Portal\Http\RedirectResponse;
use Vpn\Portal\Http\Request;
use Vpn\Portal\Http\Response;
use Vpn\Portal\Http\ServiceInterface;
use Vpn\Portal\Http\SessionInterface;
use Vpn\Portal\Http\UserInfo;
use Vpn\Portal\Json;
use Vpn\Portal\LoggerInterface;
use Vpn\Portal\TplInterface;
use Vpn\Portal\Validator;

final class UserPassAuthModule extends AbstractAuthModule
{
    public function __construct(private CredentialValidatorInterface $credentialValidator, private SessionInterface $session, protected TplInterface $tpl, private LoggerInterface $logger, private LogConfig $logConfig) {}

    #[\Override]
    public function init(ServiceInterface $service): void
    {
        $service->postBeforeAuth(
            '/_user_pass_auth/verify',
            function (Request $request): Response {
                $this->session->remove('_user_pass_auth_user_id');
                $this->session->remove('_user_pass_auth_permission_list');

                $authUser = $request->requirePostParameter('userName', fn(string $s) => Validator::userId($s));
                $authPass = $request->requirePostParameter('userPass', fn(string $s) => Validator::userAuthPass($s));
                $redirectTo = $request->requirePostParameter('authRedirectTo', fn(string $s) => Validator::matchesOrigin($request->getOrigin(), $s));

                try {
                    $userInfo = $this->credentialValidator->validate($authUser, $authPass);
                    $this->logger->info(
                        $this->authLogOkTemplate(
                            $userInfo->userId(),
                            $request->requireHeader('REMOTE_ADDR', fn(string $s) => Validator::ipAddress($s))
                        )
                    );
                    $this->session->set('_user_pass_auth_user_id', $userInfo->userId());
                    $this->session->set('_user_pass_auth_permission_list', self::encodePermissionList($userInfo->permissionList()));

                    return new RedirectResponse($redirectTo);
                } catch (CredentialValidatorChallengeException $e) {
                    // the authentication mechanism offers us a challenge, we
                    // as the user for it...
                    $responseBody = $this->tpl->render(
                        'userPassAuth',
                        [
                            'authState' => 'AUTH_CHALLENGE',
                            'authChallengeMessage' => $e->getMessage(),
                            'authUser' => $authUser,
                            'authRedirectTo' => $redirectTo,
                            'enableLogoutButton' => false,
                        ]
                    );

                    return new HtmlResponse($responseBody);
                } catch (CredentialValidatorException $e) {
                    $this->logger->warning(
                        $this->authLogFailTemplate(
                            $authUser,
                            $request->requireHeader('REMOTE_ADDR', fn(string $s) => Validator::ipAddress($s)),
                            $e->getMessage()
                        )
                    );

                    $responseBody = $this->tpl->render(
                        'userPassAuth',
                        [
                            'authState' => 'AUTH_FAILED',
                            'authUser' => $authUser,
                            'authRedirectTo' => $redirectTo,
                            'enableLogoutButton' => false,
                        ]
                    );

                    return new HtmlResponse($responseBody);
                }
            }
        );
    }

    #[\Override]
    public function userInfo(Request $request): ?UserInfo
    {
        if (null === $authUser = $this->session->get('_user_pass_auth_user_id')) {
            return null;
        }

        $permissionList = [];
        if (null !== $sessionValue = $this->session->get('_user_pass_auth_permission_list')) {
            $permissionList = self::decodePermissionList($sessionValue);
        }

        return new UserInfo(
            $authUser,
            $permissionList
        );
    }

    #[\Override]
    public function startAuth(Request $request): Response
    {
        $responseBody = $this->tpl->render(
            'userPassAuth',
            [
                'authState' => 'AUTH',
                'authUser' => null,
                'authRedirectTo' => $request->getUri(),
                'enableLogoutButton' => false,
            ]
        );

        return new HtmlResponse($responseBody, [], 200);
    }

    /**
     * We want to make sure when logging a user provided User ID through the
     * "Sign In" page that it can't simply contain _all_ characters. This makes
     * it easier to parse the log messages without needing complicated
     * escaping. For cases where the exact User ID is required, there is the
     * RAW_USER_ID field.
     */
    public static function sanitizeUserId(string $userId): string
    {
        if (null === $sanitizedUserId = preg_replace('/[^a-zA-Z0-9\.\-]/', '_', $userId)) {
            throw new RangeException('unable to sanitize userId');
        }

        return $sanitizedUserId;
    }

    private function authLogOkTemplate(string $userId, string $remoteAddress): string
    {
        return str_replace(
            [
                '{{USER_ID}}',
                '{{RAW_USER_ID}}',
                '{{REMOTE_ADDR}}',
            ],
            [
                self::sanitizeUserId($userId),
                $userId,
                $remoteAddress,
            ],
            $this->logConfig->authLogOkTemplate()
        );
    }

    private function authLogFailTemplate(string $userId, string $remoteAddress, string $failReason): string
    {
        return str_replace(
            [
                '{{USER_ID}}',
                '{{RAW_USER_ID}}',
                '{{REMOTE_ADDR}}',
                '{{FAIL_REASON}}',
            ],
            [
                self::sanitizeUserId($userId),
                $userId,
                $remoteAddress,
                $failReason,
            ],
            $this->logConfig->authLogFailTemplate()
        );
    }

    /**
     * @param array<string> $encodePermissionList
     */
    private static function encodePermissionList(array $encodePermissionList): string
    {
        return Json::encode($encodePermissionList);
    }

    /**
     * @return array<string>
     */
    private static function decodePermissionList(string $decodePermissionList): array
    {
        $permissionList = [];
        foreach (Json::decode($decodePermissionList) as $userPermission) {
            if (!\is_string($userPermission)) {
                throw new RangeException('permissionList MUST be of type `array<string>`');
            }
            $permissionList[] = $userPermission;
        }

        return $permissionList;
    }
}
