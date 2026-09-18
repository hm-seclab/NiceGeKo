<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

use Vpn\Portal\Cfg\ProfileConfig;
use Vpn\Portal\Cfg\WireGuardConfig;
use Vpn\Portal\Exception\ProtocolException;

final class Protocol
{
    /**
     * Determine which VPN protocol and transport to use based on the Server
     * and Client preferences.
     *
     * @return array<string>
     */
    public static function determine(WireGuardConfig $wgConfig, ProfileConfig $profileConfig, ProtoSupport $clientProtoSupport, ?string $publicKey, bool $preferTcp): array
    {
        if (null === $publicKey) {
            // in APIv3 it is not required to provide a WireGuard public key,
            // if it was NOT provided, there is no WireGuard support
            $clientProtoSupport->wUdp = false;
            $clientProtoSupport->wTcp = false;
        }

        $protoPriority = [];
        $serverPreference = self::serverPreference($wgConfig, $profileConfig);
        $clientProtoList = $clientProtoSupport->toArray();

        // we look at the prioritized list of protocols/transports of the
        // server and pick out the ones the client supports (while keeping the
        // order preferred by the server)
        foreach ($serverPreference as $serverProto) {
            if (\in_array($serverProto, $clientProtoList, true)) {
                $protoPriority[] = $serverProto;
            }
        }

        if (0 === \count($protoPriority)) {
            throw new ProtocolException(
                \sprintf(
                    'no common VPN protocol [CLIENT=%s SERVER=%s]',
                    0 !== \count($clientProtoList) ? implode(',', $clientProtoList) : 'None',
                    0 !== \count($serverPreference) ? implode(',', $serverPreference) : 'None'
                )
            );
        }

        // if the VPN client prefers TCP, we'll reorganize the list by moving
        // the entries with TCP transport to the start of the list of
        // protocols...
        if ($preferTcp) {
            usort($protoPriority, function (string $a, string $b) {
                if (self::isTcp($a) && self::isUdp($b)) {
                    return -1;
                }
                if (self::isUdp($a) && self::isTcp($b)) {
                    return 1;
                }

                return 0;
            });
        }

        return $protoPriority;
    }

    /**
     * We only take the Accept header serious if we detect at least one
     * mime-type we recognize, otherwise we assume it is garbage and consider
     * it as "not sent".
     */
    public static function parseMimeType(?string $httpAccept): ProtoSupport
    {
        if (null === $httpAccept) {
            return new ProtoSupport(oUdp: true, oTcp: true, wUdp: true, wTcp: false);
        }

        $oUdpSupport = false;
        $oTcpSupport = false;
        $wUdpSupport = false;
        $wTcpSupport = false;
        $takeSerious = false;

        $mimeTypeList = explode(',', $httpAccept);
        foreach ($mimeTypeList as $mimeType) {
            $mimeType = trim($mimeType);
            if ('application/x-openvpn-profile' === $mimeType) {
                $oUdpSupport = true;
                $oTcpSupport = true;
                $takeSerious = true;
            }
            if ('application/x-wireguard-profile' === $mimeType) {
                $wUdpSupport = true;
                $takeSerious = true;
            }
            if ('application/x-wireguard+tcp-profile' === $mimeType) {
                $wTcpSupport = true;
                $takeSerious = true;
            }

        }
        if (false === $takeSerious) {
            return new ProtoSupport(oUdp: true, oTcp: true, wUdp: true, wTcp: false);
        }

        return new ProtoSupport(oUdp: $oUdpSupport, oTcp: $oTcpSupport, wUdp: $wUdpSupport, wTcp: $wTcpSupport);
    }

    /**
     * @return array<string>
     */
    private static function oTransports(ProfileConfig $profileConfig): array
    {
        // oUdpSupport and oTcpSupport already ensure that OpenVPN support is
        // enabled for the profile...
        $oTransports = [];
        if ($profileConfig->oUdpSupport()) {
            $oTransports[] = 'openvpn+udp';
        }
        if ($profileConfig->oTcpSupport()) {
            $oTransports[] = 'openvpn+tcp';
        }

        return $oTransports;
    }

    /**
     * @return array<string>
     */
    private static function wTransports(WireGuardConfig $wgConfig, ProfileConfig $profileConfig): array
    {
        // the WireGuardConfig object does NOT know whether WireGuard is
        // enabled for a particular profile... so we have to make sure first
        if (!$profileConfig->wSupport()) {
            return [];
        }
        $wTransports = [];
        if (!$wgConfig->onlyProxy()) {
            $wTransports[] = 'wireguard+udp';
        }
        if ($wgConfig->enableProxy()) {
            $wTransports[] = 'wireguard+tcp';
        }

        return $wTransports;
    }

    /**
     * Returns the list of supported protocols by the server, in order of
     * preference, most preferred first.
     *
     * @return array<string>
     */
    private static function serverPreference(WireGuardConfig $wgConfig, ProfileConfig $profileConfig): array
    {
        if ('openvpn' === $profileConfig->preferredProto()) {
            return array_merge(
                self::oTransports($profileConfig),
                self::wTransports($wgConfig, $profileConfig)
            );
        }

        return array_merge(
            self::wTransports($wgConfig, $profileConfig),
            self::oTransports($profileConfig)
        );
    }

    /**
     * Helper function to determine whether the transport is TCP.
     */
    private static function isTcp(string $p): bool
    {
        return 'openvpn+tcp' === $p || 'wireguard+tcp' === $p;
    }

    /**
     * Helper function to determine whether the transport is UDP.
     */
    private static function isUdp(string $p): bool
    {
        return 'openvpn+udp' === $p || 'wireguard+udp' === $p;
    }
}
