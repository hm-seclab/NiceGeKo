<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\OpenVpn;

use DateTimeImmutable;
use Vpn\Portal\Cfg\OpenVpnConfig;
use Vpn\Portal\Cfg\ProfileConfig;
use Vpn\Portal\ClientConfigInterface;
use Vpn\Portal\OpenVpn\CA\CaInfo;
use Vpn\Portal\OpenVpn\CA\CertInfo;
use Vpn\Portal\OpenVpn\Exception\ClientConfigException;

final class ClientConfig implements ClientConfigInterface
{
    public function __construct(
        private OpenVpnConfig $openVpnConfig,
        private string $portalUrl,
        private int $nodeNumber,
        private ProfileConfig $profileConfig,
        private CaInfo $caInfo,
        private TlsCrypt $tlsCrypt,
        private CertInfo $certInfo,
        private string $vpnProto,
        private DateTimeImmutable $expiresAt
    ) {}

    #[\Override]
    public function contentType(): string
    {
        return 'application/x-openvpn-profile';
    }

    /**
     * @throws \Vpn\Portal\OpenVpn\Exception\ClientConfigException
     */
    #[\Override]
    public function get(bool $includeComments): string
    {
        $oUdpPortList = $this->profileConfig->oUdpPortList();
        $oTcpPortList = $this->profileConfig->oTcpPortList();
        if (0 !== \count($this->profileConfig->oExposedUdpPortList())) {
            $oUdpPortList = $this->profileConfig->oExposedUdpPortList();
        }
        if (0 !== \count($this->profileConfig->oExposedTcpPortList())) {
            $oTcpPortList = $this->profileConfig->oExposedTcpPortList();
        }

        $oUdpPortList = self::filterPortList($oUdpPortList, [53, 443]);
        $oTcpPortList = self::filterPortList($oTcpPortList, [80, 443]);

        // make sure we have _something_ to connect to
        if (0 === \count($oUdpPortList) && 0 === \count($oTcpPortList)) {
            throw new ClientConfigException('no UDP/TCP port available');
        }

        $clientConfig = [];
        if ($includeComments) {
            $clientConfig[] = \sprintf('# Portal: %s', $this->portalUrl);
            $clientConfig[] = \sprintf('# Profile: %s (%s)', $this->profileConfig->displayName(), $this->profileConfig->profileId());
            $clientConfig[] = \sprintf('# Expires: %s', $this->expiresAt->format(DateTimeImmutable::ATOM));
            $clientConfig[] = '';
        }

        $clientConfig = array_merge(
            $clientConfig,
            [
                'dev tun',
                'client',
                'nobind',
                'remote-cert-tls server',
                'verb 3',

                // wait this long (seconds) before trying the next server in the list
                'server-poll-timeout 10',

                // >= TLSv1.3
                'tls-version-min 1.3',

                // OpenVPN data channel encryption algorithm
                'data-ciphers AES-256-GCM:CHACHA20-POLY1305',

                // server dictates data channel key renegotiation interval
                'reneg-sec 0',

                '<ca>',
                $this->caInfo->pemCert(),
                '</ca>',

                '<cert>',
                $this->certInfo->pemCert(),
                '</cert>',

                '<key>',
                $this->certInfo->pemKey(),
                '</key>',

                '<tls-crypt>',
                $this->tlsCrypt->get($this->profileConfig->profileId()),
                '</tls-crypt>',
            ]
        );

        // MTU
        if (null !== $setMtu = $this->openVpnConfig->setMtu()) {
            $clientConfig[] = \sprintf('tun-mtu %d', $setMtu);
        }

        $clientConfig = array_merge(
            $clientConfig,
            self::addRemotes(
                $oUdpPortList,
                $oTcpPortList,
                $this->profileConfig->hostName($this->nodeNumber),
                $this->vpnProto
            )
        );

        return implode(\PHP_EOL, $clientConfig);
    }

    /**
     * Pick a random port from the available ports the client can connect to
     * that is not a "special" port. Also add a special port. This is a very
     * simple form of "load balancing" over different ports while always adding
     * a "special" port to increase the chances a client can connect on
     * "difficult" networks.
     *
     * @param array<int> $availablePortList
     * @param array<int> $specialPortList
     *
     * @return array<int>
     */
    private static function filterPortList(array $availablePortList, array $specialPortList): array
    {
        $clientPortList = [];

        // remove specialPortList entries from portList
        $normalAvailablePortList = array_values(
            array_diff($availablePortList, $specialPortList)
        );
        $specialAvailablePortList = array_values(
            array_intersect($availablePortList, $specialPortList)
        );

        // add a normal port (if available)
        if (0 !== \count($normalAvailablePortList)) {
            $clientPortList[] = $normalAvailablePortList[random_int(0, \count($normalAvailablePortList) - 1)];
        }

        // add a special port (if available)
        if (0 !== \count($specialAvailablePortList)) {
            $clientPortList[] = $specialAvailablePortList[random_int(0, \count($specialAvailablePortList) - 1)];
        }

        return $clientPortList;
    }

    /**
     * @param array<int> $oUdpPortList
     * @param array<int> $oTcpPortList
     *
     * @return array<string>
     */
    private static function addRemotes(array $oUdpPortList, array $oTcpPortList, string $hostName, string $vpnProto): array
    {
        $udpRemotes = [];
        $tcpRemotes = [];
        foreach ($oUdpPortList as $udpPort) {
            $udpRemotes[] = \sprintf('remote %s %d udp', $hostName, $udpPort);
        }
        foreach ($oTcpPortList as $tcpPort) {
            $tcpRemotes[] = \sprintf('remote %s %d tcp', $hostName, $tcpPort);
        }

        if ('openvpn+tcp' === $vpnProto) {
            // return the TCP remotes first, i.e. "Prefer TCP"
            return array_merge($tcpRemotes, $udpRemotes);
        }

        return array_merge($udpRemotes, $tcpRemotes);
    }
}
