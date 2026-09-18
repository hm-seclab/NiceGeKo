<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once \dirname(__DIR__) . '/vendor/autoload.php';
$baseDir = \dirname(__DIR__);

use Vpn\Portal\Cfg\Config;
use Vpn\Portal\Storage;
use Vpn\Portal\SysLogger;

$logger = new SysLogger('vpn-user-portal');

try {
    $config = Config::fromFile($baseDir . '/config/config.php');
    $storage = new Storage($config->dbConfig($baseDir));

    // `today` is "start of day"
    $dateTime = new DateTimeImmutable('today');
    $connectionLogRetentionDateTime = $dateTime->sub($config->logConfig()->connectionLogRetentionInterval());

    $oneWeekAgo = new DateTimeImmutable('today -1 week');
    $startOfTheDay = new DateTimeImmutable('today');

    // aggregate old entries from the connection statistics
    $storage->statsAggregate($startOfTheDay);

    // remove old entries from the connection statistics
    $storage->cleanLiveStats($oneWeekAgo);

    // remove old entries from the connection log
    $storage->cleanConnectionLog($connectionLogRetentionDateTime);

    // delete expired WireGuard peers and OpenVPN certificates
    $storage->cleanExpiredConfigurations($startOfTheDay);

    // delete expires OAuth authorizations
    $storage->cleanExpiredOAuthAuthorizations($startOfTheDay);
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . \PHP_EOL;
    $logger->error(basename(__FILE__) . ': ' . $e->getMessage());

    exit(1);
}
