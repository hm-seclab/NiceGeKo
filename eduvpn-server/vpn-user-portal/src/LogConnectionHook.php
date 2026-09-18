<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

use Vpn\Portal\Cfg\LogConfig;

/**
 * Write to (sys)logger on connect/disconnect events.
 */
final class LogConnectionHook implements ConnectionHookInterface
{
    public function __construct(private Storage $storage, private LoggerInterface $logger, private LogConfig $logConfig) {}

    #[\Override]
    public function connect(NodeInfo $nodeInfo, string $userId, string $profileId, string $vpnProto, string $connectionId, string $ipFour, string $ipSix, ?string $originatingIp, ?IpInfo $ipInfo): void
    {
        $this->logger->info(
            self::logConnect($nodeInfo, $userId, $profileId, $vpnProto, $connectionId, $ipFour, $ipSix, $originatingIp, $ipInfo)
        );
    }

    #[\Override]
    public function disconnect(NodeInfo $nodeInfo, string $userId, string $profileId, string $vpnProto, string $connectionId, string $ipFour, string $ipSix, int $bytesIn, int $bytesOut): void
    {
        $this->logger->info(
            self::logDisconnect($nodeInfo, $userId, $profileId, $vpnProto, $connectionId, $ipFour, $ipSix, $bytesIn, $bytesOut)
        );
    }

    private function logConnect(NodeInfo $nodeInfo, string $userId, string $profileId, string $vpnProto, string $connectionId, string $ipFour, string $ipSix, ?string $originatingIp, ?IpInfo $ipInfo): string
    {
        if (!$this->logConfig->originatingIp() || null === $originatingIp) {
            $originatingIp = '*';
        }
        $geoIpCc = '*';
        $geoIpUri = '*';
        if (null !== $ipInfo) {
            $geoIpCc = $ipInfo->countryCode();
            $geoIpUri = $ipInfo->geoUri();
        }

        return str_replace(
            [
                '{{NODE_NUMBER}}',
                '{{NODE_URL}}',
                '{{NODE_HOST_NAME}}',
                '{{USER_ID}}',
                '{{PROFILE_ID}}',
                '{{VPN_PROTO}}',
                '{{CONNECTION_ID}}',
                '{{IP_FOUR}}',
                '{{IP_SIX}}',
                '{{ORIGINATING_IP}}',
                '{{GEO_IP_CC}}',
                '{{GEO_IP_URI}}',
                '{{AUTH_DATA}}',
            ],
            [
                (string) $nodeInfo->nodeNumber(),
                $nodeInfo->nodeUrl(),
                $nodeInfo->hostName(),
                $userId,
                $profileId,
                $vpnProto,
                $connectionId,
                $ipFour,
                $ipSix,
                $originatingIp,
                $geoIpCc,
                $geoIpUri,
                $this->authData($userId),
            ],
            $this->logConfig->connectLogTemplate()
        );
    }

    private function logDisconnect(NodeInfo $nodeInfo, string $userId, string $profileId, string $vpnProto, string $connectionId, string $ipFour, string $ipSix, int $bytesIn, int $bytesOut): string
    {
        return str_replace(
            [
                '{{NODE_NUMBER}}',
                '{{NODE_URL}}',
                '{{NODE_HOST_NAME}}',
                '{{USER_ID}}',
                '{{PROFILE_ID}}',
                '{{VPN_PROTO}}',
                '{{CONNECTION_ID}}',
                '{{IP_FOUR}}',
                '{{IP_SIX}}',
                '{{BYTES_IN}}',
                '{{BYTES_OUT}}',
                '{{AUTH_DATA}}',
            ],
            [
                (string) $nodeInfo->nodeNumber(),
                $nodeInfo->nodeUrl(),
                $nodeInfo->hostName(),
                $userId,
                $profileId,
                $vpnProto,
                $connectionId,
                $ipFour,
                $ipSix,
                (string) $bytesIn,
                (string) $bytesOut,
                $this->authData($userId),
            ],
            $this->logConfig->disconnectLogTemplate()
        );
    }

    /**
     * Obtain the Base64 URL safe encoded "auth_data" column for this user from
     * the database. It is currently used to store the originating local user
     * before HMAC'ing the user's identifier for use with "Guest Access".
     */
    private function authData(string $userId): string
    {
        if (null === $userInfo = $this->storage->userInfo($userId)) {
            // user no longer exists
            return '';
        }
        if (null === $authData = $userInfo->authData()) {
            // no auth_data for this user
            return '';
        }

        return Base64UrlSafe::encodeUnpadded($authData);
    }
}
