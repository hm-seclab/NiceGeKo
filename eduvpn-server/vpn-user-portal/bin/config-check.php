<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once \dirname(__DIR__) . '/vendor/autoload.php';
$baseDir = \dirname(__DIR__);

use Vpn\Portal\Cfg\Config;
use Vpn\Portal\ConfigCheck;
use Vpn\Portal\OpenVpn\CA\VpnCa;

try {
    $config = Config::fromFile($baseDir . '/config/config.php');
    $ca = new VpnCa($baseDir . '/config/keys/ca', $config->vpnCaPath());
    $configStatus = ConfigCheck::verify($config, $ca->caCert());
    $globalProblems = $configStatus['global_problems'];
    $profileProblems = $configStatus['profile_problems'];

    $problemMessageList = [];
    foreach ($globalProblems as $p) {
        $problemMessageList[] = \sprintf('[SERVER]: %s', $p);
    }
    foreach ($profileProblems as $profileId => $problemList) {
        foreach ($problemList as $p) {
            $problemMessageList[] = \sprintf('[PROFILE=%s]: %s', $profileId, $p);
        }
    }

    if (0 !== \count($problemMessageList)) {
        echo '********************************************************************' . \PHP_EOL;
        echo '* WARNING: Possible issues with your VPN configuration were found! *' . \PHP_EOL;
        echo '********************************************************************' . \PHP_EOL;
        foreach ($problemMessageList as $problemMessage) {
            echo $problemMessage . \PHP_EOL;
        }

        exit(1);
    }
} catch (Throwable $e) {
    echo \sprintf('ERROR: %s', $e->getMessage()) . \PHP_EOL;

    exit(1);
}
