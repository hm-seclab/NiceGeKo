<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * This file is part of eduVPN vpn-user-portal and has been MODIFIED.
 * Modified 2026-07 by the eduVPNextension project: a device-posture gate is
 * enforced at the top of the POST /v3/connect handler (mTLS client-certificate
 * CN + Wazuh agent state) before any VPN configuration is issued.
 *
 * See eduvpn-server/vpn-user-portal/VENDORED.md for the unmodified upstream base.
 */

namespace Vpn\Portal\Http;

use DateTimeImmutable;
use fkooman\OAuth\Server\PdoStorage as OAuthStorage;
use Vpn\Portal\Cfg\Config;
use Vpn\Portal\ConnectionManager;
use Vpn\Portal\Exception\ConnectionManagerException;
use Vpn\Portal\Exception\ProtocolException;
use Vpn\Portal\Http\Exception\HttpException;
use Vpn\Portal\PermissionSourceManager;
use Vpn\Portal\PostureChecker;
use Vpn\Portal\Protocol;
use Vpn\Portal\ServerInfo;
use Vpn\Portal\Storage;
use Vpn\Portal\Validator;

class VpnApiThreeModule implements ApiServiceModuleInterface
{
    protected DateTimeImmutable $dateTime;

    public function __construct(private Config $config, private Storage $storage, private OAuthStorage $oauthStorage, private ServerInfo $serverInfo, private ConnectionManager $connectionManager, private PermissionSourceManager $permissionSourceManager, private ?PostureChecker $postureChecker = null)
    {
        $this->dateTime = new DateTimeImmutable();
    }

    #[\Override]
    public function init(ApiServiceInterface $service): void
    {
        $service->get(
            '/v3/info',
            function (Request $request, ApiUserInfo $userInfo): Response {
                $profileConfigList = $this->config->profileConfigList();
                if (null === $dbUserInfo = $this->storage->userInfo($userInfo->userId())) {
                    throw new HttpException(\sprintf('user "%s" no longer exists', $userInfo->userId()), 500);
                }

                if (null !== $permissionList = $this->permissionSourceManager->get($userInfo->userId())) {
                    // got real-time permissions
                    $dbUserInfo->setPermissionList($permissionList);
                }

                $userProfileList = [];
                foreach ($profileConfigList as $profileConfig) {
                    if ($profileConfig->hideProfile()) {
                        // do not list this profile when the profile is hidden
                        continue;
                    }
                    if (null !== $aclPermissionList = $profileConfig->aclPermissionList()) {
                        if (!$dbUserInfo->hasAnyPermission($aclPermissionList)) {
                            continue;
                        }
                    }

                    $vpnProtoTransportList = [];
                    if ($profileConfig->oSupport()) {
                        if (0 !== \count($profileConfig->oUdpPortList()) || 0 !== \count($profileConfig->oExposedUdpPortList())) {
                            $vpnProtoTransportList[] = 'openvpn+udp';
                        }
                        if (0 !== \count($profileConfig->oTcpPortList()) || 0 !== \count($profileConfig->oExposedTcpPortList())) {
                            $vpnProtoTransportList[] = 'openvpn+tcp';
                        }
                    }
                    if ($profileConfig->wSupport()) {
                        if (!$this->config->wireGuardConfig()->onlyProxy()) {
                            $vpnProtoTransportList[] = 'wireguard+udp';
                        }
                        if ($this->config->wireGuardConfig()->enableProxy()) {
                            $vpnProtoTransportList[] = 'wireguard+tcp';
                        }
                    }

                    $profileInfo = [
                        'profile_id' => $profileConfig->profileId(),
                        'profile_priority' => $profileConfig->profilePriority(),
                        'display_name' => $profileConfig->displayName(),
                        'vpn_proto_list' => $profileConfig->protoList(),
                        'default_gateway' => $profileConfig->defaultGateway(),
                        'vpn_proto_transport_list' => $vpnProtoTransportList,
                    ];
                    // if defined, add the DNS search domains to the response
                    $dnsSearchDomainList = $profileConfig->dnsSearchDomainList();
                    if (0 !== \count($dnsSearchDomainList)) {
                        $profileInfo['dns_search_domain_list'] = $dnsSearchDomainList;
                    }

                    $userProfileList[] = $profileInfo;
                }

                return new JsonResponse(
                    [
                        'info' => [
                            'profile_list' => $userProfileList,
                        ],
                    ]
                );
            }
        );

        $service->post(
            '/v3/connect',
            function (Request $request, ApiUserInfo $userInfo): Response {
                try {
                    // --- mTLS Device Posture Check ---
                    // Runs FIRST, before any disconnect/eviction below: otherwise a
                    // non-compliant (or cert-less) device could tear down the user's
                    // compliant, connected device and only then be rejected.
                    if (null !== $this->postureChecker) {
                        try {
                            $sslClientVerify = $request->optionalHeader('SSL_CLIENT_VERIFY');
                            $sslClientCN = $request->optionalHeader('SSL_CLIENT_S_DN_CN');
                        } catch (HttpException) {
                            // A present-but-empty mTLS header (e.g. a client cert with
                            // no CN) makes optionalHeader() raise 400; treat it as
                            // "no valid device certificate" and fall through to 403.
                            $sslClientVerify = null;
                            $sslClientCN = null;
                        }

                        if ('SUCCESS' !== $sslClientVerify || null === $sslClientCN) {
                            return new JsonResponse(
                                ['error' => 'device certificate required'],
                                [],
                                403
                            );
                        }

                        $postureResult = $this->postureChecker->check($sslClientCN);
                        if (!$postureResult->passed) {
                            return new JsonResponse(
                                ['error' => $postureResult->reason],
                                [],
                                403
                            );
                        }
                    }

                    // make sure all client configurations / connections initiated
                    // by this client are removed / disconnected
                    $this->connectionManager->disconnectByAuthKey($userInfo->accessToken()->authKey());

                    $publicKey = $request->optionalPostParameter('public_key', fn(string $s) => Validator::publicKey($s));
                    if (null !== $publicKey) {
                        // we disconnect all "active" connections for this
                        // WireGuard public key. The macOS/iOS clients
                        // (currently) use the same public_key for all
                        // connections to the same server
                        $this->connectionManager->disconnectByConnectionId(
                            $userInfo->userId(),
                            $publicKey
                        );
                    }

                    $maxActiveApiConfigurations = $this->config->apiConfig()->maxActiveConfigurations();
                    if (0 === $maxActiveApiConfigurations) {
                        throw new HttpException('no API configuration downloads allowed', 403);
                    }
                    $activeApiConfigurations = $this->storage->activeApiConfigurations($userInfo->userId(), $this->dateTime);
                    if (\count($activeApiConfigurations) >= $maxActiveApiConfigurations) {
                        // we disconnect the client that connected the longest
                        // time ago, which is first one from the set in
                        // activeApiConfigurations
                        $this->connectionManager->disconnectByConnectionId(
                            $userInfo->userId(),
                            $activeApiConfigurations[0]['connection_id']
                        );
                    }
                    $requestedProfileId = $request->requirePostParameter('profile_id', fn(string $s) => Validator::profileId($s));
                    $profileConfigList = $this->config->profileConfigList();
                    if (null === $dbUserInfo = $this->storage->userInfo($userInfo->userId())) {
                        throw new HttpException(\sprintf('user "%s" no longer exists', $userInfo->userId()), 500);
                    }

                    if (null !== $permissionList = $this->permissionSourceManager->get($userInfo->userId())) {
                        // got real-time permissions
                        $dbUserInfo->setPermissionList($permissionList);
                    }

                    $availableProfiles = [];
                    foreach ($profileConfigList as $profileConfig) {
                        if ($profileConfig->hideProfile()) {
                            // do not allow connections to this profile when
                            // the profile is hidden
                            continue;
                        }
                        if (null !== $aclPermissionList = $profileConfig->aclPermissionList()) {
                            if (!$dbUserInfo->hasAnyPermission($aclPermissionList)) {
                                continue;
                            }
                        }

                        $availableProfiles[] = $profileConfig->profileId();
                    }

                    if (!\in_array($requestedProfileId, $availableProfiles, true)) {
                        throw new HttpException('no such "profile_id"', 404);
                    }

                    $profileConfig = $this->config->profileConfig($requestedProfileId);

                    // still support "tcp_only" as an alias for "prefer_tcp",
                    // breaks when tcp_only=on and prefer_tcp=no, but we only
                    // want to support old clients (still using tcp_only) and
                    // new clients supporting prefer_tcp, and not a client
                    // where both are used...
                    $preferTcp = 'on' === $request->optionalPostParameter('tcp_only', fn(string $s) => Validator::onOrOff($s));
                    $preferTcp = $preferTcp || 'yes' === $request->optionalPostParameter('prefer_tcp', fn(string $s) => Validator::yesOrNo($s));

                    // XXX if public_key is missing when VPN client supports
                    // WireGuard that is a bug I guess, is the spec clear about
                    // this?!
                    $clientConfig = $this->connectionManager->connect(
                        $this->serverInfo,
                        $profileConfig,
                        $userInfo->userId(),
                        Protocol::parseMimeType($request->optionalHeader('HTTP_ACCEPT')),
                        $userInfo->accessToken()->clientId(),
                        $userInfo->accessToken()->authorizationExpiresAt(),
                        $preferTcp,
                        $publicKey,
                        $userInfo->accessToken()->authKey(),
                    );

                    return new Response(
                        $clientConfig->get(includeComments: false),
                        [
                            'Expires' => $userInfo->accessToken()->authorizationExpiresAt()->format(DateTimeImmutable::RFC7231),
                            'Content-Type' => $clientConfig->contentType(),
                        ]
                    );
                } catch (ProtocolException $e) {
                    throw new HttpException($e->getMessage(), 406);
                } catch (ConnectionManagerException $e) {
                    throw new HttpException(\sprintf('/connect failed: %s', $e->getMessage()), 500);
                }
            }
        );

        $service->post(
            '/v3/disconnect',
            function (Request $request, ApiUserInfo $userInfo): Response {
                $this->connectionManager->disconnectByAuthKey($userInfo->accessToken()->authKey());

                // optionally remove the OAuth authorization if so requested by
                // the server configuration
                if ($this->config->apiConfig()->deleteAuthorizationOnDisconnect()) {
                    $this->oauthStorage->deleteAuthorization($userInfo->accessToken()->authKey());
                }

                return new Response(null, [], 204);
            }
        );
    }
}
