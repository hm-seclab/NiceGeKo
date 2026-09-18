<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once \dirname(__DIR__) . '/vendor/autoload.php';

use Vpn\Portal\Ip;
use Vpn\Portal\IpNetList;

/*
 * Calculate WireGuard "AllowedIPs" field when dealing with overlapping IP
 * ranges. For example, if you want to route all traffic over the VPN, except
 * to a few prefixes, you can use this script to do that.
 *
 * Examples:
 *
 *     vpn-user-portal-prefix-calculator -i 0.0.0.0/0 -o 192.168.0.0/16
 *     vpn-user-portal-prefix-calculator -i ::/0 -o fc00::/7
 *
 * You can also combine IPv4 and IPv6 prefixes and specify "-o" and "-i"
 * multiple times. Use the "-a" flag to show the output in WireGuard
 * "AllowedIPs" syntax.
 */
try {
    $inList = [];
    $outList = [];
    $allowedIPs = false;

    for ($i = 1; $i < $argc; $i++) {
        if ('-a' === $argv[$i] || '--allowed-ips' === $argv[$i]) {
            $allowedIPs = true;

            continue;
        }
        if ('-i' === $argv[$i] || '--in' === $argv[$i]) {
            if ($argc > $i) {
                $inList[] = Ip::fromIpPrefix($argv[++$i]);
            }
        }
        if ('-o' === $argv[$i] || '--out' === $argv[$i]) {
            if ($argc > $i) {
                $outList[] = Ip::fromIpPrefix($argv[++$i]);
            }
        }
    }

    $ipNetList = new IpNetList();
    foreach ($inList as $inIp) {
        $ipNetList->add($inIp);
    }
    foreach ($outList as $outIp) {
        $ipNetList->remove($outIp);
    }

    if (!$allowedIPs) {
        echo (string) $ipNetList . \PHP_EOL;
        exit(0);
    }

    $ipNetTextList = [];
    foreach ($ipNetList->ls() as $ipNet) {
        $ipNetTextList[] = (string) $ipNet;
    }

    echo 'AllowedIPs = ' . implode(', ', $ipNetTextList) . \PHP_EOL;
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . \PHP_EOL;

    exit(1);
}
