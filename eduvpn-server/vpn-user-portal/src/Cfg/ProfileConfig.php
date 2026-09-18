<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Cfg;

use Vpn\Portal\Cfg\Exception\ConfigException;
use Vpn\Portal\Extractor;
use Vpn\Portal\Ip;

final class ProfileConfig
{
    public function __construct(
        /** @var array<mixed> $d */
        private array $d
    ) {}

    public function profileId(): string
    {
        return Extractor::requireString($this->d, 'profileId');
    }

    public function displayName(): string
    {
        return Extractor::requireString($this->d, 'displayName');
    }

    public function hostName(int $nodeNumber): string
    {
        $nodeIndex = $this->nodeNumberToIndex($nodeNumber);
        $hostNameList = Extractor::requireStringOrStringArray($this->d, 'hostName');
        if ($nodeIndex >= \count($hostNameList)) {
            throw new ConfigException('"hostName" for node "' . $nodeNumber . '" not set');
        }

        return $hostNameList[$nodeIndex];
    }

    public function defaultGateway(): bool
    {
        return Extractor::requireBool($this->d, 'defaultGateway', true);
    }

    /**
     * @return array<string>
     */
    public function dnsServerList(): array
    {
        return Extractor::requireStringArray($this->d, 'dnsServerList', []);
    }

    /**
     * @return array<string>
     */
    public function routeList(): array
    {
        return Extractor::requireStringArray($this->d, 'routeList', []);
    }

    /**
     * @return array<string>
     */
    public function excludeRouteList(): array
    {
        return Extractor::requireStringArray($this->d, 'excludeRouteList', []);
    }

    /**
     * @return ?array<string>
     */
    public function aclPermissionList(): ?array
    {
        return Extractor::optionalStringArray($this->d, 'aclPermissionList');
    }

    /**
     * @return array<string>
     */
    public function dnsSearchDomainList(): array
    {
        return Extractor::requireStringArray($this->d, 'dnsSearchDomainList', []);
    }

    public function nodeUrl(int $nodeNumber): string
    {
        $nodeIndex = $this->nodeNumberToIndex($nodeNumber);
        $nodeUrlList = Extractor::requireStringOrStringArray($this->d, 'nodeUrl', ['http://localhost:41194']);
        if ($nodeIndex >= \count($nodeUrlList)) {
            throw new ConfigException('"nodeUrl" for node "' . $nodeNumber . '" not set');
        }

        return $nodeUrlList[$nodeIndex];
    }

    public function preferredProto(): string
    {
        $protoList = $this->protoList();
        if (null !== $preferredProto = Extractor::optionalString($this->d, 'preferredProto')) {
            if (!\in_array($preferredProto, $protoList, true)) {
                throw new ConfigException('profile does not support "preferredProto"');
            }

            return $preferredProto;
        }

        // if only one protocol is supported, that is the default
        if (1 === \count($protoList)) {
            return $protoList[0];
        }

        // default to OpenVPN (for now) if admin did not set
        // "vpnProtoPreferred" and we support both protocols
        return 'openvpn';
    }

    public function wRangeFour(int $nodeNumber): Ip
    {
        $nodeIndex = $this->nodeNumberToIndex($nodeNumber);
        $wRangeFourList = Extractor::requireStringOrStringArray($this->d, 'wRangeFour');
        if ($nodeIndex >= \count($wRangeFourList)) {
            throw new ConfigException('"wRangeFour" for node "' . $nodeNumber . '" not set');
        }

        return Ip::fromIpPrefix($wRangeFourList[$nodeIndex]);
    }

    public function wRangeSix(int $nodeNumber): Ip
    {
        $nodeIndex = $this->nodeNumberToIndex($nodeNumber);
        $wRangeSixList = Extractor::requireStringOrStringArray($this->d, 'wRangeSix');
        if ($nodeIndex >= \count($wRangeSixList)) {
            throw new ConfigException('"wRangeSix" for node "' . $nodeNumber . '" not set');
        }

        return Ip::fromIpPrefix($wRangeSixList[$nodeIndex]);
    }

    public function oRangeFour(int $nodeNumber): Ip
    {
        $nodeIndex = $this->nodeNumberToIndex($nodeNumber);
        $oRangeFourList = Extractor::requireStringOrStringArray($this->d, 'oRangeFour');
        if ($nodeIndex >= \count($oRangeFourList)) {
            throw new ConfigException('"oRangeFour" for node "' . $nodeNumber . '" not set');
        }

        return Ip::fromIpPrefix($oRangeFourList[$nodeIndex]);
    }

    public function oRangeSix(int $nodeNumber): Ip
    {
        $nodeIndex = $this->nodeNumberToIndex($nodeNumber);
        $oRangeSixList = Extractor::requireStringOrStringArray($this->d, 'oRangeSix');
        if ($nodeIndex >= \count($oRangeSixList)) {
            throw new ConfigException('"oRangeSix" for node "' . $nodeNumber . '" not set');
        }

        return Ip::fromIpPrefix($oRangeSixList[$nodeIndex]);
    }

    /**
     * @return array<int>
     */
    public function oUdpPortList(): array
    {
        return Extractor::requireIntArray($this->d, 'oUdpPortList', [1194]);
    }

    /**
     * @return array<int>
     */
    public function oTcpPortList(): array
    {
        return Extractor::requireIntArray($this->d, 'oTcpPortList', [1194]);
    }

    /**
     * @return array<int>
     */
    public function oExposedUdpPortList(): array
    {
        return Extractor::requireIntArray($this->d, 'oExposedUdpPortList', []);
    }

    /**
     * @return array<int>
     */
    public function oExposedTcpPortList(): array
    {
        return Extractor::requireIntArray($this->d, 'oExposedTcpPortList', []);
    }

    public function oBlockLan(): bool
    {
        return Extractor::requireBool($this->d, 'oBlockLan', false);
    }

    public function oEnableLog(): bool
    {
        return Extractor::requireBool($this->d, 'oEnableLog', false);
    }

    public function oListenOn(int $nodeNumber): Ip
    {
        if (null === $oListenOnList = Extractor::optionalStringOrStringArray($this->d, 'oListenOn')) {
            return Ip::fromIp('::');
        }
        $nodeIndex = $this->nodeNumberToIndex($nodeNumber);
        if ($nodeIndex >= \count($oListenOnList)) {
            throw new ConfigException('"oListenOn" for node "' . $nodeNumber . '" not set');
        }

        return Ip::fromIp($oListenOnList[$nodeIndex]);
    }

    /**
     * @return array<string>
     */
    public function protoList(): array
    {
        $protoList = [];
        if ($this->oSupport()) {
            $protoList[] = 'openvpn';
        }
        if ($this->wSupport()) {
            $protoList[] = 'wireguard';
        }

        return $protoList;
    }

    public function oSupport(): bool
    {
        $oRangeFour = Extractor::optionalStringOrStringArray($this->d, 'oRangeFour');
        if (null === $oRangeFour || 0 === \count($oRangeFour)) {
            return false;
        }
        $oRangeSix = Extractor::optionalStringOrStringArray($this->d, 'oRangeSix');
        if (null === $oRangeSix || 0 === \count($oRangeSix)) {
            return false;
        }

        return true;
    }

    public function oUdpSupport(): bool
    {
        return $this->oSupport() && (0 !== \count($this->oExposedUdpPortList()) || 0 !== \count($this->oUdpPortList()));
    }

    public function oTcpSupport(): bool
    {
        return $this->oSupport() && (0 !== \count($this->oExposedTcpPortList()) || 0 !== \count($this->oTcpPortList()));
    }

    public function wSupport(): bool
    {
        $wRangeFour = Extractor::optionalStringOrStringArray($this->d, 'wRangeFour');
        if (null === $wRangeFour || 0 === \count($wRangeFour)) {
            return false;
        }
        $wRangeSix = Extractor::optionalStringOrStringArray($this->d, 'wRangeSix');
        if (null === $wRangeSix || 0 === \count($wRangeSix)) {
            return false;
        }

        return true;
    }

    public function wKeepAlive(): bool
    {
        return Extractor::requireBool($this->d, 'wKeepAlive', false);
    }

    public function hideProfile(): bool
    {
        return Extractor::requireBool($this->d, 'hideProfile', false);
    }

    /**
     * Configuration option to allow specifying the "nodeNumber"(s) the VPN
     * profile is deployed on.
     *
     * @return array<int>
     */
    public function onNode(): array
    {
        return Extractor::requireIntOrIntArray($this->d, 'onNode', range(0, $this->nodeCount() - 1));
    }

    /**
     * Determine the maximum number of VPN clients that can connect to this
     * profile taking into consideration OpenVPN/WireGuard and the node(s).
     */
    public function maxClientLimit(): int
    {
        return $this->oMaxClientLimit() + $this->wMaxClientLimit();
    }

    public function oMaxClientLimit(): int
    {
        $maxClientLimit = 0;
        // OpenVPN can have multiple processes, that reduces the number of IP
        // addresses available for VPN clients...
        $oProcessCount = $this->oSupport() ? (\count($this->oUdpPortList()) + \count($this->oTcpPortList())) : 0;
        foreach ($this->onNode() as $nodeNumber) {
            if ($this->oSupport()) {
                $maxClientLimit += ((int) 2 ** (32 - $this->oRangeFour($nodeNumber)->prefix())) - 3 * $oProcessCount;
            }
        }

        return $maxClientLimit;
    }

    public function wMaxClientLimit(): int
    {
        $maxClientLimit = 0;
        foreach ($this->onNode() as $nodeNumber) {
            if ($this->wSupport()) {
                $maxClientLimit += ((int) 2 ** (32 - $this->wRangeFour($nodeNumber)->prefix())) - 3;
            }
        }

        return $maxClientLimit;
    }

    public function profilePriority(): int
    {
        return Extractor::requireInt($this->d, 'profilePriority', 0);
    }

    /**
     * Get a list of configuration keys NOT supported in order to warn the
     * admins on the "Info" page about it.
     *
     * @return array<string>
     */
    public function unsupportedConfigKeys(): array
    {
        $supportedKeys = [
            'defaultGateway',
            'displayName',
            'dnsSearchDomainList',
            'dnsServerList',
            'excludeRouteList',
            'hostName',
            'nodeUrl',
            'oBlockLan',
            'oEnableLog',
            'oExposedTcpPortList',
            'oExposedUdpPortList',
            'onNode',
            'oRangeFour',
            'oRangeSix',
            'oTcpPortList',
            'oUdpPortList',
            'profileId',
            'routeList',
            'wRangeFour',
            'wRangeSix',
            'aclPermissionList',
            'oListenOn',
            'oRangeFour',
            'preferredProto',
            'wRangeFour',
            'hideProfile',
            'wKeepAlive',
            'profilePriority',
        ];

        $unsupportedKeys = [];
        foreach (array_keys($this->d) as $configKey) {
            if (!\is_string($configKey)) {
                // ignore non-string keys
                continue;
            }
            if (!\in_array($configKey, $supportedKeys, true)) {
                $unsupportedKeys[] = $configKey;
            }
        }

        return $unsupportedKeys;
    }

    private function nodeCount(): int
    {
        return \count(Extractor::requireStringOrStringArray($this->d, 'nodeUrl', ['http://127.0.0.1:41194']));
    }

    /**
     * Convert nodeNumber to nodeIndex. Each node has a unique "nodeNumber",
     * but as not all profiles necessarily are deployed on all nodes we need
     * to convert the nodeNumber to the index of the various profile
     * configuration options to find the "right" value for the node.
     *
     * For example: a VPN service has 4 nodes, and profile "employees" only
     * needs to be deployed on node 2 and 3. In the configuration the "onNode"
     * option MUST then be set to '[2, 3]'.
     * This allows us to keep configuration values like e.g. "wRangeSix"
     * 0-indexed and we don't need to set the key of the option to the node,
     * e.g.:
     *
     *     [2 => '10.10.10.10/24', 3 => '10.10.11.0/24']
     *
     * This is due to a bug that unfortuantely made it to the production
     * release and we can't break existing installations :-(
     *
     * @see https://todo.sr.ht/~eduvpn/server/90
     */
    private function nodeNumberToIndex(int $nodeNumber): int
    {
        $nodeIndex = array_search($nodeNumber, $this->onNode(), true);
        if (!\is_int($nodeIndex) || $nodeIndex < 0 || $nodeIndex > $this->nodeCount() - 1) {
            throw new ConfigException(\sprintf('configuration for nodeNumber "%d" for profile "%s" does not exist', $nodeNumber, $this->profileId()));
        }

        return $nodeIndex;
    }
}
