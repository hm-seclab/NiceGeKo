<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

$showFour = false;
$showSix = false;
$prefixCount = 1;
$prefixLength = 24;

for ($i = 1; $i < $argc; $i++) {
    if ('-4' === $argv[$i]) {
        $showFour = true;
    }
    if ('-6' === $argv[$i]) {
        $showSix = true;
    }
    if ('-n' === $argv[$i]) {
        if ($i + 1 < $argc) {
            $pC = (int) $argv[++$i];
            if ($pC >= 0) {
                $prefixCount = $pC;
            }
        }

        continue;
    }
    if ('-p' === $argv[$i]) {
        if ($i + 1 < $argc) {
            $pL = (int) $argv[++$i];
            if ($pL >= 8 && $pL <= 32) {
                $prefixLength = $pL;
            }
        }

        continue;
    }

    if ('-h' === $argv[$i] || '--help' === $argv[$i]) {
        echo 'SYNTAX: ' . $argv[0] . ' [-4] [-6] [-n COUNT] [-p PREFIX_LENGTH]' . \PHP_EOL;
        exit(0);
    }
}

// if nothing is explicity requested, show both IPv4 and IPv6 prefix
if (!$showFour && !$showSix) {
    $showFour = $showSix = true;
}

for ($i = 0; $i < $prefixCount; $i++) {
    if ($showFour) {
        $hexIp = "\xa" . random_bytes(1) . random_bytes(1) . random_bytes(1);
        if (false === $stringIp = inet_ntop($hexIp)) {
            echo 'ERROR: inet_ntop';
            exit(1);
        }
        if (false === $intIp = ip2long($stringIp)) {
            echo 'ERROR: ip2long';
            exit(1);
        }
        $intIp = $intIp >> (32 - $prefixLength) << (32 - $prefixLength);
        echo long2ip($intIp) . '/' . $prefixLength . \PHP_EOL;
    }
    if ($showSix) {
        echo chunk_split('fd' . bin2hex(random_bytes(7)), 4, ':') . ':/64' . \PHP_EOL;
    }
}
