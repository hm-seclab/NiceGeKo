<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

use DateTimeImmutable;
use fkooman\OAuth\Server\PdoStorage as OAuthStorage;
use Vpn\Portal\Cfg\Config;
use Vpn\Portal\ClientConfigInterface;
use Vpn\Portal\ConnectionManager;
use Vpn\Portal\Expiry;
use Vpn\Portal\Http\Exception\HttpException;
use Vpn\Portal\OpenVpn\ClientConfig as OpenVpnClientConfig;
use Vpn\Portal\PermissionSourceManager;
use Vpn\Portal\ProtoSupport;
use Vpn\Portal\ServerInfo;
use Vpn\Portal\Storage;
use Vpn\Portal\Tpl;
use Vpn\Portal\TplInterface;
use Vpn\Portal\Validator;
use Vpn\Portal\WireGuard\ClientConfig as WireGuardClientConfig;
use Vpn\Portal\WireGuard\Key;

final class VpnPortalModule implements ServiceModuleInterface
{
    protected DateTimeImmutable $dateTime;

    public function __construct(private Config $config, private TplInterface $tpl, private CookieInterface $cookie, private ConnectionManager $connectionManager, private Storage $storage, private OAuthStorage $oauthStorage, private ServerInfo $serverInfo, private Expiry $sessionExpiry, private PermissionSourceManager $permissionSourceManager)
    {
        $this->dateTime = new DateTimeImmutable();
    }

    #[\Override]
    public function init(ServiceInterface $service): void
    {
        $service->get(
            '/',
            fn(Request $request, UserInfo $userInfo): Response => new RedirectResponse($request->getRootUri() . 'home')
        );

        $service->get(
            '/home',
            function (Request $request, UserInfo $userInfo): Response {
                $profileConfigList = $this->config->profileConfigList();
                [$availableProfileConfigList, $availableProfileIdList] = self::availableProfileConfigList($profileConfigList, $userInfo);
                [$rowCount, $profileProtoList] = self::profileProtoList($this->config, $availableProfileConfigList);

                return new HtmlResponse(
                    $this->tpl->render(
                        'vpnPortalHome',
                        [
                            'maxActiveConfigurations' => $this->config->maxActiveConfigurations(),
                            'numberOfActivePortalConfigurations' => $this->storage->numberOfActivePortalConfigurations($userInfo->userId(), $this->dateTime),
                            'profileConfigList' => $this->config->profileConfigList(),
                            'availableProfileConfigList' => $availableProfileConfigList,
                            'availableProfileIdList' => $availableProfileIdList,
                            'expiryDate' => $this->sessionExpiry->expiresAt($userInfo)->format('Y-m-d'),
                            'manualUserConfigList' => self::manualUserConfigList($this->storage, $userInfo->userId()),
                            'rowCount' => $rowCount,
                            'profileProtoList' => $profileProtoList,
                            'allowExpiryExtension' => $this->config->wireGuardConfig()->allowExpiryExtension(),
                        ]
                    )
                );
            }
        );

        $service->post(
            '/home',
            function (Request $request, UserInfo $userInfo): Response {
                $postAction = $request->requirePostParameter(
                    'action',
                    fn(string $s) => Validator::inSet($s, ['add_config', 'delete_config', 'extend_config'])
                );
                switch ($postAction) {
                    case 'add_config':
                        $profileProtoTransport = $request->requirePostParameter('profileId', fn(string $s) => Validator::profileProtoTransport($s));
                        [$profileId, $protoTransport] = Validator::parseProfileProtoTransport($profileProtoTransport);
                        switch ($protoTransport) {
                            case 'openvpn+udp':
                                $preferTcp = false;
                                $clientProtoSupport = new ProtoSupport(oUdp: true, oTcp: true, wUdp: false, wTcp: false);

                                break;
                            case 'openvpn+tcp':
                                $preferTcp = true;
                                $clientProtoSupport = new ProtoSupport(oUdp: true, oTcp: true, wUdp: false, wTcp: false);

                                break;
                            case 'wireguard+udp':
                                $preferTcp = false;
                                $clientProtoSupport = new ProtoSupport(oUdp: false, oTcp: false, wUdp: true, wTcp: false);

                                break;
                            case 'wireguard+tcp':
                                $preferTcp = true;
                                $clientProtoSupport = new ProtoSupport(oUdp: false, oTcp: false, wUdp: false, wTcp: true);

                                break;
                            default:
                                throw new HttpException(\sprintf('protocol transport "%s" not supported', $protoTransport), 400);
                        }

                        return $this->addConfig($request, $userInfo, $profileId, $preferTcp, $clientProtoSupport);
                    case 'delete_config':
                        return $this->deleteConfig($request, $userInfo);
                    case 'extend_config':
                        return $this->extendConfig($request, $userInfo);
                    default:
                        throw new HttpException(\sprintf('unsupported action "%s"', $postAction), 400);
                }
            }
        );

        $service->get(
            '/account',
            function (Request $request, UserInfo $userInfo): Response {
                $profileConfigList = $this->config->profileConfigList();
                [$availableProfileConfigList,] = self::availableProfileConfigList($profileConfigList, $userInfo);

                return new HtmlResponse(
                    $this->tpl->render(
                        'vpnPortalAccount',
                        [
                            'availableProfileConfigList' => $availableProfileConfigList,
                            'showPermissions' => $this->config->showPermissions(),
                            'userInfo' => $userInfo,
                            'showRefreshButton' => $this->permissionSourceManager->hasPermissionSources(),
                            'authorizationList' => $this->oauthStorage->getAuthorizations($userInfo->userId()),
                        ]
                    )
                );
            }
        );

        $service->post(
            '/account',
            function (Request $request, UserInfo $userInfo): Response {
                if (null !== $permissionList = $this->permissionSourceManager->get($userInfo->userId())) {
                    $userInfo->setPermissionList($permissionList);
                    $this->storage->userUpdate($userInfo, $this->dateTime);
                }

                return new RedirectResponse($request->getRootUri() . 'account');
            }
        );

        $service->post(
            '/removeClientAuthorization',
            function (Request $request, UserInfo $userInfo): Response {
                // XXX make sure authKey belongs to current user!
                $authKey = $request->requirePostParameter('auth_key', fn(string $s) => Validator::authKey($s));
                if (null === $this->oauthStorage->getAuthorization($authKey)) {
                    throw new HttpException('no such authorization', 400);
                }

                // disconnect all OpenVPN and WireGuard clients under this
                // OAuth authorization
                $this->connectionManager->disconnectByAuthKey($authKey);

                // delete the OAuth authorization
                $this->oauthStorage->deleteAuthorization($authKey);

                return new RedirectResponse($request->getRootUri() . 'account');
            }
        );

        $service->postBeforeAuth(
            '/setLanguage',
            function (Request $request): Response {
                $this->cookie->set('L', $request->requirePostParameter('uiLanguage', fn(string $s) => Validator::inSet($s, array_keys(Tpl::supportedLanguages()))));

                return new RedirectResponse($request->requireReferrer());
            }
        );
    }

    /**
     * @return list<array{connection_id:string,display_name:string,expires_at:\DateTimeImmutable,ip_four?:string,ip_six?:string,node_number:int,profile_id:string,vpn_proto:string}>
     */
    public static function manualUserConfigList(Storage $storage, string $userId): array
    {
        $configList = [];
        foreach ($storage->oCertInfoListByUserId($userId) as $oCertInfo) {
            if (null !== $oCertInfo['auth_key']) {
                continue;
            }
            $connectionId = $oCertInfo['common_name'];
            unset($oCertInfo['common_name']);
            unset($oCertInfo['auth_key']);
            $configList[] = array_merge(
                $oCertInfo,
                [
                    'connection_id' => $connectionId,
                    'vpn_proto' => 'openvpn',
                ]
            );
        }

        foreach ($storage->wPeerInfoListByUserId($userId) as $wPeerInfo) {
            if (null !== $wPeerInfo['auth_key']) {
                continue;
            }
            $connectionId = $wPeerInfo['public_key'];
            unset($wPeerInfo['public_key']);
            unset($wPeerInfo['auth_key']);
            $configList[] = array_merge(
                $wPeerInfo,
                [
                    'connection_id' => $connectionId,
                    'vpn_proto' => 'wireguard',
                ]
            );
        }

        return $configList;
    }

    private function addConfig(Request $request, UserInfo $userInfo, string $profileId, bool $preferTcp, ProtoSupport $clientProtoSupport): Response
    {
        $maxActiveConfigurations = $this->config->maxActiveConfigurations();
        if (0 === $maxActiveConfigurations) {
            throw new HttpException('no portal configuration downloads allowed', 403);
        }
        $numberOfActivePortalConfigurations = $this->storage->numberOfActivePortalConfigurations($userInfo->userId(), $this->dateTime);
        if ($numberOfActivePortalConfigurations >= $maxActiveConfigurations) {
            throw new HttpException('limit of available portal configuration downloads has been reached', 403);
        }

        $displayName = $request->requirePostParameter('displayName', fn(string $s) => Validator::displayName($s));
        $profileConfigList = $this->config->profileConfigList();
        [, $availableProfileIdList] = self::availableProfileConfigList($profileConfigList, $userInfo);

        // make sure the profileId is in the list of allowed profiles for this
        // user, it would not result in the ability to use the VPN, but
        // better prevent it early
        if (!\in_array($profileId, $availableProfileIdList, true)) {
            throw new HttpException('no permission to download a configuration for this profile', 403);
        }

        $profileConfig = $this->config->profileConfig($profileId);
        $secretKey = Key::generate();
        $publicKey = Key::publicKeyFromSecretKey($secretKey);
        $clientConfig = $this->connectionManager->connect(
            $this->serverInfo,
            $profileConfig,
            $userInfo->userId(),
            $clientProtoSupport,
            $displayName,
            $this->sessionExpiry->expiresAt($userInfo),
            $preferTcp,
            $publicKey,
            null
        );

        return self::clientConfigResponse($clientConfig, $request->getServerName(), $profileId, $displayName, $secretKey);
    }

    private function deleteConfig(Request $request, UserInfo $userInfo): Response
    {
        $this->connectionManager->disconnectByConnectionId(
            $userInfo->userId(),
            $request->requirePostParameter('connectionId', fn(string $s) => Validator::connectionId($s))
        );

        return new RedirectResponse($request->getRootUri() . 'home#active-configurations');
    }

    /**
     * Extend the validity of the WireGuard configuration to match
     * sessionExpiry. This allows users to "extend" the duration of the
     * configuration without obtaining a new one and importing it in their
     * VPN application.
     */
    private function extendConfig(Request $request, UserInfo $userInfo): Response
    {
        if (!$this->config->wireGuardConfig()->allowExpiryExtension()) {
            throw new HttpException('extending the expiry of WireGuard configuration files not allowed', 400);
        }

        $connectionId = $request->requirePostParameter('connectionId', fn(string $s) => Validator::connectionId($s));
        if (null === $wPeerInfo = $this->storage->wPeerInfo($connectionId)) {
            throw new HttpException('peer does not or no longer exist', 400);
        }
        if ($userInfo->userId() !== $wPeerInfo['user_id']) {
            throw new HttpException('peer configuration does not belong to this user', 403);
        }

        // we MUST make sure the user still is allowed to use the profile the
        // configuration belongs to...
        $profileConfigList = $this->config->profileConfigList();
        [, $availableProfileIdList] = self::availableProfileConfigList($profileConfigList, $userInfo);
        if (!\in_array($wPeerInfo['profile_id'], $availableProfileIdList, true)) {
            throw new HttpException('user no longer has access to this profile', 403);
        }

        $expiresAt = $this->sessionExpiry->expiresAt($userInfo);
        $this->storage->wPeerSetExpiresAt($connectionId, $expiresAt);

        return new RedirectResponse($request->getRootUri() . 'home#active-configurations');
    }

    private function clientConfigResponse(ClientConfigInterface $clientConfig, string $serverName, string $profileId, string $displayName, string $secretKey): Response
    {
        if ($clientConfig instanceof WireGuardClientConfig) {
            // set private key
            $clientConfig->setPrivateKey($secretKey);

            return new HtmlResponse(
                $this->tpl->render(
                    'vpnPortalWgConfig',
                    [
                        'wireGuardClientConfig' => $clientConfig,
                    ]
                )
            );
        }

        if ($clientConfig instanceof OpenVpnClientConfig) {
            // special characters don't work in file names as NetworkManager
            // URL encodes the filename when searching for certificates
            // https://bugzilla.gnome.org/show_bug.cgi?id=795601
            $clientConfigFile = \sprintf('%s_%s_%s_%s', $serverName, $profileId, $this->dateTime->format('Ymd'), str_replace(' ', '_', $displayName));

            return new Response(
                $clientConfig->get(includeComments: true),
                [
                    'Content-Type' => $clientConfig->contentType(),
                    'Content-Disposition' => \sprintf('attachment; filename="%s.ovpn"', $clientConfigFile),
                ]
            );
        }

        throw new HttpException('unsupported client config format', 500);
    }

    /**
     * Filter the list of profiles by checking if the profile should be shown,
     * and that the user has the required permissions in case ACLs are enabled.
     *
     * @param array<\Vpn\Portal\Cfg\ProfileConfig> $profileConfigList
     *
     * @return array{0:array<\Vpn\Portal\Cfg\ProfileConfig>,1:array<string>}
     */
    private static function availableProfileConfigList(array $profileConfigList, UserInfo $userInfo): array
    {
        $availableProfileConfigList = [];
        $availableProfileIdList = [];
        foreach ($profileConfigList as $profileConfig) {
            if ($profileConfig->hideProfile()) {
                // do not allow connections to this profile when the profile is
                // hidden
                continue;
            }
            if (null !== $aclPermissionList = $profileConfig->aclPermissionList()) {
                if (!$userInfo->hasAnyPermission($aclPermissionList)) {
                    continue;
                }
            }

            $availableProfileIdList[] = $profileConfig->profileId();
            $availableProfileConfigList[] = $profileConfig;
        }

        return [$availableProfileConfigList, $availableProfileIdList];
    }

    /**
     * @param array<\Vpn\Portal\Cfg\ProfileConfig> $profileConfigList
     *
     * @return array{0:int,1:array<array{profileId:string,displayName:string,protoTransportList:array<string>}>}
     */
    private static function profileProtoList(Config $config, array $profileConfigList): array
    {
        $profileProtoList = [];
        $rowCount = 0;
        foreach ($profileConfigList as $profileConfig) {
            $protoTransportList = [];
            if ($profileConfig->oSupport()) {
                if (0 !== \count($profileConfig->oUdpPortList())) {
                    $protoTransportList[] = 'openvpn+udp';
                }
                if (0 !== \count($profileConfig->oTcpPortList())) {
                    $protoTransportList[] = 'openvpn+tcp';
                }
            }
            if ($profileConfig->wSupport()) {
                if (!$config->wireGuardConfig()->onlyProxy()) {
                    $protoTransportList[] = 'wireguard+udp';
                }
                if ($config->wireGuardConfig()->enableProxy()) {
                    $protoTransportList[] = 'wireguard+tcp';
                }
            }
            if (0 === \count($protoTransportList)) {
                // do not add the profile to the list if it has no protocols
                continue;
            }
            $rowCount += \count($protoTransportList) + 1;
            $profileProtoList[] = [
                'profileId' => $profileConfig->profileId(),
                'displayName' => $profileConfig->displayName(),
                'protoTransportList' => $protoTransportList,
            ];
        }

        return [$rowCount, $profileProtoList];
    }
}
