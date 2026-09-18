<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal;

final class Dns
{
    /**
     * Obtain an IpNetList containing all IPv4 addresses a hostname resolves
     * to or an empty list if it does not resolve.
     */
    public static function ipFour(string $dnsName): IpNetList
    {
        $ipNetList = new IpNetList();
        foreach (self::dnsRecord($dnsName, \DNS_A, 'ip') as $ipFour) {
            $ipNetList->add(Ip::fromIp($ipFour));
        }

        return $ipNetList;
    }

    /**
     * @return array<string>
     */
    private static function dnsRecord(string $dnsName, int $recordType, string $resultField): array
    {
        // add "." at the end if not there to avoid PHP adding suffixes from
        // /etc/resolv.conf willy nilly
        if (!str_ends_with($dnsName, '.')) {
            $dnsName .= '.';
        }
        if (false === $recordItemList = @dns_get_record($dnsName, $recordType)) {
            return [];
        }

        $resultSet = [];
        foreach ($recordItemList as $recordItem) {
            if (!\array_key_exists($resultField, $recordItem) || !\is_string($recordItem[$resultField])) {
                continue;
            }
            $resultSet[] = $recordItem[$resultField];
        }

        return $resultSet;
    }
}
