<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http;

use Vpn\Portal\Cfg\Config;
use Vpn\Portal\ConnectionHookInterface;
use Vpn\Portal\GeoIp;
use Vpn\Portal\Http\Exception\NodeApiException;
use Vpn\Portal\LoggerInterface;
use Vpn\Portal\NodeInfo;
use Vpn\Portal\ServerConfig;
use Vpn\Portal\Storage;
use Vpn\Portal\Validator;

final class NodeApiModule implements ServiceModuleInterface
{
    private ?GeoIp $geoIp = null;

    public function __construct(private Config $config, private Storage $storage, private ServerConfig $serverConfig, private ConnectionHookInterface $connectionHook, private LoggerInterface $logger)
    {
        if (null !== $geoIpDb = $this->config->logConfig()->geoIpDb()) {
            $this->geoIp = new GeoIp($geoIpDb);
        }
    }

    #[\Override]
    public function init(ServiceInterface $service): void
    {
        $service->get(
            '/ping',
            fn(Request $request, UserInfo $userInfo): Response => new JsonResponse(
                [
                    'pong' => [
                        'node_number' => (int) $userInfo->userId(),
                    ],
                ]
            )
        );

        $service->post(
            '/server_config/v2',
            function (Request $request, UserInfo $userInfo): Response {
                // XXX catch exceptions
                $profileConfigList = $this->config->profileConfigList();
                $profileIdList = $request->optionalArrayPostParameter('profile_id_list', fn(array $a) => Validator::profileIdList($a));
                if (0 !== \count($profileIdList)) {
                    $profileConfigList = [];
                    foreach ($profileIdList as $profileId) {
                        $profileConfigList[] = $this->config->profileConfig($profileId);
                    }
                }

                $serverConfigList = $this->serverConfig->get(
                    $profileConfigList,
                    (int) $userInfo->userId(), // userId = nodeNumber
                    $request->requirePostParameter('public_key', fn(string $s) => Validator::publicKey($s)),
                    'yes' === $request->requirePostParameter('prefer_aes', fn(string $s) => Validator::yesOrNo($s)),
                    $request->requirePostParameter('vpn_user', fn(string $s) => Validator::vpnUser($s)),
                    $request->requirePostParameter('vpn_group', fn(string $s) => Validator::vpnGroup($s))
                );

                return new JsonResponse($serverConfigList);
            }
        );

        $service->post(
            '/connect',
            fn(Request $request, UserInfo $userInfo): Response => $this->connect($request)
        );

        $service->post(
            '/disconnect',
            fn(Request $request, UserInfo $userInfo): Response => $this->disconnect($request)
        );
    }

    public function connect(Request $request): Response
    {
        try {
            $profileId = $request->requirePostParameter('profile_id', fn(string $s) => Validator::profileId($s));
            $nodeNumber = (int) $request->requireHeader('HTTP_X_NODE_NUMBER', fn(string $s) => Validator::nodeNumber($s));
            $commonName = $request->requirePostParameter('common_name', fn(string $s) => Validator::commonName($s));
            $originatingIp = $request->requirePostParameter('originating_ip', fn(string $s) => Validator::ipAddress($s));
            $ipFour = $request->requirePostParameter('ip_four', fn(string $s) => Validator::ipFour($s));
            $ipSix = $request->requirePostParameter('ip_six', fn(string $s) => Validator::ipSix($s));
            $userId = $this->verifyConnection($profileId, $nodeNumber, $commonName);

            // determine IP Info if said functionality is enabled
            $ipInfo = null;
            if (null !== $this->geoIp) {
                $ipInfo = $this->geoIp->get($originatingIp);
            }

            $profileConfig = $this->config->profileConfig($profileId);
            $nodeInfo = new NodeInfo(
                $nodeNumber,
                $profileConfig->nodeUrl($nodeNumber),
                $profileConfig->hostName($nodeNumber)
            );
            $this->connectionHook->connect($nodeInfo, $userId, $profileId, 'openvpn', $commonName, $ipFour, $ipSix, $originatingIp, $ipInfo);

            return new Response('OK');
        } catch (NodeApiException $e) {
            $this->logger->warning($e->getMessage());

            return new Response('ERR');
        }
    }

    public function disconnect(Request $request): Response
    {
        $profileId = $request->requirePostParameter('profile_id', fn(string $s) => Validator::profileId($s));
        $nodeNumber = (int) $request->requireHeader('HTTP_X_NODE_NUMBER', fn(string $s) => Validator::nodeNumber($s));
        $commonName = $request->requirePostParameter('common_name', fn(string $s) => Validator::commonName($s));
        $ipFour = $request->requirePostParameter('ip_four', fn(string $s) => Validator::ipFour($s));
        $ipSix = $request->requirePostParameter('ip_six', fn(string $s) => Validator::ipSix($s));
        $bytesIn = (int) $request->requirePostParameter('bytes_in', fn(string $s) => Validator::nonNegativeInt($s));
        $bytesOut = (int) $request->requirePostParameter('bytes_out', fn(string $s) => Validator::nonNegativeInt($s));
        // because OpenVPN disconnects are only triggered after the client
        // disconnects from the OpenVPN server process, we can't always use
        // the "certificates" table to find the user_id from there as the
        // certificate might have been deleted already in ConnectionManager
        if (null === $openConnectionInfo = $this->storage->openConnectionInfo($commonName)) {
            $this->logger->info(\sprintf('no open connection for CN "%s"', $commonName));

            return new Response('OK');
        }

        $profileConfig = $this->config->profileConfig($profileId);
        $nodeInfo = new NodeInfo(
            $nodeNumber,
            $profileConfig->nodeUrl($nodeNumber),
            $profileConfig->hostName($nodeNumber)
        );
        $this->connectionHook->disconnect($nodeInfo, $openConnectionInfo['user_id'], $profileId, 'openvpn', $commonName, $ipFour, $ipSix, $bytesIn, $bytesOut);

        return new Response('OK');
    }

    private function verifyConnection(string $profileId, int $nodeNumber, string $commonName): string
    {
        if (null === $oCertInfo = $this->storage->oCertInfo($commonName)) {
            throw new NodeApiException(\sprintf('unable to find certificate with CN "%s" in the database', $commonName));
        }

        $userId = $oCertInfo['user_id'];
        if ($oCertInfo['user_is_disabled']) {
            throw new NodeApiException(\sprintf('account "%s" has been disabled', $userId));
        }

        // make sure the profileId the client connects to matches the one in
        // the certificate
        if ($profileId !== $oCertInfo['profile_id']) {
            throw new NodeApiException(\sprintf('certificate "%s" for profile "%s" can not be used with profile "%s"', $commonName, $oCertInfo['profile_id'], $profileId));
        }

        // make sure the nodeNumber the client connects to matches the one in
        // the certificate
        if ($nodeNumber !== $oCertInfo['node_number']) {
            throw new NodeApiException(\sprintf('certificate "%s" for node "%d" can not be used with node "%d"', $commonName, $oCertInfo['node_number'], $nodeNumber));
        }

        $profileConfig = $this->config->profileConfig($profileId);
        if (null !== $profilePermissionList = $profileConfig->aclPermissionList()) {
            // ACL is enabled for this profile
            if (null === $userInfo = $this->storage->userInfo($userId)) {
                throw new NodeApiException(\sprintf('account "%s" does not exist', $userId));
            }
            if (!$userInfo->hasAnyPermission($profilePermissionList)) {
                throw new NodeApiException(\sprintf('account "%s" has insufficient permissions to access profile "%s"', $userId, $profileId));
            }
        }

        return $userId;
    }
}
