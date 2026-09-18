<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once \dirname(__DIR__) . '/vendor/autoload.php';
$baseDir = \dirname(__DIR__);

use Vpn\Portal\Cfg\Config;
use Vpn\Portal\Crypto\Minisign\Verifier;
use Vpn\Portal\HttpClient\CurlClient;
use Vpn\Portal\ServerList;
use Vpn\Portal\SysLogger;

$logger = new SysLogger('vpn-user-portal');

try {
    $config = Config::fromFile($baseDir . '/config/config.php');
    $apiConfig = $config->apiConfig();
    if (!$apiConfig->enableGuestAccess()) {
        // "Guest Access" disabled, no need to fetch discovery file
        exit(0);
    }
    $serverList = new ServerList($baseDir . '/data', $apiConfig);
    $serverList->update(
        new CurlClient(),
        new Verifier($apiConfig->guestAccessPublicKeyList())
    );
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . \PHP_EOL;
    $logger->error(basename(__FILE__) . ': ' . $e->getMessage());

    exit(1);
}
