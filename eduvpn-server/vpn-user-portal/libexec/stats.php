<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once \dirname(__DIR__) . '/vendor/autoload.php';
$baseDir = \dirname(__DIR__);

use Vpn\Portal\Cfg\Config;
use Vpn\Portal\ConnectionHooks;
use Vpn\Portal\ConnectionManager;
use Vpn\Portal\HttpClient\ClientConfig;
use Vpn\Portal\HttpClient\CurlClient;
use Vpn\Portal\Storage;
use Vpn\Portal\SysLogger;
use Vpn\Portal\VpnDaemon;

$logger = new SysLogger('vpn-user-portal');

try {
    $config = Config::fromFile($baseDir . '/config/config.php');
    $storage = new Storage($config->dbConfig($baseDir));
    $vpnDaemon = new VpnDaemon(
        new CurlClient(
            clientConfig: new ClientConfig(
                caFile: $baseDir . '/config/keys/vpn-daemon/ca.crt',
                certFile: $baseDir . '/config/keys/vpn-daemon/vpn-daemon-client.crt',
                keyFile: $baseDir . '/config/keys/vpn-daemon/vpn-daemon-client.key'
            )
        ),
        $logger
    );
    $connectionManager = new ConnectionManager(
        $config,
        $vpnDaemon,
        $storage,
        ConnectionHooks::init($config, $storage, $logger),
        $logger
    );

    $dateTime = new DateTimeImmutable();
    foreach ($connectionManager->get() as $profileId => $connectionInfoList) {
        $storage->statsAdd($dateTime, $profileId, \count($connectionInfoList));
    }
} catch (Throwable $e) {
    echo \sprintf('ERROR: %s', $e->getMessage()) . \PHP_EOL;
    $logger->error(basename(__FILE__) . ': ' . $e->getMessage());

    exit(1);
}
