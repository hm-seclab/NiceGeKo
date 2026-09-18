<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

use DateInterval;
use DateTimeImmutable;
use RangeException;
use Vpn\Portal\Cfg\Config;
use Vpn\Portal\Cfg\ProfileConfig;
use Vpn\Portal\Cfg\WireGuardConfig;
use Vpn\Portal\OpenVpn\CA\CaInfo;

final class ConfigCheck
{
    /**
     * @return array{global_problems:array<string>,profile_problems:array<string,array<string>>}
     */
    public static function verify(Config $config, CaInfo $caInfo, ?DateTimeImmutable $dateTime = null): array
    {
        $usedRangeList = [];
        $usedUdpPortList = [];
        $usedTcpPortList = [];
        $nodeProfileMapping = [];
        $globalProblemList = [];
        $profileProblemList = [];

        // global issues
        self::verifyCaExpiry($caInfo, $dateTime ?? new DateTimeImmutable(), $globalProblemList);
        self::verifyMbStringFuncOverload($globalProblemList);
        self::verifyDuplicateProfileId($config, $globalProblemList);
        self::verifyNotSupportedConfigKeys($config, $globalProblemList);
        self::verifyNotSupportedWireGuardConfigKeys($config->wireGuardConfig(), $globalProblemList);

        // per profile issues
        foreach ($config->profileConfigList() as $profileConfig) {
            $problemList = [];
            self::verifyValidProfileId($profileConfig, $problemList);
            self::verifyNotSupportedProfileConfigKeys($profileConfig, $problemList);
            self::verifyDefaultGatewayHasDnsServerList($profileConfig, $problemList);
            self::verifyRangeOverlap($profileConfig, $usedRangeList, $problemList);
            self::verifyRoutesAndExcludeRoutesAreNormalized($profileConfig, $problemList);
            self::verifyDnsRouteIsPushedWhenNotDefaultGateway($profileConfig, $problemList);
            self::verifyDnsHasSearchDomainWhenNotDefaultGateway($profileConfig, $problemList);
            self::verifyNonLocalNodeUrlHasTls($profileConfig, $problemList);
            self::verifyUniqueOpenVpnPortsPerNode($profileConfig, $usedUdpPortList, $usedTcpPortList, $problemList);
            self::verifyRouteListIsEmptyWithDefaultGateway($profileConfig, $problemList);
            self::verifyNodeNumberUrlConsistency($profileConfig, $nodeProfileMapping, $problemList);
            self::verifyHasVpnProto($profileConfig, $problemList);
            $profileProblemList[$profileConfig->profileId()] = $problemList;
        }

        // make sure IP space is big enough for OpenVPN/WireGuard
        return [
            'global_problems' => $globalProblemList,
            'profile_problems' => $profileProblemList,
        ];
    }

    /**
     * @param array<string> $globalProblemList
     */
    private static function verifyCaExpiry(CaInfo $caInfo, DateTimeImmutable $dateTime, array &$globalProblemList): void
    {
        // if the CA expires within the next year, add a warning!
        if ($dateTime->add(new DateInterval('P1Y')) > $caInfo->validTo()) {
            $globalProblemList[] = \sprintf('the CA is about to expire (Valid To: %s) [See: https://docs.eduvpn.org/server/v3/ca.html]', $caInfo->validTo()->format(DateTimeImmutable::ATOM));
        }
    }

    /**
     * @param array<string> $globalProblemList
     */
    private static function verifyMbStringFuncOverload(array &$globalProblemList): void
    {
        // @see https://www.php.net/manual/en/mbstring.configuration.php#ini.mbstring.func-overload
        if (false !== (bool) \ini_get('mbstring.func_overload')) {
            $globalProblemList[] = '"mbstring.func_overload" MUST NOT be enabled';
        }
    }

    /**
     * @param array<string> $globalProblemList
     */
    private static function verifyDuplicateProfileId(Config $config, array &$globalProblemList): void
    {
        $profileIdList = [];
        foreach ($config->profileConfigList() as $profileConfig) {
            if (\in_array($profileConfig->profileId(), $profileIdList, true)) {
                $globalProblemList[] = \sprintf('duplicate Profile ID: %s', $profileConfig->profileId());
            }
            $profileIdList[] = $profileConfig->profileId();
        }
    }

    /**
     * @param array<string> $globalProblemList
     */
    private static function verifyNotSupportedConfigKeys(Config $config, array &$globalProblemList): void
    {
        $unsupportedConfigKeys = $config->unsupportedConfigKeys();
        foreach ($unsupportedConfigKeys as $unsupportedConfigKey) {
            $globalProblemList[] = \sprintf('configuration key "%s" not supported', $unsupportedConfigKey);
        }
    }

    /**
     * @param array<string> $globalProblemList
     */
    private static function verifyNotSupportedWireGuardConfigKeys(WireGuardConfig $wgConfig, array &$globalProblemList): void
    {
        $unsupportedConfigKeys = $wgConfig->unsupportedConfigKeys();
        foreach ($unsupportedConfigKeys as $unsupportedConfigKey) {
            $globalProblemList[] = \sprintf('[WireGuard] configuration key "%s" not supported', $unsupportedConfigKey);
        }
    }

    /**
     * @param array<string> $profileProblemList
     */
    private static function verifyValidProfileId(ProfileConfig $profileConfig, array &$profileProblemList): void
    {
        try {
            Validator::profileId($profileConfig->profileId());
        } catch (RangeException) {
            $profileProblemList[] = \sprintf('"profileId" field has invalid value "%s"', $profileConfig->profileId());
        }
    }

    /**
     * @param array<string> $profileProblemList
     */
    private static function verifyNotSupportedProfileConfigKeys(ProfileConfig $profileConfig, array &$profileProblemList): void
    {
        $unsupportedConfigKeys = $profileConfig->unsupportedConfigKeys();
        foreach ($unsupportedConfigKeys as $unsupportedConfigKey) {
            $profileProblemList[] = \sprintf('configuration key "%s" not supported', $unsupportedConfigKey);
        }
    }

    /**
     * @param array<string> $profileProblemList
     */
    private static function verifyRouteListIsEmptyWithDefaultGateway(ProfileConfig $profileConfig, array &$profileProblemList): void
    {
        if (!$profileConfig->defaultGateway()) {
            return;
        }

        if (0 !== \count($profileConfig->routeList())) {
            $profileProblemList[] = '"defaultGateway" is "true", expecting "routeList" to be empty';
        }
    }

    /**
     * @param array<string,array<string>> $usedUdpPortList
     * @param array<string,array<string>> $usedTcpPortList
     * @param array<string> $profileProblemList
     */
    private static function verifyUniqueOpenVpnPortsPerNode(ProfileConfig $profileConfig, array &$usedUdpPortList, array &$usedTcpPortList, array &$profileProblemList): void
    {
        if (!$profileConfig->oSupport()) {
            return;
        }

        // collect all ports per unique nodeUrl and report duplicate ones
        $udpPortList = $profileConfig->oUdpPortList();
        $tcpPortList = $profileConfig->oTcpPortList();
        foreach ($profileConfig->onNode() as $nodeNumber) {
            $nodeUrl = $profileConfig->nodeUrl($nodeNumber);
            if (!\array_key_exists($nodeUrl, $usedUdpPortList)) {
                $usedUdpPortList[$nodeUrl] = [];
            }
            if (!\array_key_exists($nodeUrl, $usedTcpPortList)) {
                $usedTcpPortList[$nodeUrl] = [];
            }

            foreach ($udpPortList as $udpPort) {
                $ipPort = \sprintf('%s!%d', $profileConfig->oListenOn($nodeNumber), $udpPort);
                if (\in_array($ipPort, $usedUdpPortList[$nodeUrl], true)) {
                    $profileProblemList[] = \sprintf('Node "%s" already uses IP!UDP port "%s"', $nodeUrl, $ipPort);

                    continue;
                }
                $usedUdpPortList[$nodeUrl][] = $ipPort;
            }
            foreach ($tcpPortList as $tcpPort) {
                $ipPort = \sprintf('%s!%d', $profileConfig->oListenOn($nodeNumber), $tcpPort);
                if (\in_array($ipPort, $usedTcpPortList[$nodeUrl], true)) {
                    $profileProblemList[] = \sprintf('Node "%s" already uses IP!TCP port "%s"', $nodeUrl, $ipPort);

                    continue;
                }
                $usedTcpPortList[$nodeUrl][] = $ipPort;
            }
        }
    }

    /**
     * @param array<string> $profileProblemList
     */
    private static function verifyNonLocalNodeUrlHasTls(ProfileConfig $profileConfig, array &$profileProblemList): void
    {
        foreach ($profileConfig->onNode() as $nodeNumber) {
            $nodeUrl = $profileConfig->nodeUrl($nodeNumber);
            $nodeUrlScheme = parse_url($nodeUrl, \PHP_URL_SCHEME);
            if ('https' === $nodeUrlScheme) {
                return;
            }
            $nodeUrlHost = parse_url($nodeUrl, \PHP_URL_HOST);
            if (\in_array($nodeUrlHost, ['localhost', '127.0.0.1', '::1'], true)) {
                return;
            }

            $profileProblemList[] = \sprintf('Node URL "%s" is not using https', $nodeUrl);
        }
    }

    /**
     * @param array<string> $profileProblemList
     */
    private static function verifyRoutesAndExcludeRoutesAreNormalized(ProfileConfig $profileConfig, array &$profileProblemList): void
    {
        foreach ($profileConfig->routeList() as $routeIpPrefix) {
            $ip = Ip::fromIpPrefix($routeIpPrefix);
            if (!$ip->equals($ip->network())) {
                $profileProblemList[] = \sprintf('"routeList" entry "%s" is not normalized, expecting "%s"', (string) $ip, (string) $ip->network());
            }
        }

        foreach ($profileConfig->excludeRouteList() as $routeIpPrefix) {
            $ip = Ip::fromIpPrefix($routeIpPrefix);
            if (!$ip->equals($ip->network())) {
                $profileProblemList[] = \sprintf('"excludeRouteList" entry "%s" is not normalized, expecting "%s"', (string) $ip, (string) $ip->network());
            }
        }
    }

    /**
     * @param array<string> $profileProblemList
     */
    private static function verifyDnsHasSearchDomainWhenNotDefaultGateway(ProfileConfig $profileConfig, array &$profileProblemList): void
    {
        if ($profileConfig->defaultGateway()) {
            return;
        }

        if (0 === \count($profileConfig->dnsServerList())) {
            return;
        }

        if (0 === \count($profileConfig->dnsSearchDomainList())) {
            $profileProblemList[] = 'for profiles without "defaultGateway" DNS will be ignored if no "dnsSearchDomainList" is set';
        }
    }

    /**
     * @param array<string> $profileProblemList
     */
    private static function verifyDnsRouteIsPushedWhenNotDefaultGateway(ProfileConfig $profileConfig, array &$profileProblemList): void
    {
        if ($profileConfig->defaultGateway()) {
            return;
        }

        if (0 === \count($profileConfig->dnsServerList())) {
            return;
        }

        // if no DNS search domain is set in the scenario where the profile is
        // not the default gateway, DNS provided through VPN won't be used
        // anyway
        if (0 === \count($profileConfig->dnsSearchDomainList())) {
            return;
        }

        foreach ($profileConfig->dnsServerList() as $dnsServer) {
            if (\in_array($dnsServer, ['@GW4@', '@GW6@'], true)) {
                // the '@GW4@' and '@GW6@' templates are *always* "routed" over
                // the VPN
                continue;
            }
            $dnsServerIp = Ip::fromIp($dnsServer);
            foreach ($profileConfig->routeList() as $routeIpPrefix) {
                $routeIp = Ip::fromIpPrefix($routeIpPrefix);
                if ($routeIp->contains($dnsServerIp)) {
                    continue 2;
                }
            }

            $profileProblemList[] = \sprintf('Traffic to DNS server "%s" will not be routed over VPN', $dnsServerIp->address());
        }
    }

    /**
     * @param array<string> $profileProblemList
     */
    private static function verifyDefaultGatewayHasDnsServerList(ProfileConfig $profileConfig, array &$profileProblemList): void
    {
        if ($profileConfig->defaultGateway() && 0 === \count($profileConfig->dnsServerList())) {
            $profileProblemList[] = '"defaultGateway" is "true", but "dnsServerList" is empty';
        }
    }

    /**
     * @param array<Ip>     $usedRangeList
     * @param array<string> $profileProblemList
     */
    private static function verifyRangeOverlap(ProfileConfig $profileConfig, array &$usedRangeList, array &$profileProblemList): void
    {
        // perhaps we can also log the profile to usedRangeList so it is easier
        // to find offending ranges, but string search in config file should
        // work as well...
        $profileRangeList = [];
        foreach ($profileConfig->onNode() as $nodeNumber) {
            if ($profileConfig->oSupport()) {
                $profileRangeList[] = $profileConfig->oRangeFour($nodeNumber);
                $profileRangeList[] = $profileConfig->oRangeSix($nodeNumber);
            }
            if ($profileConfig->wSupport()) {
                $profileRangeList[] = $profileConfig->wRangeFour($nodeNumber);
                $profileRangeList[] = $profileConfig->wRangeSix($nodeNumber);
            }
        }

        foreach ($profileRangeList as $profileRange) {
            foreach ($usedRangeList as $usedRange) {
                if ($profileRange->contains($usedRange) || $usedRange->contains($profileRange)) {
                    $profileProblemList[] = \sprintf('Prefix "%s" is equal to, or overlaps prefix "%s"', (string) $profileRange, (string) $usedRange);
                }
            }
            $usedRangeList[] = $profileRange;
        }
    }

    /**
     * @param array<string,array<int, string>> $profileNodeNumberUrlListMapping
     * @param array<string> $profileProblemList
     */
    private static function verifyNodeNumberUrlConsistency(ProfileConfig $profileConfig, array &$profileNodeNumberUrlListMapping, array &$profileProblemList): void
    {
        // make sure onNode does not contain the same nodeNumber > 1
        if (array_values($profileConfig->onNode()) !== array_values(array_unique($profileConfig->onNode()))) {
            $profileProblemList[] = \sprintf('onNode repeats nodeNumbers: [%s]', implode(',', $profileConfig->onNode()));

            return;
        }

        // verify nodeNumber(s) and nodeUrl(s)
        $nodeUrlList = [];
        foreach ($profileConfig->onNode() as $nodeNumber) {
            if ($nodeNumber < 0) {
                $profileProblemList[] = 'nodeNumber(s) MUST be >= 0';

                return;
            }
            $nodeUrl = $profileConfig->nodeUrl($nodeNumber);
            if (\in_array($nodeUrl, $nodeUrlList, true)) {
                $profileProblemList[] = \sprintf('duplidate nodeUrl "%s"', $nodeUrl);

                return;
            }
            $nodeUrlList[$nodeNumber] = $nodeUrl;
        }

        // make sure no other profile has our nodeUrl under a different nodeNumber
        foreach ($profileNodeNumberUrlListMapping as $profileId => $nodeNumberUrlList) {
            foreach ($nodeUrlList as $nodeNumber => $nodeUrl) {
                if (\array_key_exists($nodeNumber, $nodeNumberUrlList)) {
                    if ($nodeNumberUrlList[$nodeNumber] !== $nodeUrl) {
                        $profileProblemList[] = \sprintf('profile "%s" already defines nodeNumber "%d" with nodeUrl "%s"', $profileId, $nodeNumber, $nodeNumberUrlList[$nodeNumber]);
                    }
                }
            }
        }

        $profileNodeNumberUrlListMapping[$profileConfig->profileId()] = $nodeUrlList;
    }

    /**
     * @param array<string> $profileProblemList
     */
    private static function verifyHasVpnProto(ProfileConfig $profileConfig, array &$profileProblemList): void
    {
        if (!$profileConfig->oSupport() && !$profileConfig->wSupport()) {
            $profileProblemList[] = 'Profile does not support any VPN protocol';
        }
    }
}
