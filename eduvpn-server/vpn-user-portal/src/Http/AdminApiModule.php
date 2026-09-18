<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

use DateInterval;
use DateTimeImmutable;
use fkooman\OAuth\Server\PdoStorage as OAuthStorage;
use Vpn\Portal\Cfg\Config;
use Vpn\Portal\ConnectionManager;
use Vpn\Portal\Exception\ConnectionManagerException;
use Vpn\Portal\Exception\ProtocolException;
use Vpn\Portal\Expiry;
use Vpn\Portal\Http\Exception\HttpException;
use Vpn\Portal\Protocol;
use Vpn\Portal\ServerInfo;
use Vpn\Portal\Storage;
use Vpn\Portal\Validator;
use Vpn\Portal\WireGuard\ClientConfig as WireGuardClientConfig;
use Vpn\Portal\WireGuard\Key;

final class AdminApiModule implements ServiceModuleInterface
{
    protected DateTimeImmutable $dateTime;

    public function __construct(private Config $config, private Storage $storage, private OAuthStorage $oauthStorage, private ServerInfo $serverInfo, private ConnectionManager $connectionManager, private Expiry $sessionExpiry)
    {
        $this->dateTime = new DateTimeImmutable();
    }

    #[\Override]
    public function init(ServiceInterface $service): void
    {
        $service->get(
            '/v1/user_configuration_list',
            function (Request $request, UserInfo $userInfo): Response {
                $userId = $request->requireQueryParameter('user_id', fn(string $s) => Validator::userId($s));
                if (null === $this->storage->userInfo($userId)) {
                    throw new HttpException('user does not exist', 400);
                }

                return new JsonResponse(['user_configuration_list' => $this->extractUserConfigurationList($userId)]);
            }
        );

        $service->post(
            '/v1/delete_user_authorizations',
            function (Request $request, UserInfo $userInfo): Response {
                $userId = $request->requireQueryParameter('user_id', fn(string $s) => Validator::userId($s));
                if (null === $this->storage->userInfo($userId)) {
                    throw new HttpException('user does not exist', 400);
                }

                // revoke all OAuth authorizations
                foreach ($this->oauthStorage->getAuthorizations($userId) as $clientAuthorization) {
                    $this->oauthStorage->deleteAuthorization($clientAuthorization->authKey());
                }

                return new Response(null, [], 204);
            }
        );

        $service->get(
            '/v1/connected_users',
            function (Request $request, UserInfo $userInfo): Response {
                $showAll = 'on' === $request->optionalQueryParameter('show_all', fn(string $s) => Validator::onOrOff($s));

                return new JsonResponse(
                    [
                        'connected_users' => self::extractConnectedUsers(
                            $this->connectionManager->get(showAll: $showAll)
                        ),
                    ]
                );
            }
        );

        $service->get(
            '/v1/connection_list',
            function (Request $request, UserInfo $userInfo): Response {
                $showAll = 'on' === $request->optionalQueryParameter('show_all', fn(string $s) => Validator::onOrOff($s));

                return new JsonResponse(
                    [
                        'connection_list' => $this->connectionManager->get(showAll: $showAll),
                    ]
                );
            }
        );

        $service->get(
            '/v1/users',
            fn(Request $request, UserInfo $userInfo): Response => new JsonResponse(['users' => self::extractUsers($this->storage->userList())])
        );

        $service->get(
            '/v1/disabled_users',
            fn(Request $request, UserInfo $userInfo): Response => new JsonResponse(['disabled_users' => self::extractDisabledUsers($this->storage->userList())])
        );

        $service->post(
            '/v1/disable_user',
            function (Request $request, UserInfo $userInfo): Response {
                $userId = $request->requirePostParameter('user_id', fn(string $s) => Validator::userId($s));
                if (null === $this->storage->userInfo($userId)) {
                    throw new HttpException('user does not exist', 400);
                }
                $this->storage->userDisable($userId);

                // delete and disconnect all (active) VPN configurations
                // for this user
                $this->connectionManager->disconnectByUserId($userId);

                // revoke all OAuth authorizations
                foreach ($this->oauthStorage->getAuthorizations($userId) as $clientAuthorization) {
                    $this->oauthStorage->deleteAuthorization($clientAuthorization->authKey());
                }

                return new Response(null, [], 204);
            }
        );

        $service->post(
            '/v1/enable_user',
            function (Request $request, UserInfo $userInfo): Response {
                $userId = $request->requirePostParameter('user_id', fn(string $s) => Validator::userId($s));
                if (null === $this->storage->userInfo($userId)) {
                    throw new HttpException('user does not exist', 400);
                }

                // enabling the account will again allow the authorization of
                // OAuth clients, allow OpenVPN connections again and sync the
                // WireGuard peer configurations to the daemon(s)
                $this->storage->userEnable($userId);

                return new Response(null, [], 204);
            }
        );

        $service->post(
            '/v1/create',
            function (Request $request, UserInfo $userInfo): Response {
                try {
                    $userId = $request->requirePostParameter('user_id', fn(string $s) => Validator::userId($s));
                    if (null === $this->storage->userInfo($userId)) {
                        $this->storage->userAdd(new UserInfo($userId, []), $this->dateTime);
                    }

                    $requestedProfileId = $request->requirePostParameter('profile_id', fn(string $s) => Validator::profileId($s));
                    $profileConfigList = $this->config->profileConfigList();
                    $availableProfiles = [];
                    foreach ($profileConfigList as $profileConfig) {
                        $availableProfiles[] = $profileConfig->profileId();
                    }

                    if (!\in_array($requestedProfileId, $availableProfiles, true)) {
                        throw new HttpException('no such "profile_id"', 404);
                    }

                    $profileConfig = $this->config->profileConfig($requestedProfileId);
                    $preferTcp = 'yes' === $request->optionalPostParameter('prefer_tcp', fn(string $s) => Validator::yesOrNo($s));
                    if (null === $displayName = $request->optionalPostParameter('display_name', fn(string $s) => Validator::displayName($s))) {
                        $displayName = 'Admin API';
                    }

                    $expiresAt = $this->sessionExpiry->expiresAt();
                    if (null !== $expiresIn = $request->optionalPostParameter('expires_in', fn(string $s) => Validator::dateInterval($s))) {
                        // make sure the provided `expires_in` is actually
                        // supported by the server...
                        if (!$this->sessionExpiry->isAllowed($expiresIn)) {
                            throw new HttpException('provided "expires_in" is not supported by the server, add it to "supportedSessionExpiry" configuration option', 400);
                        }

                        // use provided value, but make sure it never outlives
                        // our CA (OpenVPN)
                        $expiresAt = $this->sessionExpiry->clampToCa(new DateInterval($expiresIn));
                    }

                    $secretKey = Key::generate();
                    $clientConfig = $this->connectionManager->connect(
                        $this->serverInfo,
                        $profileConfig,
                        $userId,
                        Protocol::parseMimeType($request->optionalHeader('HTTP_ACCEPT')),
                        $displayName,
                        $expiresAt,
                        $preferTcp,
                        Key::publicKeyFromSecretKey($secretKey),
                        null
                    );

                    if ($clientConfig instanceof WireGuardClientConfig) {
                        $clientConfig->setPrivateKey($secretKey);
                    }

                    return new Response(
                        $clientConfig->get(includeComments: false),
                        [
                            'Expires' => $this->sessionExpiry->expiresAt()->format(DateTimeImmutable::RFC7231),
                            'Content-Type' => $clientConfig->contentType(),
                        ]
                    );
                } catch (ProtocolException $e) {
                    throw new HttpException($e->getMessage(), 406);
                } catch (ConnectionManagerException $e) {
                    throw new HttpException(\sprintf('/v1/create failed: %s', $e->getMessage()), 500);
                }
            }
        );

        $service->post(
            '/v1/destroy',
            function (Request $request, UserInfo $userInfo): Response {
                try {
                    $userId = $request->requirePostParameter('user_id', fn(string $s) => Validator::userId($s));
                    if (null === $this->storage->userInfo($userId)) {
                        throw new HttpException('user does not exist', 400);
                    }
                    // we will destroy all active configurations for this user
                    $this->connectionManager->disconnectByUserId($userId);
                    // delete the user account as well
                    $this->storage->userDelete($userId);

                    return new Response(null, [], 204);
                } catch (ConnectionManagerException $e) {
                    throw new HttpException(\sprintf('/v1/destroy failed: %s', $e->getMessage()), 500);
                }
            }
        );

        $service->post(
            '/v1/delete_connection',
            function (Request $request, UserInfo $userInfo): Response {
                try {
                    $userId = $request->requirePostParameter('user_id', fn(string $s) => Validator::userId($s));
                    if (null === $this->storage->userInfo($userId)) {
                        throw new HttpException('user does not exist', 400);
                    }
                    $connectionId = $request->requirePostParameter('connection_id', fn(string $s) => Validator::connectionId($s));
                    $this->connectionManager->disconnectByConnectionId($userId, $connectionId);

                    return new Response(null, [], 204);
                } catch (ConnectionManagerException $e) {
                    throw new HttpException(\sprintf('/v1/delete_connection failed: %s', $e->getMessage()), 500);
                }
            }
        );
    }

    /**
     * @return array<array{connection_id:string,profile_id:string,display_name:string,auth_key:?string,expires_at:string,vpn_proto:string}>
     */
    private function extractUserConfigurationList(string $userId): array
    {
        $userConfigurationList = [];
        foreach ($this->storage->oCertInfoListByUserId($userId) as $oCertInfo) {
            $userConfigurationList[] = [
                'connection_id' => $oCertInfo['common_name'],
                'profile_id' => $oCertInfo['profile_id'],
                'display_name' => $oCertInfo['display_name'],
                'auth_key' => $oCertInfo['auth_key'],
                'expires_at' => $oCertInfo['expires_at']->format(DateTimeImmutable::ATOM),
                'vpn_proto' => 'openvpn',
            ];
        }

        foreach ($this->storage->wPeerInfoListByUserId($userId) as $wPeerInfo) {
            $userConfigurationList[] = [
                'connection_id' => $wPeerInfo['public_key'],
                'profile_id' => $wPeerInfo['profile_id'],
                'display_name' => $wPeerInfo['display_name'],
                'auth_key' => $wPeerInfo['auth_key'],
                'expires_at' => $wPeerInfo['expires_at']->format(DateTimeImmutable::ATOM),
                'vpn_proto' => 'wireguard',
            ];
        }

        return $userConfigurationList;
    }

    /**
     * Show the user IDs of the connected users per profile.
     *
     * @param array<string,array<array{user_id:string,connection_id:string,display_name:string,ip_list:array<string>,vpn_proto:string,auth_key:?string}>> $connectionList
     *
     * @return array<string,array<string>>
     */
    private static function extractConnectedUsers(array $connectionList): array
    {
        $userList = [];
        foreach ($connectionList as $profileId => $connectionInfoList) {
            $profileUserList = [];
            foreach ($connectionInfoList as $connectionInfo) {
                $profileUserList[] = $connectionInfo['user_id'];
            }
            $userList[$profileId] = array_unique($profileUserList);
        }

        return $userList;
    }

    /**
     * @param array<\Vpn\Portal\Http\UserInfo> $userList
     *
     * @return array<string>
     */
    private static function extractUsers(array $userList): array
    {
        $strUserList = [];
        foreach ($userList as $userInfo) {
            $strUserList[] = $userInfo->userId();
        }

        return $strUserList;
    }

    /**
     * @param array<\Vpn\Portal\Http\UserInfo> $userList
     *
     * @return array<string>
     */
    private static function extractDisabledUsers(array $userList): array
    {
        $disabledUserList = [];
        foreach ($userList as $userInfo) {
            if ($userInfo->isDisabled()) {
                $disabledUserList[] = $userInfo->userId();
            }
        }

        return $disabledUserList;
    }
}
